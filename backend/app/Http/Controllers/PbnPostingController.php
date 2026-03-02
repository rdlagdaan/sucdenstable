<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PbnPostingController extends Controller
{
    
// ===== START ADD: safe table + column resolvers (prevents 500) =====
private function tableExists(string $table): bool
{
    try {
        return DB::table('information_schema.tables')
            ->whereRaw("table_schema = 'public'")
            ->whereRaw('table_name = ?', [$table])
            ->exists();
    } catch (\Throwable $e) {
        return false;
    }
}


private function colExists(string $table, string $column): bool
{
    try {
        return DB::table('information_schema.columns')
            ->whereRaw("table_schema = 'public'")
            ->whereRaw('table_name = ?', [$table])
            ->whereRaw('column_name = ?', [$column])
            ->exists();
    } catch (\Throwable $e) {
        return false;
    }
}


private function pbnDetailsTable(): string
{
    // most common alternates in Sucden builds
    $candidates = ['pbn_entry_details', 'sucden_pbn_details'];
    foreach ($candidates as $t) {
        if ($this->tableExists($t)) return $t;
    }
    return 'pbn_entry_details'; // fallback
}

/**
 * Determine how details link to main:
 *  - preferred: pbn_entry_id = main.id
 *  - fallback: pbn_number / PBNNo / pbn_no = main.pbn_number
 */
private function pbnDetailsLink(string $detailsTable): array
{
    // link-by-id
    if ($this->colExists($detailsTable, 'pbn_entry_id')) {
        return ['type' => 'id', 'col' => 'pbn_entry_id'];
    }

    // link-by-number
    foreach (['pbn_number', 'pbn_no', 'PBNNo', 'pbnNo'] as $col) {
        if ($this->colExists($detailsTable, $col)) {
            return ['type' => 'number', 'col' => $col];
        }
    }

    // unknown schema: return "none" so caller can safely return empty details (no 500)
    return ['type' => 'none', 'col' => null];
}
// ===== END ADD: safe table + column resolvers (prevents 500) =====
    
    
    /**
     * IMPORTANT:
     * Receiving-side usage table may differ in your project.
     * Set this to your actual receiving details table once confirmed.
     *
     * Expected columns (for usage computation):
     * - pbn_entry_id (int)
     * - pbn_detail_id (int)  OR row reference to pbn_entry_details.id
     * - quantity (numeric)
     * - company_id (int)
     * - delete_flag (int) optional
     */
// ===== START REPLACE: receiving table resolver =====
private function receivingTable(): string
{
    return (string) config('pbn.receiving_details_table', 'receiving_details');
}
// ===== END REPLACE: receiving table resolver =====

    /**
     * GET /api/pbn/posting/list
     * Query:
     *  - company_id (required)
     *  - status: unposted|posted|closed|all (optional; default unposted)
     *  - q (optional)
     */
public function list(Request $req)
{
    $data = $req->validate([
        'company_id' => ['required','integer'],
        'status'     => ['nullable','string'],
        'q'          => ['nullable','string'],
    ]);

    $companyId = (int) $data['company_id'];
    $status    = strtolower((string) ($data['status'] ?? 'unposted'));
    $q         = trim((string) ($data['q'] ?? ''));

    /**
     * ✅ FIX for your 500:
     * Your flags can be SMALLINT (or VARCHAR in some builds). Using NULLIF(flag,'') breaks
     * when flag is SMALLINT because '' cannot be cast to SMALLINT.
     *
     * Solution: always cast flag to TEXT first, then NULLIF/trim, then cast to int.
     */
    $deleteZero = "COALESCE(NULLIF(trim(p.delete_flag::text), '' )::int, 0) = 0";
    $postedZero = "COALESCE(NULLIF(trim(p.posted_flag::text), '' )::int, 0) = 0";
    $postedOne  = "COALESCE(NULLIF(trim(p.posted_flag::text), '' )::int, 0) = 1";
    $closeZero  = "COALESCE(NULLIF(trim(p.close_flag::text),  '' )::int, 0) = 0";
    $closeOne   = "COALESCE(NULLIF(trim(p.close_flag::text),  '' )::int, 0) = 1";

    $query = DB::table('pbn_entry as p')
        ->where('p.company_id', $companyId)
        ->whereRaw($deleteZero);

    // Status filter
    if ($status === 'unposted') {
        $query->whereRaw($postedZero)->whereRaw($closeZero);
    } elseif ($status === 'posted') {
        $query->whereRaw($postedOne)->whereRaw($closeZero);
    } elseif ($status === 'closed') {
        $query->whereRaw($closeOne);
    } // 'all' => no extra filter

    // Search filter
    if ($q !== '') {
        $qq = strtolower($q);
        $query->where(function ($w) use ($qq) {
            $w->whereRaw("LOWER(COALESCE(p.pbn_number, '')) LIKE ?", ["%{$qq}%"])
              ->orWhereRaw("LOWER(COALESCE(p.vendor_name, '')) LIKE ?", ["%{$qq}%"])
              ->orWhereRaw("LOWER(COALESCE(p.vend_code,   '')) LIKE ?", ["%{$qq}%"]);
        });
    }

    $rows = $query
        ->orderByDesc('p.id')
        ->limit(200)
        ->get([
            'p.id',
            'p.pbn_number',
            'p.pbn_date',
            'p.sugar_type',
            'p.crop_year',
            'p.vend_code',
            'p.vendor_name',
            'p.posted_flag',
            'p.close_flag',
        ]);

    return response()->json($rows);
}





    /**
     * GET /api/pbn/posting/{id}
     * Query:
     *  - company_id (required)
     *
     * Returns:
     *  - main (header)
     *  - details[] with: used_qty + remaining_qty + usage_status
     */
    public function show(Request $req, int $id)
    {
        $data = $req->validate([
            'company_id' => ['required','integer'],
        ]);
        $companyId = (int) $data['company_id'];

        $main = DB::table('pbn_entry as p')
            ->where('p.id', $id)
            ->where('p.company_id', $companyId)
            ->first();

        if (!$main) {
            return response()->json(['message' => 'PO not found'], 404);
        }

// ===== START REPLACE: details query (schema-safe) =====
$detailsTable = $this->pbnDetailsTable();
$link = $this->pbnDetailsLink($detailsTable);

try {
    $dq = DB::table($detailsTable . ' as d');

    // deleted flag filter only if the column exists
    if ($this->colExists($detailsTable, 'delete_flag')) {
        $dq->where(function ($w) {
            $w->whereNull('d.delete_flag')->orWhere('d.delete_flag', 0);
        });
    }

    // apply link
    if ($link['type'] === 'id') {
        $dq->where('d.' . $link['col'], $id);
    } elseif ($link['type'] === 'number') {
        $dq->where('d.' . $link['col'], (string)($main->pbn_number ?? ''));
    } else {
        // unknown schema -> return empty (but no 500)
// unknown schema -> return empty (but no 500)
$details = collect([]);
// goto DETAILS_DONE;

    }

    // order: prefer row, else itemNo if present
    if ($this->colExists($detailsTable, 'row')) {
        $dq->orderBy('d.row');
    } elseif ($this->colExists($detailsTable, 'itemNo')) {
        $dq->orderBy('d.itemNo');
    }

    // select safely (use what exists)
    $select = [];

    // required id-like field
    if ($this->colExists($detailsTable, 'id')) {
        $select[] = 'd.id';
    } else {
        // fallback key
        $select[] = DB::raw("ROW_NUMBER() OVER() as id");
    }

    // row number
    if ($this->colExists($detailsTable, 'row')) {
        $select[] = 'd.row';
    } elseif ($this->colExists($detailsTable, 'itemNo')) {
        $select[] = 'd.itemNo as row';
    } else {
        $select[] = DB::raw('0 as row');
    }

    // mill
    if ($this->colExists($detailsTable, 'mill_code')) $select[] = 'd.mill_code';
    elseif ($this->colExists($detailsTable, 'millCode')) $select[] = 'd.millCode as mill_code';
    elseif ($this->colExists($detailsTable, 'millID')) $select[] = 'd.millID as mill_code';
    else $select[] = DB::raw("'' as mill_code");

    if ($this->colExists($detailsTable, 'mill')) $select[] = 'd.mill';
    else $select[] = DB::raw("'' as mill");

    // quantity
    if ($this->colExists($detailsTable, 'quantity')) $select[] = 'd.quantity';
    elseif ($this->colExists($detailsTable, 'qty')) $select[] = 'd.qty as quantity';
    else $select[] = DB::raw('0 as quantity');

    // unit_cost
    if ($this->colExists($detailsTable, 'unit_cost')) $select[] = 'd.unit_cost';
    elseif ($this->colExists($detailsTable, 'price')) $select[] = 'd.price as unit_cost';
    else $select[] = DB::raw('0 as unit_cost');

    // commission
    if ($this->colExists($detailsTable, 'commission')) $select[] = 'd.commission';
    else $select[] = DB::raw('0 as commission');

    // selected_flag
    if ($this->colExists($detailsTable, 'selected_flag')) $select[] = 'd.selected_flag';
    else $select[] = DB::raw('0 as selected_flag');

    $details = $dq->get($select);
} catch (\Throwable $e) {
    Log::warning('PO posting: details query failed', [
        'details_table' => $detailsTable,
        'err' => $e->getMessage(),
    ]);
    $details = collect([]);
}


// ===== END REPLACE: details query (schema-safe) =====


        // Compute used quantities from Receiving module (best-effort).
        // If your receiving table/columns differ, update RECEIVING_DETAILS_TABLE and the query inside getUsedMap().
        $usedMap = $this->getUsedMapSafe($companyId, (int)$main->id);

        $rows = $details->map(function ($d) use ($usedMap) {
            $detailId  = (int) $d->id;
            $qty       = (float) ($d->quantity ?? 0);
            $used      = (float) ($usedMap[$detailId] ?? 0);
            $remaining = max(0, $qty - $used);

            $usageStatus = 'unused';
            if ($used > 0 && $remaining > 0) $usageStatus = 'partial';
            if ($remaining <= 0 && $qty > 0) $usageStatus = 'fully_used';

            return [
                'id'            => $detailId,
                'row'           => (int) ($d->row ?? 0),
                'mill_code'     => (string) ($d->mill_code ?? ''),
                'mill'          => (string) ($d->mill ?? ''),
                'quantity'      => $qty,
                'unit_cost'     => (float) ($d->unit_cost ?? 0),
                'commission'    => (float) ($d->commission ?? 0),
                'used_qty'      => $used,
                'remaining_qty' => $remaining,
                'usage_status'  => $usageStatus,
                'selected_flag' => (int) ($d->selected_flag ?? 0),
            ];
        });

        return response()->json([
            'main'    => $main,
            'details' => $rows,
        ]);
    }

    /**
     * Best-effort: if Receiving table doesn't exist yet / different schema,
     * this will return an empty map (used=0) but won't crash.
     */
private function getUsedMapSafe(int $companyId, int $pbnEntryId): array
{
    try {
        $table = $this->receivingTable();

        // if receiving table doesn't exist yet, just return empty
        if (!$this->tableExists($table)) return [];

        $q = DB::table($table)
            ->where('company_id', $companyId);

        // link by pbn_entry_id if present
        if ($this->colExists($table, 'pbn_entry_id')) {
            $q->where('pbn_entry_id', $pbnEntryId);
        } else {
            // schema not ready for usage tracking
            return [];
        }

        if ($this->colExists($table, 'delete_flag')) {
            $q->where(function ($w) {
                $w->whereNull('delete_flag')->orWhere('delete_flag', 0);
            });
        }

        // we need pbn_detail_id + quantity
        if (!$this->colExists($table, 'pbn_detail_id') || !$this->colExists($table, 'quantity')) {
            return [];
        }

        $rows = $q->groupBy('pbn_detail_id')
            ->get([
                'pbn_detail_id',
                DB::raw('SUM(quantity) as used_qty'),
            ]);

        $map = [];
        foreach ($rows as $r) {
            $k = (int) ($r->pbn_detail_id ?? 0);
            if ($k > 0) $map[$k] = (float) ($r->used_qty ?? 0);
        }
        return $map;

    } catch (\Throwable $e) {
        Log::warning('PO posting: receiving usage map not available yet', [
            'table' => $this->receivingTable(),
            'err'   => $e->getMessage(),
        ]);
        return [];
    }
}


    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            // PostgreSQL information_schema check
            $exists = DB::table('information_schema.columns')
                ->whereRaw('table_name = ?', [$table])
                ->whereRaw('column_name = ?', [$column])
                ->exists();
            return (bool)$exists;
        } catch (\Throwable $e) {
            return false;
        }
    }


