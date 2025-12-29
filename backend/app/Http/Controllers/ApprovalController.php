<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class ApprovalController extends Controller
{
    /**
     * While finishing integration, allow requests through without changing Axios.
     * Turn this OFF later by setting APPROVALS_DEV_BYPASS=false in .env
     */
    private function approvalsBypass(): bool
    {
        return (bool) env('APPROVALS_DEV_BYPASS', true);
    }

    /**
     * Try to resolve the current user from any common guard
     * (web/api/sanctum) or from a Bearer token (Sanctum).
     */
    private function userFromAnyGuard(Request $r)
    {
        foreach (['sanctum', 'api', 'web'] as $guard) {
            try {
                $u = auth($guard)->user();
                if ($u) {
                    return $u;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Fallback: Bearer token (Sanctum)
        $auth = (string) $r->header('Authorization', '');
        if (stripos($auth, 'Bearer ') === 0 && class_exists(PersonalAccessToken::class)) {
            $plain = trim(substr($auth, 7));
            try {
                $pat = PersonalAccessToken::findToken($plain);
                if ($pat && method_exists($pat, 'tokenable')) {
                    return $pat->tokenable; // usually App\Models\User
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return null;
    }

    // ===================== Endpoints =====================

    /**
     * POST /api/approvals/request-edit
     *
     * Create (or reuse) a pending approval for a module+record+action.
     * JSON body:
     *  - module / subject_type
     *  - record_id / subject_id
     *  - company_id
     *  - action (optional, default "edit")
     *  - reason (optional)
     */
public function requestEdit(Request $req)
{
    $module     = $req->input('module')     ?? $req->input('subject_type');
    $recordId   = (int) ($req->input('record_id') ?? $req->input('subject_id'));
    $companyId  = $req->input('company_id');      // may be null; pass from FE
    $reason     = (string) ($req->input('reason') ?? '');

    $u = $this->userFromAnyGuard($req);
    $requester = $u?->id;

    // ✅ force action to lowercase so we always store 'edit', 'cancel', 'delete', etc.
    $action = strtolower((string) $req->input('action', 'edit'));

    if (!$module || !$recordId) {
        return response()->json(['message' => 'module and record_id are required'], 422);
    }

    try {
        $now = now();

        // 0️⃣  Auto-expire ONLY UNUSED approvals whose window has elapsed
        DB::table('approvals')
            ->where('module', $module)
            ->where('record_id', $recordId)
            ->whereRaw('LOWER(action) = ?', [$action]) // ✅ case-insensitive match
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('consumed_at')
            ->whereNull('first_edit_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->update([
                'status'      => 'expired',
                'consumed_at' => $now,
                'updated_at'  => $now,
            ]);

        // 1️⃣  Look for any ACTIVE approval (pending or approved, not consumed, not expired)
        $existing = DB::table('approvals')
            ->where('module', $module)
            ->where('record_id', $recordId)
            ->whereRaw('LOWER(action) = ?', [$action]) // ✅ case-insensitive match
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('consumed_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', $now);
            })
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            // If still pending, just update the reason
            if ($existing->status === 'pending') {
                DB::table('approvals')
                    ->where('id', $existing->id)
                    ->update([
                        'reason'     => $reason,
                        'updated_at' => $now,
                    ]);

                $existing->reason = $reason;
            }

            return response()->json([
                'ok'          => true,
                'id'          => $existing->id,
                'status'      => $existing->status,
                'reused'      => true,
                'request_ctr' => $existing->request_ctr ?? null,
            ]);
        }

        // 2️⃣  No active approval → find latest request_ctr for history
        $latestCtr = DB::table('approvals')
            ->where('module', $module)
            ->where('record_id', $recordId)
            ->whereRaw('LOWER(action) = ?', [$action]) // ✅ case-insensitive match
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->max('request_ctr');

        $nextCtr = $latestCtr ? ((int) $latestCtr + 1) : 1;

        // 3️⃣  Insert new row with incremented ctr
        $id = DB::table('approvals')->insertGetId([
            'company_id'   => $companyId,
            'module'       => $module,
            'record_id'    => $recordId,
            'action'       => $action,  // ✅ stored lowercase
            'reason'       => $reason,
            'status'       => 'pending',
            'requester_id' => $requester,
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
    } catch (\Throwable $e) {
        \Log::error('approvals.request-edit failed', [
            'module'     => $module,
            'record_id'  => $recordId,
            'company_id' => $companyId,
            'action'     => $action,
            'err'        => $e->getMessage(),
        ]);

        return response()->json([
            'message' => 'Could not create edit approval request.',
        ], 500);
    }
}


    /**
     * GET /api/approvals/{id}
     */
    public function show($id)
    {
        $req = Approval::findOrFail($id);
        $this->authorizeView($req);

        return response()->json($req);
    }

public function statusBySubject(\Illuminate\Http\Request $req)
{
    try {
        $module    = (string) $req->query('module', '');
        $recordId  = (int) $req->query('record_id', 0);
        $companyId = $req->query('company_id'); // optional
        $action    = (string) $req->query('action', ''); // 🔹 optional, e.g. 'edit'

        if ($module === '' || $recordId <= 0) {
            return response()->json(['exists' => false]);
        }

        $q = \DB::table('approvals')
            ->where('module', $module)
            ->where('record_id', $recordId);

        if (!is_null($companyId) && $companyId !== '') {
            $q->where('company_id', $companyId);
        }

        $action = (string) $req->query('action', '');
        if ($action !== '') {
            $q->whereRaw('LOWER(action) = ?', [strtolower($action)]);
        }

        $row = $q->orderByDesc('id')->first();

        // Fallback without company filter (legacy)
        if (!$row && !is_null($companyId) && $companyId !== '') {
            $q2 = \DB::table('approvals')
                ->where('module', $module)
                ->where('record_id', $recordId);
            if ($action !== '') {
                $q2->where('action', $action);
            }
            $row = $q2->orderByDesc('id')->first();
        }

        if (!$row) return response()->json(['exists' => false]);

        $now       = now();
        $status    = strtolower((string) $row->status);
        $expiresAt = $row->expires_at ? \Carbon\Carbon::parse($row->expires_at) : null;
        $consumed  = !empty($row->consumed_at);

        $active = $status === 'approved'
            && $expiresAt && $now->lt($expiresAt)
            && !$consumed;

        return response()->json([
            'exists'             => true,
            'id'                 => $row->id,
            'status'             => $status,
            'reason'             => $row->reason ?? null,
            'approved_at'        => $row->approved_at ?? null,
            'expires_at'         => $expiresAt?->toISOString(),
            'approved_active'    => $active,
            'first_edit_at'      => $row->first_edit_at,
            'approval_token'     => $row->approval_token ?? null,
            'edit_window_minutes'=> $row->edit_window_minutes ?? null,
            'action'             => $row->action ?? null,
        ]);
    } catch (\Throwable $e) {
        \Log::warning('approvals.status error', ['err' => $e->getMessage()]);
        return response()->json(['exists' => false], 200);
    }
}

public function releaseBySubject(Request $req)
{
    $data = $req->validate([
        'module'     => 'required|string',
        'record_id'  => 'required|integer',
        'company_id' => 'nullable|integer',
        'action'     => 'nullable|string',
    ]);

    // ✅ default to edit, normalize to lowercase
    $action = strtolower((string) ($data['action'] ?? 'edit'));

    $q = DB::table('approvals')
        ->where('module', $data['module'])
        ->where('record_id', $data['record_id'])
        ->where('status', 'approved')
        ->whereNull('consumed_at')
        ->whereRaw('LOWER(action) = ?', [$action]); // ✅ case-insensitive match

    if (!empty($data['company_id'])) {
        $q->where('company_id', $data['company_id']);
    }

    $now = now();

    $q->update([
        'consumed_at'   => $now,
        'first_edit_at' => DB::raw("COALESCE(first_edit_at, '{$now->toDateTimeString()}')"),
        'updated_at'    => $now,
    ]);

    return response()->json(['ok' => true]);
}



    /**
     * POST /api/approvals/{id}/approve
     *
     * Body:
     *  - expires_minutes (optional, default 120)
     */
public function approve(Request $request, int $id)
{
    // 1) Optional minutes for EDIT approvals only
    $data = $request->validate([
        'edit_window_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
    ]);

    // 2) Load approval + permission
    $approval = Approval::findOrFail($id);
    $this->authorizeManage($approval);

    $now    = now();
    $action = strtoupper((string) ($approval->action ?? 'EDIT'));

    // Who approved
    $user = $this->userFromAnyGuard($request);

    // Base fields for all approvals
    $baseUpdate = [
        'status'      => 'approved',
        'approved_by' => $user?->id,
        'approved_at' => $now,
        'updated_at'  => $now,
    ];

    if ($action === '' || $action === 'EDIT') {
        // ─────────────────────────────────────────────
        // EDIT → has an edit window (same behavior as GA)
        // ─────────────────────────────────────────────
        $minutes = $data['edit_window_minutes'] ?? 60;
        $expires = $now->copy()->addMinutes($minutes);

        $approval->fill(array_merge($baseUpdate, [
            'edit_window_minutes' => $minutes,
            'expires_at'          => $expires,
            'consumed_at'         => null,   // still usable until released/expired
        ]));
        $approval->save();

        return response()->json([
            'ok'         => true,
            'expires_at' => $expires->toISOString(),
        ]);
    }

    // ─────────────────────────────────────────────
    // CANCEL / DELETE (or other one-shot actions)
    // → NO edit window, apply immediately
    // ─────────────────────────────────────────────
    $approval->fill(array_merge($baseUpdate, [
        'edit_window_minutes' => 0,   // ❗ MUST NOT be NULL
        'expires_at'          => null,
        'consumed_at'         => $now,
    ]));

    $approval->save();

    // Apply module-specific side-effects (e.g. update cash_sales)
    $this->applyModuleAction($approval);

    return response()->json(['ok' => true]);
}


/**
 * Apply the side-effect of an approved action to its module/record.
 * For example:
 *  - sales_journal + CANCEL  → set cash_sales.is_cancel = 'c'
 *  - sales_journal + DELETE  → set cash_sales.is_cancel = 'd'
 *  - general_accounting + CANCEL / DELETE → update general_accounting.is_cancel
 */
private function applyModuleAction(Approval $approval): void
{
    try {
        $module    = $approval->module;
        $action    = strtoupper((string) ($approval->action ?? ''));
        $recordId  = (int) $approval->record_id;
        $companyId = $approval->company_id;

        if ($recordId <= 0) {
            return;
        }

        $now = now();

        // ───── SALES JOURNAL (existing behavior) ─────
        if ($module === 'sales_journal') {
            $q = DB::table('cash_sales')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                $q->update([
                    'is_cancel'  => 'c',
                    'updated_at' => $now,
                ]);
            } elseif ($action === 'DELETE') {
                $q->update([
                    'is_cancel'  => 'd',
                    'updated_at' => $now,
                ]);
            } elseif ($action === 'UNCANCEL') {
                $q->update([
                    'is_cancel'  => 'n',
                    'updated_at' => $now,
                ]);
            }
        }

        // ───── GENERAL ACCOUNTING (NEW) ─────
        if ($module === 'general_accounting') {
            $q = DB::table('general_accounting')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                // cancel JE
                $q->update([
                    'is_cancel'  => 'c',   // <-- use 'c' to mirror Sales Journal
                    'updated_at' => $now,
                ]);
            } elseif ($action === 'DELETE') {
                // soft-delete JE (hide from dropdowns)
                $q->update([
                    'is_cancel'  => 'd',
                    'updated_at' => $now,
                ]);
            } elseif ($action === 'UNCANCEL') {
                // if you later add an UNCANCEL approval type
                $q->update([
                    'is_cancel'  => 'n',
                    'updated_at' => $now,
                ]);
            }
        }


        // ───── CASH RECEIPTS (NEW) ─────
        if ($module === 'cash_receipts' || $module === 'cash_receipt') {
            $q = DB::table('cash_receipts')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                // cancelled
                $q->update([
                    'is_cancel'  => 'c',
                    'updated_at' => $now,
                ]);
            } elseif ($action === 'DELETE') {
                // soft delete
                $q->update([
                    'is_cancel'  => 'd',
                    'updated_at' => $now,
                ]);
            } elseif ($action === 'UNCANCEL') {
                // active
                $q->update([
                    'is_cancel'  => 'n',
                    'updated_at' => $now,
                ]);
            }
        }


        // ───── PURCHASE JOURNAL (NEW) ─────
        if ($module === 'purchase_journal') {
            $q = DB::table('cash_purchase')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                // keep your existing Y/N scheme for now
                $q->update([
                    'is_cancel'  => 'c',
                    'updated_at' => $now,
                ]);
            } elseif ($action === 'UNCANCEL') {
                $q->update([
                    'is_cancel'  => 'n',
                    'updated_at' => $now,
                ]);
            } elseif ($action === 'DELETE') {
                // if you keep hard delete
                $q->update([
                    'is_cancel'  => 'd',
                    'updated_at' => $now,
                ]);
            }
        }



// ───── CASH DISBURSEMENT (NEW) ─────
if ($module === 'cash_disbursement' || $module === 'cash_disbursements') {
    // header table
    $q = DB::table('cash_disbursement')->where('id', $recordId);
    if (!empty($companyId)) {
        $q->where('company_id', $companyId);
    }

    if ($action === 'CANCEL') {
        $q->update([
            'is_cancel'  => 'y',
            'updated_at' => $now,
        ]);
    } elseif ($action === 'UNCANCEL') {
        $q->update([
            'is_cancel'  => 'n',
            'updated_at' => $now,
        ]);
    } elseif ($action === 'DELETE') {
        // hard-delete to match your current CD destroy() behavior
        DB::table('cash_disbursement_details')
            ->where('transaction_id', $recordId)
            ->delete();

        $q->delete();
    }
}


        // ───── PBN POSTING (NEW) ─────
        // module: 'pbn_posting'
        // actions:
        //  - POST          => pbn_entry.posted_flag = 1
        //  - UNPOST_UNUSED => set pbn_entry_details.selected_flag = 0 where used_qty = 0
        //  - CLOSE         => pbn_entry.close_flag = 1
        if ($module === 'pbn_posting') {
            // normalize
            $act = strtoupper($action);

            // Header row
            $hdr = DB::table('pbn_entry')->where('id', $recordId);
            if (!empty($companyId)) {
                $hdr->where('company_id', $companyId);
            }

            // quick fetch for state checks
            $pbn = (clone $hdr)->first();
            if (!$pbn) {
                return;
            }

            // If deleted/hidden, do nothing
            if (isset($pbn->delete_flag) && (int)$pbn->delete_flag === 1) {
                return;
            }

            // 1) POST
            if ($act === 'POST') {
                // do not post if already closed
                if (isset($pbn->close_flag) && (int)$pbn->close_flag === 1) {
                    return;
                }

                $hdr->update([
                    'posted_flag' => 1,
                    'posted_by'   => (string)($approval->approved_by ?? ''), // varchar field
                    'updated_at'  => $now,
                ]);

                // Mark all details as selectable/posted (uses existing selected_flag field)
                DB::table('pbn_entry_details')
                    ->where('pbn_entry_id', $recordId)
                    ->when(!empty($companyId), fn($q) => $q->where('company_id', $companyId))
                    ->where(function ($w) {
                        $w->whereNull('delete_flag')->orWhere('delete_flag', 0);
                    })
                    ->update([
                        'selected_flag' => 1,
                        'updated_at'    => $now,
                    ]);

                return;
            }

            // 2) UNPOST_UNUSED
            if ($act === 'UNPOST_UNUSED') {
                // If closed, do not allow changes
                if (isset($pbn->close_flag) && (int)$pbn->close_flag === 1) {
                    return;
                }

                // Best-effort: requires receiving usage table to exist.
                // This matches the same expected schema described in PbnPostingController:
                // receiving_details: company_id, pbn_entry_id, pbn_detail_id, quantity
                $receivingTable = \App\Http\Controllers\PbnPostingController::RECEIVING_DETAILS_TABLE ?? 'receiving_details';

                // If receiving table is missing, do nothing (safe)
                try {
                    $used = DB::table($receivingTable)
                        ->where('company_id', (int)$companyId)
                        ->where('pbn_entry_id', $recordId)
                        ->groupBy('pbn_detail_id')
                        ->get([
                            'pbn_detail_id',
                            DB::raw('SUM(quantity) as used_qty'),
                        ]);

                    $usedMap = [];
                    foreach ($used as $r) {
                        $k = (int)($r->pbn_detail_id ?? 0);
                        if ($k > 0) $usedMap[$k] = (float)($r->used_qty ?? 0);
                    }

                    // Unpost ONLY those with used_qty == 0
                    $detailIds = DB::table('pbn_entry_details')
                        ->where('pbn_entry_id', $recordId)
                        ->when(!empty($companyId), fn($q) => $q->where('company_id', $companyId))
                        ->where(function ($w) {
                            $w->whereNull('delete_flag')->orWhere('delete_flag', 0);
                        })
                        ->pluck('id')
                        ->all();

                    $unusedIds = [];
                    foreach ($detailIds as $did) {
                        $did = (int)$did;
                        $u = (float)($usedMap[$did] ?? 0);
                        if ($u <= 0) $unusedIds[] = $did;
                    }

                    if (!empty($unusedIds)) {
                        DB::table('pbn_entry_details')
                            ->where('pbn_entry_id', $recordId)
                            ->when(!empty($companyId), fn($q) => $q->where('company_id', $companyId))
                            ->whereIn('id', $unusedIds)
                            ->update([
                                'selected_flag' => 0,  // hides unused from Receiving dropdown
                                'updated_at'    => $now,
                            ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('PBN UNPOST_UNUSED skipped (receiving usage table not ready)', [
                        'err' => $e->getMessage()
                    ]);
                }

                return;
            }

            // 3) CLOSE
            if ($act === 'CLOSE') {
                // allow close only if already posted
                if ((int)($pbn->posted_flag ?? 0) !== 1) {
                    return;
                }

                $hdr->update([
                    'close_flag' => 1,
                    'close_by'   => (string)($approval->approved_by ?? ''),
                    'updated_at' => $now,
                ]);

                return;
            }
        }





    } catch (\Throwable $e) {
        \Log::error('applyModuleAction failed', [
            'approval_id' => $approval->id,
            'module'      => $approval->module,
            'action'      => $approval->action,
            'record_id'   => $approval->record_id,
            'err'         => $e->getMessage(),
        ]);
    }
}



    /**
     * POST /api/approvals/{id}/reject
     */
public function reject(Request $request, int $id)
{
    // 1) Load row from the approvals table via Approval model
    $req = Approval::find($id);
    if (!$req) {
        return response()->json([
            'message' => 'Approval request not found.',
        ], 404);
    }

    // 2) Supervisor / admin permission check
    $this->authorizeManage($req);

    $user = $this->userFromAnyGuard($request);
    $now  = now();

    // 3) Only pending can be rejected
    if ($req->status !== 'pending') {
        return response()->json([
            'message' => 'Already processed',
            'status'  => $req->status,
        ], 409);
    }

    // 4) Apply reject update
    $update = [
        'status'      => 'rejected',
        'approved_by' => $user?->id,
        'updated_at'  => $now,
    ];

    // optional supervisor note
    if ($request->filled('response_message')) {
        $update['response_message'] = $request->input('response_message');
    }

    $req->update($update);

    return response()->json($req->fresh());
}




    /**
     * GET /api/approvals/inbox
     * Query:
     *  - status     (optional; default pending)
     *  - company_id (optional; recommended to send)
     */
public function inbox(Request $req)
{
    $status     = $req->query('status', null);   // allow "all"
    $companyId  = $req->query('company_id');
    $search     = trim((string)$req->query('search', ''));
    $page       = max((int)$req->query('page', 1), 1);
    $perPage    = max((int)$req->query('per_page', 20), 5);

    $q = DB::table('approvals');

    if ($status && $status !== 'all') {
        $q->where('status', $status);
    }

    if ($companyId) {
        $q->where('company_id', $companyId);
    }

    // 🔍 Search by module, record_id, reason
    if ($search !== '') {
        $qq = strtolower($search);
        $q->where(function ($w) use ($qq) {
            $w->whereRaw("LOWER(module) LIKE ?", ["%{$qq}%"])
              ->orWhereRaw("CAST(record_id AS TEXT) LIKE ?", ["%{$qq}%"])
              ->orWhereRaw("LOWER(reason) LIKE ?", ["%{$qq}%"]);
        });
    }

    $total = $q->count();

    $rows = $q->orderByDesc('created_at')
        ->offset(($page - 1) * $perPage)
        ->limit($perPage)
        ->get([
            'id',
            DB::raw("COALESCE(module,'') as subject_type"),
            DB::raw("COALESCE(record_id,0) as subject_id"),
            'action',
            'reason',
            'status',
            'created_at',
        ]);

    return response()->json([
        'data'       => $rows,
        'page'       => $page,
        'per_page'   => $perPage,
        'total'      => $total,
        'total_pages'=> ceil($total / $perPage),
    ]);
}


    /**
     * GET /api/approvals/outbox
     * Query:
     *  - requester_id (optional; defaults to currently logged user)
     */
public function outbox(Request $req)
{
    // requester_id from auth user or explicit query param
    $uid = optional($req->user())->id ?: (int) $req->query('requester_id', 0);

    // 🔍 search & pagination
    $search   = trim((string) $req->query('search', ''));
    $page     = max(1, (int) $req->query('page', 1));
    $perPage  = max(1, min(200, (int) $req->query('per_page', 20)));
    $offset   = ($page - 1) * $perPage;

    $base = DB::table('approvals')
        ->when($uid, fn ($q) => $q->where('requester_id', $uid));

    if ($search !== '') {
        $like = '%' . $search . '%';
        $base->where(function ($q) use ($like) {
            $q->where('module', 'ILIKE', $like)
              ->orWhere('reason', 'ILIKE', $like)
              ->orWhere('status', 'ILIKE', $like)
              ->orWhere('action', 'ILIKE', $like)
              ->orWhereRaw("CAST(record_id AS TEXT) ILIKE ?", [$like]);
        });
    }

    // total count BEFORE limit/offset
    $total = (clone $base)->count();

    $rows = $base
        ->orderByDesc('created_at')
        ->offset($offset)
        ->limit($perPage)
        ->get([
            'id',
            DB::raw("COALESCE(module,'') as subject_type"),
            DB::raw("COALESCE(record_id,0) as subject_id"),
            'action',
            'reason',
            'status',
            'created_at',
        ]);

    return response()->json([
        'data'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
    ]);
}


    // ================= Authorization helpers =================

    private function authorizeView(Approval $req): void
    {
        if ($this->approvalsBypass()) {
            return; // bypass during integration
        }

        $u = $this->userFromAnyGuard(request());
        if (!$u) {
            abort(403);
        }

        if ((int) $u->company_id !== (int) $req->company_id) {
            abort(403);
        }
    }

    private function authorizeManage(Approval $req): void
    {
        if ($this->approvalsBypass()) {
            return; // bypass during integration
        }

        $u = $this->userFromAnyGuard(request());
        if (!$u) {
            abort(403);
        }

        if ((int) $u->company_id !== (int) $req->company_id) {
            abort(403);
        }

        $role    = strtolower((string) ($u->role_name ?? $u->role ?? $u->usertype ?? ''));
        $isAdmin = (bool) ($u->is_admin ?? ($role === 'admin' || $role === 'administrator'));
        $perms   = is_array($u->permissions ?? null) ? $u->permissions : [];

        $can = $isAdmin
            || in_array('approval.manage', $perms, true)
            || in_array($role, ['supervisor', 'approver'], true);

        if (!$can) {
            abort(403, 'Not allowed to approve');
        }
    }

    private function authorizeSupervisor(Request $r, int $companyId): void
    {
        if ($this->approvalsBypass()) {
            return; // bypass during integration
        }

        $u = $this->userFromAnyGuard($r);
        if (!$u) {
            abort(403);
        }

        if ((int) $u->company_id !== (int) $companyId) {
            abort(403);
        }
        // If you want to require specific roles to *view* inbox, add checks here.
    }
}
