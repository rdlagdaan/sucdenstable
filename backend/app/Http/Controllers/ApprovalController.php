<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                if ($u) return $u;
            } catch (\Throwable $e) { /* ignore */ }
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
            } catch (\Throwable $e) { /* ignore */ }
        }

        return null;
        }

    // ===================== Endpoints =====================

    // POST /approvals/request
public function requestEdit(\Illuminate\Http\Request $req)
{
    $module     = $req->input('module')     ?? $req->input('subject_type');
    $recordId   = (int) ($req->input('record_id') ?? $req->input('subject_id'));
    $companyId  = $req->input('company_id');      // may be null; pass from FE
    $reason     = (string) ($req->input('reason') ?? '');
    $requester  = $req->user()->id ?? null;

    if (!$module || !$recordId) {
        return response()->json(['message' => 'module and record_id are required'], 422);
    }

    // Reuse latest pending for same company (if provided) to avoid duplicates
    $existing = \DB::table('approvals')
        ->where('module', $module)
        ->where('record_id', $recordId)
        ->when($companyId, fn($q)=>$q->where('company_id', $companyId))
        ->where('status', 'pending')
        ->orderByDesc('id')
        ->first();

    if ($existing) {
        \DB::table('approvals')->where('id', $existing->id)->update([
            'reason'     => $reason,
            'updated_at' => now(),
        ]);
        return response()->json(['ok'=>true,'id'=>$existing->id,'status'=>'pending']);
    }

    $id = \DB::table('approvals')->insertGetId([
        'company_id'   => $companyId,
        'module'       => $module,
        'record_id'    => $recordId,
        'action'       => 'edit',
        'reason'       => $reason,
        'status'       => 'pending',
        'requester_id' => $requester,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    return response()->json(['ok'=>true,'id'=>$id,'status'=>'pending']);
}


    // GET /approvals/{id}
    public function show($id)
    {
        $req = ApprovalRequest::findOrFail($id);
        $this->authorizeView($req);
        return response()->json($req);
    }

public function statusBySubject(\Illuminate\Http\Request $req)
{
    try {
        $module    = (string) $req->query('module', '');
        $recordId  = (int) $req->query('record_id', 0);
        $companyId = $req->query('company_id'); // optional

        if ($module === '' || $recordId <= 0) {
            return response()->json(['exists'=>false]);
        }

        $q = \DB::table('approvals')
            ->where('module', $module)
            ->where('record_id', $recordId);

        if (!is_null($companyId) && $companyId !== '') {
            $q->where('company_id', $companyId);
        }

        $row = $q->orderByDesc('id')->first();

        // Fallback without company filter (legacy)
        if (!$row && !is_null($companyId) && $companyId !== '') {
            $row = \DB::table('approvals')
                ->where('module', $module)
                ->where('record_id', $recordId)
                ->orderByDesc('id')->first();
        }

        if (!$row) return response()->json(['exists'=>false]);

        $now       = now();
        $status    = strtolower((string) $row->status);
        $expiresAt = $row->expires_at ? \Carbon\Carbon::parse($row->expires_at) : null;
        $consumed  = !empty($row->consumed_at);

        $active = $status === 'approved'
               && $expiresAt && $now->lt($expiresAt)
               && !$consumed;

        return response()->json([
            'exists'          => true,
            'id'              => $row->id,
            'status'          => $status,
            'reason'          => $row->reason ?? null,
            'approved_at'     => $row->approved_at ?? null,
            'expires_at'      => $expiresAt?->toISOString(),
            'approved_active' => $active,
            'first_edit_at'   => $row->first_edit_at,
            // include token only if you use it in your guard
            'approval_token'  => $row->approval_token ?? null,
        ]);
    } catch (\Throwable $e) {
        \Log::warning('approvals.status error', ['err'=>$e->getMessage()]);
        return response()->json(['exists'=>false], 200);
    }
}


public function releaseBySubject(\Illuminate\Http\Request $req)
{
    $data = $req->validate([
        'module'     => 'required|string',
        'record_id'  => 'required|integer',
        'company_id' => 'nullable|integer',
    ]);

    $q = \DB::table('approvals')
        ->where('module', $data['module'])
        ->where('record_id', $data['record_id'])
        ->where('status', 'approved')
        ->whereNull('consumed_at');

    if (!empty($data['company_id'])) $q->where('company_id', $data['company_id']);

    $q->update(['consumed_at' => now()]);

    return response()->json(['ok' => true]);
}



    /**
     * OPTIONAL: in your existing approve() method, set an expiry window and a token.
     * If you already do this elsewhere, skip.
     */
public function approve(\Illuminate\Http\Request $req, int $id)
{
    $minutes = (int)($req->input('expires_minutes', 120));
    $now     = now();
    $expires = $now->copy()->addMinutes($minutes);

    \Illuminate\Support\Facades\DB::table('approvals')
        ->where('id', $id)
        ->update([
            'status'              => 'approved',
            'approved_by'         => $req->user()->id ?? null,
            'approved_at'         => $now,
            'expires_at'          => $expires,
            'edit_window_minutes' => $minutes,   // <-- your column
            'updated_at'          => now(),
        ]);

    return response()->json(['ok'=>true,'expires_at'=>$expires->toISOString()]);
}


    // POST /approvals/{id}/reject
    public function reject(Request $r, $id)
    {
        $req = ApprovalRequest::findOrFail($id);
        $this->authorizeManage($req);

        if ($req->status !== 'pending') {
            return response()->json(['message' => 'Already processed'], 409);
        }

        $u = $this->userFromAnyGuard($r);

        $req->update([
            'status'           => 'rejected',
            'approved_by'      => $u?->id,
            'response_message' => $r->input('response_message'),
            'updated_at'       => now(),
        ]);

        return response()->json($req);
    }

    // GET /approvals/inbox  (supervisors)
public function inbox(\Illuminate\Http\Request $req)
{
    $status    = $req->query('status', 'pending');
    $companyId = $req->query('company_id');

    $rows = \DB::table('approvals')
        ->when($status, fn($q)=>$q->where('status', $status))
        ->when($companyId, fn($q)=>$q->where('company_id', $companyId))
        ->orderByDesc('created_at')
        ->limit(200)
        ->get([
            'id',
            DB::raw("COALESCE(module,'') as subject_type"),
            DB::raw("COALESCE(record_id,0) as subject_id"),
            DB::raw("'edit' as action"),      // ← literal, not a column
            'reason',
            'status',
            'created_at',
        ]);


    return response()->json($rows);
}

public function outbox(Request $req)
{
    $uid = optional($req->user())->id ?: (int) $req->query('requester_id', 0);

    $rows = DB::table('approvals')
        ->when($uid, fn ($q) => $q->where('requester_id', $uid))
        ->orderByDesc('created_at')
        ->limit(200)
        ->get([
            'id',
            DB::raw("COALESCE(module,'') as subject_type"),
            DB::raw("COALESCE(record_id,0) as subject_id"),
            DB::raw("'edit' as action"),      // ← literal, not a column
            'reason',
            'status',
            'created_at',
        ]);


    return response()->json($rows);
}

    // ================= Authorization helpers =================

    private function authorizeView(ApprovalRequest $req): void
    {
        if ($this->approvalsBypass()) return; // <- bypass during integration
        $u = $this->userFromAnyGuard(request());
        if (!$u) abort(403);
        if ((int) $u->company_id !== (int) $req->company_id) abort(403);
    }

    private function authorizeManage(ApprovalRequest $req): void
    {
        if ($this->approvalsBypass()) return; // <- bypass during integration
        $u = $this->userFromAnyGuard(request());
        if (!$u) abort(403);
        if ((int) $u->company_id !== (int) $req->company_id) abort(403);

        $role    = strtolower((string) ($u->role_name ?? $u->role ?? $u->usertype ?? ''));
        $isAdmin = (bool) ($u->is_admin ?? ($role === 'admin' || $role === 'administrator'));
        $perms   = is_array($u->permissions ?? null) ? $u->permissions : [];

        $can = $isAdmin
            || in_array('approval.manage', $perms, true)
            || in_array($role, ['supervisor', 'approver'], true);

        if (!$can) abort(403, 'Not allowed to approve');
    }

    private function authorizeSupervisor(Request $r, int $companyId): void
    {
        if ($this->approvalsBypass()) return; // <- bypass during integration
        $u = $this->userFromAnyGuard($r);
        if (!$u) abort(403);
        if ((int) $u->company_id !== (int) $companyId) abort(403);
        // If you want to require specific roles to *view* inbox, add checks here.
    }
}