// ===== START ADD: Approval-aligned PBN posting actions =====

/**
 * POST /api/pbn/posting/{id}/request-post
 * Creates/reuses an approval request with:
 *  - module = pbn_posting
 *  - action = post
 */
public function requestPost(Request $req, int $id)
{
    $data = $req->validate([
        'company_id' => ['required','integer'],
        'reason'     => ['nullable','string'],
    ]);

    $companyId = (int) $data['company_id'];
    $reason    = (string) ($data['reason'] ?? '');

    $pbn = DB::table('pbn_entry')
        ->where('id', $id)
        ->where('company_id', $companyId)
        ->first();

    if (!$pbn) return response()->json(['message' => 'PO not found'], 404);

    if ((int)($pbn->delete_flag ?? 0) === 1) {
        return response()->json(['message' => 'PO is deleted/hidden.'], 409);
    }
    if ((int)($pbn->close_flag ?? 0) === 1) {
        return response()->json(['message' => 'PO is already closed.'], 409);
    }
    if ((int)($pbn->posted_flag ?? 0) === 1) {
        return response()->json(['message' => 'PO is already posted.'], 409);
    }

    return $this->createOrReuseApproval($req, $companyId, 'pbn_posting', $id, 'post', $reason);
}

/**
 * POST /api/pbn/posting/{id}/request-unpost-unused
 * Approval action: unpost_unused
 */
