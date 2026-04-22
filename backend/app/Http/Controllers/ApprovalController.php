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
$action = strtolower(trim((string) $req->input('action', 'edit')));

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

    $module    = strtolower(trim((string) $req->module));
    $action    = strtolower(trim((string) $req->action));
    $companyId = (int) ($req->company_id ?? 0);
    $recordId  = (int) ($req->record_id ?? 0);

    $context = null;

    if (in_array($module, ['cash_receipt', 'cash_receipts'], true)) {
        $main = DB::table('cash_receipts')
            ->where('id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
            ->first();

        $details = DB::table('cash_receipt_details as d')
            ->where('d.transaction_id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('d.company_id', $companyId))
            ->leftJoin('account_code as a', function ($j) use ($companyId) {
                $j->on('d.acct_code', '=', 'a.acct_code');
                if ($companyId > 0) {
                    $j->where('a.company_id', '=', $companyId);
                }
            })
            ->orderBy('d.id')
            ->get([
                'd.id',
                'd.transaction_id',
                'd.acct_code',
                DB::raw("COALESCE(a.acct_desc, '') as acct_desc"),
                'd.debit',
                'd.credit',
                'd.workstation_id',
            ]);

        $context = [
            'transaction_type'  => 'Receipt',
            'transaction_no'    => $main->cr_no ?? null,
            'transaction_main'  => $main,
            'transaction_details' => $details,
        ];
    }

    if (in_array($module, ['cash_disbursement', 'cash_disbursements'], true)) {
        $main = DB::table('cash_disbursement')
            ->where('id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
            ->first();

        $details = DB::table('cash_disbursement_details as d')
            ->where('d.transaction_id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('d.company_id', $companyId))
            ->leftJoin('account_code as a', function ($j) use ($companyId) {
                $j->on('d.acct_code', '=', 'a.acct_code');
                if ($companyId > 0) {
                    $j->where('a.company_id', '=', $companyId);
                }
            })
            ->orderBy('d.id')
            ->get([
                'd.id',
                'd.transaction_id',
                'd.acct_code',
                DB::raw("COALESCE(a.acct_desc, '') as acct_desc"),
                'd.debit',
                'd.credit',
                'd.workstation_id',
            ]);

        $context = [
            'transaction_type'  => 'Disbursement',
            'transaction_no'    => $main->cd_no ?? null,
            'transaction_main'  => $main,
            'transaction_details' => $details,
        ];
    }

    if ($module === 'sales_journal') {
        $main = DB::table('cash_sales')
            ->where('id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
            ->first();

        $details = DB::table('cash_sales_details as d')
            ->where('d.transaction_id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('d.company_id', $companyId))
            ->leftJoin('account_code as a', function ($j) use ($companyId) {
                $j->on('d.acct_code', '=', 'a.acct_code');
                if ($companyId > 0) {
                    $j->where('a.company_id', '=', $companyId);
                }
            })
            ->orderBy('d.id')
            ->get([
                'd.id',
                'd.transaction_id',
                'd.acct_code',
                DB::raw("COALESCE(a.acct_desc, '') as acct_desc"),
                'd.debit',
                'd.credit',
            ]);

        $context = [
            'transaction_type'  => 'Sales',
            'transaction_no'    => $main->cs_no ?? null,
            'transaction_main'  => $main,
            'transaction_details' => $details,
        ];
    }

    if ($module === 'purchase_journal') {
        $main = DB::table('cash_purchase')
            ->where('id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
            ->first();

        $details = DB::table('cash_purchase_details as d')
            ->where('d.transaction_id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('d.company_id', $companyId))
            ->leftJoin('account_code as a', function ($j) use ($companyId) {
                $j->on('d.acct_code', '=', 'a.acct_code');
                if ($companyId > 0) {
                    $j->where('a.company_id', '=', $companyId);
                }
            })
            ->orderBy('d.id')
            ->get([
                'd.id',
                'd.transaction_id',
                'd.acct_code',
                DB::raw("COALESCE(a.acct_desc, '') as acct_desc"),
                'd.debit',
                'd.credit',
            ]);

        $context = [
            'transaction_type'  => 'Purchase',
            'transaction_no'    => $main->cp_no ?? null,
            'transaction_main'  => $main,
            'transaction_details' => $details,
        ];
    }

    if ($module === 'general_accounting') {
        $main = DB::table('general_accounting')
            ->where('id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
            ->first();

        $details = DB::table('general_accounting_details as d')
            ->where('d.transaction_id', $recordId)
            ->when($companyId > 0, fn ($q) => $q->where('d.company_id', $companyId))
            ->leftJoin('account_code as a', function ($j) use ($companyId) {
                $j->on('d.acct_code', '=', 'a.acct_code');
                if ($companyId > 0) {
                    $j->where('a.company_id', '=', $companyId);
                }
            })
            ->orderBy('d.id')
            ->get([
                'd.id',
                'd.transaction_id',
                'd.acct_code',
                DB::raw("COALESCE(a.acct_desc, '') as acct_desc"),
                'd.debit',
                'd.credit',
                'd.company_id',
            ]);

        $context = [
            'transaction_type'  => 'General',
            'transaction_no'    => $main->ga_no ?? null,
            'transaction_main'  => $main,
            'transaction_details' => $details,
        ];
    }

    if ($module === 'receiving_entries' && $action === 'process') {
        $receiving = DB::table('receiving_entry')
            ->where('id', $recordId)
            ->when($companyId > 0, fn($q) => $q->where('company_id', $companyId))
            ->first();

        $preview = null;
        $previewError = null;

        try {
            $svc = app(\App\Services\ReceivingPurchaseJournalService::class);
            $preview = $svc->buildJournalPreview($companyId, $recordId);
        } catch (\Throwable $e) {
            $previewError = $e->getMessage();
            \Log::error('Approval show(): buildJournalPreview failed', [
                'approval_id' => $req->id,
                'company_id'  => $companyId,
                'record_id'   => $recordId,
                'err'         => $e->getMessage(),
            ]);
        }

        $context = [
            'receiving' => $receiving,
            'purchase_journal_preview' => $preview,
            'purchase_journal_preview_error' => $previewError,
        ];
    }

    return response()->json([
        'approval' => $req,
        'context'  => $context,
    ]);
}

public function statusBySubject(\Illuminate\Http\Request $req)
{
    try {
        $module    = (string) $req->query('module', '');
        $recordId  = (int) $req->query('record_id', 0);
        $companyId = $req->query('company_id');
        $action    = strtolower(trim((string) $req->query('action', '')));

        if ($module === '' || $recordId <= 0) {
            return response()->json(['exists' => false]);
        }

        $q = \DB::table('approvals')
            ->where('module', $module)
            ->where('record_id', $recordId);

        if (!is_null($companyId) && $companyId !== '') {
            $q->where('company_id', $companyId);
        }

        if ($action !== '') {
            $q->whereRaw('LOWER(action) = ?', [$action]);
        }

        $row = $q->orderByDesc('id')->first();

        \Log::info('APPROVAL_STATUS_LOOKUP_PRIMARY', [
            'module'     => $module,
            'record_id'  => $recordId,
            'company_id' => $companyId,
            'action'     => $action,
            'row'        => $row,
        ]);

        // Fallback without company filter (legacy)
        if (!$row && !is_null($companyId) && $companyId !== '') {
            $q2 = \DB::table('approvals')
                ->where('module', $module)
                ->where('record_id', $recordId);

            if ($action !== '') {
                $q2->whereRaw('LOWER(action) = ?', [$action]);
            }

            $row = $q2->orderByDesc('id')->first();

            \Log::info('APPROVAL_STATUS_LOOKUP_FALLBACK', [
                'module'     => $module,
                'record_id'  => $recordId,
                'company_id' => $companyId,
                'action'     => $action,
                'row'        => $row,
            ]);
        }

        if (!$row) {
            return response()->json(['exists' => false]);
        }

        $now       = now();
        $status    = strtolower((string) $row->status);
        $expiresAt = $row->expires_at ? \Carbon\Carbon::parse($row->expires_at) : null;
        $consumed  = !empty($row->consumed_at);

        // ✅ IMPORTANT:
        // approved edit requests stay active even when expires_at is NULL
        $active = $status === 'approved'
            && !$consumed
            && (!$expiresAt || $now->lt($expiresAt));

        return response()->json([
            'exists'              => true,
            'id'                  => $row->id,
            'status'              => $status,
            'reason'              => $row->reason ?? null,
            'approved_at'         => $row->approved_at ?? null,
            'expires_at'          => $expiresAt?->toISOString(),
            'approved_active'     => $active,
            'first_edit_at'       => $row->first_edit_at,
            'approval_token'      => $row->approval_token ?? null,
            'edit_window_minutes' => $row->edit_window_minutes ?? null,
            'action'              => $row->action ?? null,
        ]);
    } catch (\Throwable $e) {
        \Log::warning('approvals.status error', ['err' => $e->getMessage()]);
        return response()->json(['exists' => false], 200);
    }
}

public function myApprovedEditAlerts(\Illuminate\Http\Request $req)
{
    try {
        $user = $this->userFromAnyGuard($req);
        if (!$user?->id) {
            return response()->json(['items' => []]);
        }

        $companyId = $req->query('company_id');

        $rows = \DB::table('approvals')
            ->where('requester_id', (int) $user->id)
            ->whereRaw('LOWER(action) = ?', ['edit'])
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->whereNull('consumed_at')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('id')
            ->limit(20)
            ->get([
                'id',
                'module',
                'record_id',
                'requester_id',
                'approved_at',
                'expires_at',
                'company_id',
                'reason',
            ]);

        $items = collect($rows)
            ->filter(function ($row) {
                // if expires_at is null, treat as still active
                if (empty($row->expires_at)) return true;
                return now()->lt(\Carbon\Carbon::parse($row->expires_at));
            })
            ->values()
            ->map(function ($row) {
                $module = strtolower(trim((string) $row->module));
                $recordId = (int) ($row->record_id ?? 0);
                $companyId = (int) ($row->company_id ?? 0);

                $moduleLabel = match ($module) {
                    'sales_journal'      => 'Sales Journal',
                    'cash_receipts',
                    'cash_receipt'       => 'Cash Receipts',
                    'cash_disbursement',
                    'cash_disbursements' => 'Cash Disbursement',
                    'purchase_journal'   => 'Purchase Journal',
                    'general_accounting' => 'General Accounting',
                    default              => ucwords(str_replace('_', ' ', (string) $row->module)),
                };

                $recordNo = null;
                $recordLabel = null;

                if ($module === 'sales_journal') {
                    $recordLabel = 'Sales No.';
                    $recordNo = \DB::table('cash_sales')
                        ->where('id', $recordId)
                        ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                        ->value('cs_no');
                } elseif (in_array($module, ['cash_receipts', 'cash_receipt'], true)) {
                    $recordLabel = 'Receipt No.';
                    $recordNo = \DB::table('cash_receipts')
                        ->where('id', $recordId)
                        ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                        ->value('cr_no');
                } elseif (in_array($module, ['cash_disbursement', 'cash_disbursements'], true)) {
                    $recordLabel = 'Disbursement No.';
                    $recordNo = \DB::table('cash_disbursement')
                        ->where('id', $recordId)
                        ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                        ->value('cd_no');
                } elseif ($module === 'purchase_journal') {
                    $recordLabel = 'Purchase No.';
                    $recordNo = \DB::table('cash_purchase')
                        ->where('id', $recordId)
                        ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                        ->value('cp_no');
                } elseif ($module === 'general_accounting') {
                    $recordLabel = 'General No.';
                    $recordNo = \DB::table('general_accounting')
                        ->where('id', $recordId)
                        ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                        ->value('ga_no');
                }

                return [
                    'id'           => $row->id,
                    'module'       => $row->module,
                    'module_label' => $moduleLabel,
                    'record_id'    => $row->record_id,
                    'record_label' => $recordLabel,
                    'record_no'    => $recordNo,
                    'approved_at'  => $row->approved_at,
                    'reason'       => $row->reason,
                ];
            });

        return response()->json([
            'items' => $items,
            'count' => $items->count(),
        ]);
    } catch (\Throwable $e) {
        \Log::warning('approvals.myApprovedEditAlerts error', ['err' => $e->getMessage()]);
        return response()->json(['items' => [], 'count' => 0], 200);
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
    $approval = Approval::findOrFail($id);
    $this->authorizeManage($approval);

    $now    = now();
    $action = strtoupper(trim((string) ($approval->action ?? 'EDIT')));
    $user   = $this->userFromAnyGuard($request);

    \Log::info('APPROVAL_APPROVE_ENTER', [
        'approval_id' => $id,
        'before'      => DB::table('approvals')->where('id', $id)->first(),
        'action'      => $action,
        'user_id'     => $user?->id,
        'payload'     => $request->all(),
    ]);

    DB::beginTransaction();

    try {
        if ($action === '' || $action === 'EDIT') {
            // ✅ No end-of-day time limit
            // edit_window_minutes must NOT be NULL because column is NOT NULL
            $affected = DB::table('approvals')
                ->where('id', $id)
                ->update([
                    'status'              => 'approved',
                    'approved_by'         => $user?->id,
                    'approved_at'         => $now,
                    'edit_window_minutes' => 0,
                    'expires_at'          => null,
                    'consumed_at'         => null,
                    'updated_at'          => $now,
                ]);

            $after = DB::table('approvals')->where('id', $id)->first();

            \Log::info('APPROVAL_APPROVE_EDIT_UPDATED', [
                'approval_id' => $id,
                'affected'    => $affected,
                'after'       => $after,
            ]);

            DB::commit();

            return response()->json([
                'ok'       => true,
                'affected' => $affected,
                'approval' => $after,
            ]);
        }

        $affected = DB::table('approvals')
            ->where('id', $id)
            ->update([
                'status'              => 'approved',
                'approved_by'         => $user?->id,
                'approved_at'         => $now,
                'edit_window_minutes' => 0,
                'expires_at'          => null,
                'consumed_at'         => $now,
                'updated_at'          => $now,
            ]);

        $after = DB::table('approvals')->where('id', $id)->first();

        \Log::info('APPROVAL_APPROVE_ONE_SHOT_UPDATED', [
            'approval_id' => $id,
            'affected'    => $affected,
            'after'       => $after,
        ]);

        $fresh = Approval::findOrFail($id);
        $this->applyModuleAction($fresh, $request);

        \Log::info('APPROVAL_APPROVE_ONE_SHOT_ACTION_APPLIED', [
            'approval_id' => $id,
            'module'      => $fresh->module,
            'action'      => $fresh->action,
        ]);

        DB::commit();

        return response()->json([
            'ok'       => true,
            'affected' => $affected,
            'approval' => $after,
        ]);
    } catch (\Throwable $e) {
        DB::rollBack();

        \Log::error('APPROVAL_APPROVE_FAILED', [
            'approval_id' => $id,
            'error'       => $e->getMessage(),
            'trace_line'  => $e->getLine(),
            'trace_file'  => $e->getFile(),
        ]);

        return response()->json([
            'message' => 'Approval failed.',
            'error'   => $e->getMessage(),
        ], 500);
    }
}

/**
 * Apply the side-effect of an approved action to its module/record.
 * For example:
 *  - sales_journal + CANCEL  → set cash_sales.is_cancel = 'c'
 *  - sales_journal + DELETE  → set cash_sales.is_cancel = 'd'
 *  - general_accounting + CANCEL / DELETE → update general_accounting.is_cancel
 */
/**
 * Apply the side-effect of an approved action to its module/record.
 */
private function applyModuleAction(Approval $approval, Request $request): void
{
    try {
        $module = trim((string) ($approval->module ?? ''));
        $action = strtoupper(trim((string) ($approval->action ?? '')));

        $recordId  = (int) $approval->record_id;
        $companyId = $approval->company_id;

        if ($recordId <= 0) {
            return;
        }

        $now = now();

        \Log::info('applyModuleAction dispatch', [
            'approval_id' => $approval->id,
            'module_raw'  => $approval->module,
            'module_norm' => $module,
            'action_raw'  => $approval->action,
            'action_norm' => $action,
            'record_id'   => $recordId,
            'company_id'  => $companyId,
        ]);

        // ───── SALES JOURNAL (existing behavior) ─────
        if ($module === 'sales_journal') {
            $q = DB::table('cash_sales')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                $q->update(['is_cancel' => 'c', 'updated_at' => $now]);
            } elseif ($action === 'DELETE') {
                $q->update(['is_cancel' => 'd', 'updated_at' => $now]);
            } elseif ($action === 'UNCANCEL') {
                $q->update(['is_cancel' => 'n', 'updated_at' => $now]);
            }
        }

        // ───── GENERAL ACCOUNTING (NEW) ─────
        if ($module === 'general_accounting') {
            $q = DB::table('general_accounting')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                $q->update(['is_cancel' => 'c', 'updated_at' => $now]);
            } elseif ($action === 'DELETE') {
                $q->update(['is_cancel' => 'd', 'updated_at' => $now]);
            } elseif ($action === 'UNCANCEL') {
                $q->update(['is_cancel' => 'n', 'updated_at' => $now]);
            }
        }

        // ───── CASH RECEIPTS (NEW) ─────
        if ($module === 'cash_receipts' || $module === 'cash_receipt') {
            $q = DB::table('cash_receipts')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                $q->update(['is_cancel' => 'c', 'updated_at' => $now]);
            } elseif ($action === 'DELETE') {
                $q->update(['is_cancel' => 'd', 'updated_at' => $now]);
            } elseif ($action === 'UNCANCEL') {
                $q->update(['is_cancel' => 'n', 'updated_at' => $now]);
            }
        }

        // ───── PURCHASE JOURNAL (NEW) ─────
        if ($module === 'purchase_journal') {
            $q = DB::table('cash_purchase')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                $q->update(['is_cancel' => 'c', 'updated_at' => $now]);
            } elseif ($action === 'UNCANCEL') {
                $q->update(['is_cancel' => 'n', 'updated_at' => $now]);
            } elseif ($action === 'DELETE') {
                $q->update(['is_cancel' => 'd', 'updated_at' => $now]);
            }
        }

        // ───── CASH DISBURSEMENT (NEW) ─────
        if ($module === 'cash_disbursement' || $module === 'cash_disbursements') {
            $q = DB::table('cash_disbursement')->where('id', $recordId);
            if (!empty($companyId)) {
                $q->where('company_id', $companyId);
            }

            if ($action === 'CANCEL') {
                $q->update(['is_cancel' => 'y', 'updated_at' => $now]);
            } elseif ($action === 'UNCANCEL') {
                $q->update(['is_cancel' => 'n', 'updated_at' => $now]);
            } elseif ($action === 'DELETE') {
                DB::table('cash_disbursement_details')
                    ->where('transaction_id', $recordId)
                    ->delete();

                $q->delete();
            }
        }

        // ───── PBN POSTING (NEW) ─────
        if ($module === 'pbn_posting') {
            $act = strtoupper($action);

            $hdr = DB::table('pbn_entry')->where('id', $recordId);
            if (!empty($companyId)) {
                $hdr->where('company_id', $companyId);
            }

            $pbn = (clone $hdr)->first();
            if (!$pbn) return;

            if (isset($pbn->delete_flag) && (int)$pbn->delete_flag === 1) {
                return;
            }

            if ($act === 'POST') {
                if (isset($pbn->close_flag) && (int)$pbn->close_flag === 1) {
                    return;
                }

                $hdr->update([
                    'posted_flag' => 1,
                    'posted_by'   => (string)($approval->approved_by ?? ''),
                    'updated_at'  => $now,
                ]);

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

            if ($act === 'UNPOST_UNUSED') {
                if (isset($pbn->close_flag) && (int)$pbn->close_flag === 1) {
                    return;
                }

                $receivingTable = \App\Http\Controllers\PbnPostingController::RECEIVING_DETAILS_TABLE ?? 'receiving_details';

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
                                'selected_flag' => 0,
                                'updated_at'    => $now,
                            ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('PBN UNPOST_UNUSED skipped (receiving usage table not ready)', [
                        'err' => $e->getMessage(),
                    ]);
                }

                return;
            }

            if ($act === 'CLOSE') {
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

        // ───── RECEIVING ENTRY POSTING (UPDATED) ─────
        if (in_array(trim((string)$module), ['receiving_entries', 'receiving_entry'], true)) {
            $act = strtoupper(trim((string)$action));

            $hdrQ = DB::table('receiving_entry')->where('id', $recordId);
            if (!empty($companyId)) $hdrQ->where('company_id', $companyId);

            $re = (clone $hdrQ)->first();
            if (!$re) return;

            $isDeleted   = (bool)($re->deleted_flag ?? false);
            $isProcessed = (bool)($re->processed_flag ?? false);

            if ($act === 'POST') {
                if ($isDeleted) return;
                if ($isProcessed) return;

                $updated = $hdrQ->update([
                    'posted_flag' => true,
                    'posted_by'   => (int)($approval->approved_by ?? 0),
                    'posted_at'   => $now,
                    'updated_at'  => $now,
                ]);

                \Log::info('Receiving POST applyModuleAction update result', [
                    'record_id'   => $recordId,
                    'company_id'  => $companyId,
                    'updated'     => $updated,
                    'module'      => $module,
                    'action'      => $action,
                    'approval_id' => $approval->id,
                ]);

                // ✅ IMPORTANT: POST does NOT create Purchase Journal. PROCESS does.
                return;
            }

            if ($act === 'PROCESS') {
                $isPosted = (bool)($re->posted_flag ?? false);
                if ($isDeleted) return;
                if (!$isPosted) {
                    throw new \RuntimeException('Cannot PROCESS: Receiving Entry is not posted yet.');
                }
                if ($isProcessed) return;

                // ✅ Updated: removed pay_method/bank/check_ref
                $inputs = $request->validate([
                    'explanation' => ['required','string','max:1000'],
                    'booking_no'  => ['nullable','string','max:25'],
                ]);

                $svc = app(\App\Services\ReceivingPurchaseJournalService::class);

                // Server truth preview + balance check
                $preview = $svc->buildJournalPreview((int)$companyId, (int)$recordId);
                if (empty($preview['totals']['balanced'])) {
                    throw new \RuntimeException('Cannot PROCESS: Purchase Journal is not balanced.');
                }

                $ip  = (string)($request->ip() ?? '0.0.0.0');
                $uid = (int)($approval->approved_by ?? 0);

                // ✅ MUST succeed or throw (do not swallow)
                $cpId = $svc->upsertPurchaseJournalFromReceiving(
                    (int)$companyId,
                    (int)$recordId,
                    $uid,
                    $ip,
                    $inputs
                );

                $hdrQ->update([
                    'processed_flag'   => true,
                    'processed_by'     => $uid,
                    'processed_at'     => $now,
                    'cash_purchase_id' => $cpId,
                    'updated_at'       => $now,
                ]);

                \Log::info('Receiving PROCESS created/updated Purchase Journal', [
                    'company_id'       => $companyId,
                    'receiving_entry'  => $recordId,
                    'cash_purchase_id' => $cpId,
                    'cp_no'            => DB::table('cash_purchase')->where('id', $cpId)->value('cp_no'),
                    'approval_id'      => $approval->id,
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

        // ✅ IMPORTANT: do not hide failures (especially PROCESS)
        throw $e;
    }
}



    /**
     * POST /api/approvals/{id}/reject
     */
public function reject(Request $request, int $id)
{
    $req = Approval::find($id);
    if (!$req) {
        return response()->json([
            'message' => 'Approval request not found.',
        ], 404);
    }

    $this->authorizeManage($req);

    $user = $this->userFromAnyGuard($request);
    $now  = now();

    if ($req->status !== 'pending') {
        return response()->json([
            'message' => 'Already processed',
            'status'  => $req->status,
        ], 409);
    }

    DB::table('approvals')
        ->where('id', $id)
        ->update([
            'status'           => 'rejected',
            'approved_by'      => $user?->id,
            'response_message' => $request->filled('response_message')
                ? $request->input('response_message')
                : DB::raw('response_message'),
            'updated_at'       => $now,
        ]);

    return response()->json(
        DB::table('approvals')->where('id', $id)->first()
    );
}




    /**
     * GET /api/approvals/inbox
     * Query:
     *  - status     (optional; default pending)
     *  - company_id (optional; recommended to send)
     */
public function inbox(Request $req)
{
    $status     = $req->query('status', null);
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
            'company_id',
            DB::raw("COALESCE(module,'') as subject_type"),
            DB::raw("COALESCE(record_id,0) as subject_id"),
            'action',
            'reason',
            'status',
            'created_at',
        ]);

    $rows = $rows->map(function ($row) {
        $module    = strtolower((string)($row->subject_type ?? ''));
        $subjectId = (int)($row->subject_id ?? 0);
        $companyId = (int)($row->company_id ?? 0);

        $row->transaction_label = null;
        $row->transaction_no    = null;

        if (in_array($module, ['cash_receipt', 'cash_receipts'], true)) {
            $row->transaction_label = 'Receipt';
            $row->transaction_no = DB::table('cash_receipts')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('cr_no');
        } elseif (in_array($module, ['cash_disbursement', 'cash_disbursements'], true)) {
            $row->transaction_label = 'Disbursement';
            $row->transaction_no = DB::table('cash_disbursement')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('cd_no');
        } elseif ($module === 'sales_journal') {
            $row->transaction_label = 'Sales';
            $row->transaction_no = DB::table('cash_sales')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('cs_no');
        } elseif ($module === 'purchase_journal') {
            $row->transaction_label = 'Purchase';
            $row->transaction_no = DB::table('cash_purchase')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('cp_no');
        } elseif ($module === 'general_accounting') {
            $row->transaction_label = 'General';
            $row->transaction_no = DB::table('general_accounting')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('ga_no');
        }

        return $row;
    })->values();

    \Log::info('APPROVAL_INBOX_ROWS', [
        'status'      => $status,
        'company_id'  => $companyId,
        'search'      => $search,
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'row_ids'     => $rows->pluck('id')->values()->all(),
        'row_statuses'=> $rows->map(fn($r) => ['id' => $r->id, 'status' => $r->status, 'action' => $r->action])->values()->all(),
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
    $uid = optional($req->user())->id ?: (int) $req->query('requester_id', 0);

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

    $total = (clone $base)->count();

    $rows = $base
        ->orderByDesc('created_at')
        ->offset($offset)
        ->limit($perPage)
        ->get([
            'id',
            'company_id',
            DB::raw("COALESCE(module,'') as subject_type"),
            DB::raw("COALESCE(record_id,0) as subject_id"),
            'action',
            'reason',
            'status',
            'created_at',
        ]);

    $rows = $rows->map(function ($row) {
        $module    = strtolower((string)($row->subject_type ?? ''));
        $subjectId = (int)($row->subject_id ?? 0);
        $companyId = (int)($row->company_id ?? 0);

        $row->transaction_label = null;
        $row->transaction_no    = null;

        if (in_array($module, ['cash_receipt', 'cash_receipts'], true)) {
            $row->transaction_label = 'Receipt';
            $row->transaction_no = DB::table('cash_receipts')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('cr_no');
        } elseif (in_array($module, ['cash_disbursement', 'cash_disbursements'], true)) {
            $row->transaction_label = 'Disbursement';
            $row->transaction_no = DB::table('cash_disbursement')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('cd_no');
        } elseif ($module === 'sales_journal') {
            $row->transaction_label = 'Sales';
            $row->transaction_no = DB::table('cash_sales')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('cs_no');
        } elseif ($module === 'purchase_journal') {
            $row->transaction_label = 'Purchase';
            $row->transaction_no = DB::table('cash_purchase')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('cp_no');
        } elseif ($module === 'general_accounting') {
            $row->transaction_label = 'General';
            $row->transaction_no = DB::table('general_accounting')
                ->where('id', $subjectId)
                ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
                ->value('ga_no');
        }

        return $row;
    })->values();

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
