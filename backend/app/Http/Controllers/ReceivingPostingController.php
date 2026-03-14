<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ReceivingPurchaseJournalService;

class ReceivingPostingController extends Controller
{
    /**
     * List Receiving Entries for Posting module (search + status flags).
     * Default: excludes deleted.
     * Optional filters:
     *   q=... (receipt_no/pbn/vendor)
     *   include_deleted=1
     *   include_processed=1
     */
    public function list(Request $req)
    {
        $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
        if ($companyId <= 0) {
            return response()->json(['message' => 'Missing company_id'], 422);
        }

        $q = trim((string) $req->query('q', ''));
        $includeDeleted   = $req->boolean('include_deleted', false);
        $includeProcessed = $req->boolean('include_processed', false);

        $rows = DB::table('receiving_entry as r')
            ->leftJoin('pbn_entry as p', function ($j) use ($companyId) {
                $j->on('p.pbn_number', '=', 'r.pbn_number')
                  ->where('p.company_id', '=', $companyId);
            })
            ->leftJoin('receiving_details as d', 'd.receipt_no', '=', 'r.receipt_no')

            
->select(
    'r.id',
    'r.receipt_no',
    'r.pbn_number',
    'r.receipt_date',
    'r.mill',
    'r.posted_flag',
    'r.processed_flag',
    'r.deleted_flag',
    'p.vendor_name',
    'p.vend_code as vendor_code',
    'p.sugar_type',
    'p.crop_year',
    DB::raw('COALESCE(SUM(d.quantity),0) as quantity')
)

            ->where('r.company_id', $companyId)
            ->when($q !== '', function ($qq) use ($q) {
                $like = "%{$q}%";
                $qq->where(function ($w) use ($like) {
                    $w->where('r.receipt_no', 'ilike', $like)
                      ->orWhere('r.pbn_number', 'ilike', $like)
                      ->orWhere('p.vendor_name', 'ilike', $like)
                      ->orWhere('p.vend_code', 'ilike', $like);
                });
            })
->when(!$includeDeleted, fn ($w) => $w->where(function ($x) {
    $x->whereNull('r.deleted_flag')->orWhere('r.deleted_flag', false);
}))
->when(!$includeProcessed, fn ($w) => $w->where(function ($x) {
    $x->whereNull('r.processed_flag')->orWhere('r.processed_flag', false);
}))

            ->groupBy(
                'r.id','r.receipt_no','r.pbn_number','r.receipt_date','r.mill',
                'r.posted_flag','r.processed_flag','r.deleted_flag',
                'p.vendor_name','p.vend_code','p.sugar_type','p.crop_year'
            )
            ->orderBy('r.receipt_no', 'asc')
            ->limit(300)
            ->get();

        return response()->json($rows);
    }

    /**
     * Show a single Receiving Entry header (plus PBN context).
     * Use this for the Posting module "review" panel.
     */
    public function show(Request $req, int $id)
    {
        $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
        if ($companyId <= 0) {
            return response()->json(['message' => 'Missing company_id'], 422);
        }

        $row = DB::table('receiving_entry as r')
            ->leftJoin('pbn_entry as p', function ($j) use ($companyId) {
                $j->on('p.pbn_number', '=', 'r.pbn_number')
                  ->where('p.company_id', '=', $companyId);
            })
            ->select(
                'r.*',
                'p.vendor_name',
                'p.vend_code as vendor_code',
                'p.sugar_type',
                'p.crop_year'
            )
            ->where('r.company_id', $companyId)
            ->where('r.id', $id)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Receiving entry not found'], 404);
        }

        return response()->json($row);
    }

public function previewJournal(Request $req, int $id, ReceivingPurchaseJournalService $svc)
{
    $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) return response()->json(['message' => 'Missing company_id'], 422);

    try {
        $data = $svc->buildJournalPreview($companyId, $id);

        $re = DB::table('receiving_entry')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first(['id', 'pbn_number', 'item_number', 'mill']);

        $seed = '';

        if ($re) {
            $detail = DB::table('pbn_entry_details')
                ->where('pbn_number', $re->pbn_number)
                ->where('row', $re->item_number)
                ->first(['mill_code', 'mill', 'quantity', 'price']);

            $millId = trim((string) ($detail->mill_code ?? ''));
            if ($millId === '') {
                $millId = trim((string) ($detail->mill ?? ''));
            }
            if ($millId === '') {
                $millId = trim((string) ($re->mill ?? ''));
            }

            $qty = number_format((float) ($detail->quantity ?? 0), 2);
            $price = number_format((float) ($detail->price ?? 0), 2);

            $seed = $millId !== ''
                ? "{$millId} - {$qty} LKG@{$price}"
                : "{$qty} LKG@{$price}";
        }

        if (is_array($data)) {
            $data['explanation_seed'] = $seed;
        }

        return response()->json($data);
    } catch (\Throwable $e) {
        \Log::warning('receiving preview journal failed', ['id' => $id, 'err' => $e->getMessage()]);
        return response()->json(['message' => $e->getMessage()], 422);
    }
}