public function requestUnpostUnused(Request $req, int $id)
{
    $data = $req->validate([
        'company_id' => ['required','integer'],
        'reason'     => ['nullable','string'],
    ]);

    $companyId = (int) $data['company_id'];
    $reason    = (string) ($data['reason'] ?? '');

    $pbn = DB::table('pbn_entry')
        ->where('id', $id)
        ->where('company_id', $companyId)
        ->first();

    if (!$pbn) return response()->json(['message' => 'PO not found'], 404);

    if ((int)($pbn->delete_flag ?? 0) === 1) {
        return response()->json(['message' => 'PO is deleted/hidden.'], 409);
    }
    if ((int)($pbn->close_flag ?? 0) === 1) {
        return response()->json(['message' => 'PO is closed. Unpost is not allowed.'], 409);
    }
    if ((int)($pbn->posted_flag ?? 0) !== 1) {
        return response()->json(['message' => 'PO must be posted first.'], 409);
    }

    return $this->createOrReuseApproval($req, $companyId, 'pbn_posting', $id, 'unpost_unused', $reason);
}

/**
 * POST /api/pbn/posting/{id}/request-close
 * Approval action: close
 */
public function requestClose(Request $req, int $id)
{
    $data = $req->validate([
        'company_id' => ['required','integer'],
        'reason'     => ['nullable','string'],
    ]);

    $companyId = (int) $data['company_id'];
    $reason    = (string) ($data['reason'] ?? '');

    $pbn = DB::table('pbn_entry')
        ->where('id', $id)
        ->where('company_id', $companyId)
        ->first();

    if (!$pbn) return response()->json(['message' => 'PO not found'], 404);

    if ((int)($pbn->delete_flag ?? 0) === 1) {
        return response()->json(['message' => 'PO is deleted/hidden.'], 409);
    }
    if ((int)($pbn->close_flag ?? 0) === 1) {
        return response()->json(['message' => 'PO is already closed.'], 409);
    }
    if ((int)($pbn->posted_flag ?? 0) !== 1) {
        return response()->json(['message' => 'PO must be posted before it can be closed.'], 409);
    }

    return $this->createOrReuseApproval($req, $companyId, 'pbn_posting', $id, 'close', $reason);
}