public function post(Request $req, int $id)
{
    $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    $userId = (int) ($req->input('user_id') ?: optional($req->user())->id ?: 0);

    $hdrQ = DB::table('receiving_entry')
        ->where('company_id', $companyId)
        ->where('id', $id);

    $re = (clone $hdrQ)->first();

    if (!$re) {
        return response()->json(['message' => 'Receiving entry not found'], 404);
    }

    if ((bool) ($re->deleted_flag ?? false)) {
        return response()->json(['message' => 'Cannot POST a deleted Receiving Entry.'], 422);
    }

    if ((bool) ($re->processed_flag ?? false)) {
        return response()->json(['message' => 'Cannot POST a processed Receiving Entry.'], 422);
    }

    if ((bool) ($re->posted_flag ?? false)) {
        return response()->json(['message' => 'Receiving Entry is already posted.'], 422);
    }

    $now = now();

    $hdrQ->update([
        'posted_flag' => true,
        'posted_by'   => $userId > 0 ? $userId : null,
        'posted_at'   => $now,
        'updated_at'  => $now,
    ]);

    return response()->json(['ok' => true]);
}

public function unpost(Request $req, int $id)
{
    $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    $userId = (int) ($req->input('user_id') ?: optional($req->user())->id ?: 0);

    $hdrQ = DB::table('receiving_entry')
        ->where('company_id', $companyId)
        ->where('id', $id);

    $re = (clone $hdrQ)->first();

    if (!$re) {
        return response()->json(['message' => 'Receiving entry not found'], 404);
    }

    if ((bool) ($re->deleted_flag ?? false)) {
        return response()->json(['message' => 'Cannot UNPOST a deleted Receiving Entry.'], 422);
    }

    if ((bool) ($re->processed_flag ?? false)) {
        return response()->json(['message' => 'Cannot UNPOST a processed Receiving Entry.'], 422);
    }

    if (!(bool) ($re->posted_flag ?? false)) {
        return response()->json(['message' => 'Receiving Entry is not posted.'], 422);
    }

    $now = now();

    $hdrQ->update([
        'posted_flag' => false,
        'unposted_by' => $userId > 0 ? $userId : null,
        'unposted_at' => $now,
        'updated_at'  => $now,
    ]);

    return response()->json(['ok' => true]);
}

public function softDelete(Request $req, int $id)
{
    $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    $userId = (int) ($req->input('user_id') ?: optional($req->user())->id ?: 0);

    $hdrQ = DB::table('receiving_entry')
        ->where('company_id', $companyId)
        ->where('id', $id);

    $re = (clone $hdrQ)->first();

    if (!$re) {
        return response()->json(['message' => 'Receiving entry not found'], 404);
    }

    if ((bool) ($re->deleted_flag ?? false)) {
        return response()->json(['message' => 'Receiving Entry is already deleted.'], 422);
    }

    if ((bool) ($re->processed_flag ?? false)) {
        return response()->json(['message' => 'Cannot DELETE a processed Receiving Entry.'], 422);
    }

    $now = now();

    $hdrQ->update([
        'deleted_flag' => true,
        'deleted_by'   => $userId > 0 ? $userId : null,
        'updated_at'   => $now,
    ]);

    return response()->json(['ok' => true]);
}

public function process(Request $req, int $id, ReceivingPurchaseJournalService $svc)
{
    $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    $inputs = $req->validate([
        'explanation' => ['required', 'string', 'max:1000'],
        'booking_no'  => ['nullable', 'string', 'max:25'],
    ]);

    $userId = (int) ($req->input('user_id') ?: optional($req->user())->id ?: 0);
    $ip     = (string) ($req->ip() ?: '0.0.0.0');

    $hdrQ = DB::table('receiving_entry')
        ->where('company_id', $companyId)
        ->where('id', $id);

    $re = (clone $hdrQ)->first();

    if (!$re) {
        return response()->json(['message' => 'Receiving entry not found'], 404);
    }

    if ((bool) ($re->deleted_flag ?? false)) {
        return response()->json(['message' => 'Cannot PROCESS a deleted Receiving Entry.'], 422);
    }

    if (!(bool) ($re->posted_flag ?? false)) {
        return response()->json(['message' => 'Cannot PROCESS: Receiving Entry is not posted yet.'], 422);
    }

    if ((bool) ($re->processed_flag ?? false)) {
        return response()->json(['message' => 'Receiving Entry is already processed.'], 422);
    }

    try {
        $preview = $svc->buildJournalPreview($companyId, $id);

        if (empty($preview['totals']['balanced'])) {
            return response()->json(['message' => 'Cannot PROCESS: Purchase Journal is not balanced.'], 422);
        }

        $cpId = $svc->upsertPurchaseJournalFromReceiving(
            $companyId,
            $id,
            $userId,
            $ip,
            $inputs
        );

        $now = now();

        $hdrQ->update([
            'processed_flag'   => true,
            'processed_by'     => $userId > 0 ? $userId : null,
            'processed_at'     => $now,
            'cash_purchase_id' => $cpId,
            'updated_at'       => $now,
        ]);

        return response()->json([
            'ok'               => true,
            'cash_purchase_id' => $cpId,
        ]);
    } catch (\Throwable $e) {
        \Log::error('receiving posting process failed', [
            'id'         => $id,
            'company_id' => $companyId,
            'err'        => $e->getMessage(),
        ]);

        return response()->json(['message' => $e->getMessage()], 422);
    }
}




}