/**
 * Create or reuse an approval request (same rules as your ApprovalController.requestEdit):
 * - auto-expire unused elapsed approvals
 * - reuse active pending/approved unconsumed approvals
 * - otherwise insert a new pending request with incremented request_ctr
 */
private function createOrReuseApproval(Request $req, int $companyId, string $module, int $recordId, string $action, string $reason)
{
    $action = strtolower(trim($action));
    $module = trim($module);

    $now = now();

    // Resolve requester id the same “safe” way as ApprovalController (simple fallback)
    $requesterId = optional($req->user())->id;
    if (!$requesterId) {
        try { $requesterId = auth('sanctum')->id(); } catch (\Throwable $e) {}
    }

    // 0) expire unused approvals whose window elapsed
    DB::table('approvals')
        ->where('module', $module)
        ->where('record_id', $recordId)
        ->where('company_id', $companyId)
        ->whereRaw('LOWER(action) = ?', [$action])
        ->whereIn('status', ['pending','approved'])
        ->whereNull('consumed_at')
        ->whereNull('first_edit_at')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', $now)
        ->update([
            'status'      => 'expired',
            'consumed_at' => $now,
            'updated_at'  => $now,
        ]);

    // 1) reuse active approval
    $existing = DB::table('approvals')
        ->where('module', $module)
        ->where('record_id', $recordId)
        ->where('company_id', $companyId)
        ->whereRaw('LOWER(action) = ?', [$action])
        ->whereIn('status', ['pending','approved'])
        ->whereNull('consumed_at')
        ->where(function ($q) use ($now) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
        })
        ->orderByDesc('id')
        ->first();

    if ($existing) {
        if ($existing->status === 'pending') {
            DB::table('approvals')->where('id', $existing->id)->update([
                'reason'     => $reason,
                'updated_at' => $now,
            ]);
        }

        return response()->json([
            'ok'          => true,
            'id'          => $existing->id,
            'status'      => $existing->status,
            'reused'      => true,
            'request_ctr' => $existing->request_ctr ?? null,
        ]);
    }

    // 2) new request_ctr
    $latestCtr = DB::table('approvals')
        ->where('module', $module)
        ->where('record_id', $recordId)
        ->where('company_id', $companyId)
        ->whereRaw('LOWER(action) = ?', [$action])
        ->max('request_ctr');

    $nextCtr = $latestCtr ? ((int)$latestCtr + 1) : 1;

    // 3) insert new approval
    $id = DB::table('approvals')->insertGetId([
        'company_id'   => $companyId,
        'module'       => $module,
        'record_id'    => $recordId,
        'action'       => $action,
        'reason'       => $reason,
        'status'       => 'pending',
        'requester_id' => $requesterId,
        'request_ctr'  => $nextCtr,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    return response()->json([
        'ok'          => true,
        'id'          => $id,
        'status'      => 'pending',
        'reused'      => false,
        'request_ctr' => $nextCtr,
    ]);
}

// ===== END ADD: Approval-aligned PBN posting actions =====







}
