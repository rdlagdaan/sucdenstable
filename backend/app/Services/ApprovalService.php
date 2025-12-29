<?php

namespace App\Services;

use App\Models\Approval;
use Illuminate\Contracts\Auth\Authenticatable;

class ApprovalService
{
    /**
     * Create a new approval request.
     */
    public function requestApproval(
        string $module,
        int $recordId,
        int $companyId,
        Authenticatable $requester,
        string $action,
        ?string $reason = null,
        ?int $editWindowMinutes = null
    ): Approval {
        return Approval::create([
            'module'             => $module,
            'record_id'          => $recordId,
            'company_id'         => $companyId,
            'requester_id'       => $requester->id,
            'reason'             => $reason,
            'status'             => Approval::STATUS_PENDING,
            'edit_window_minutes'=> $editWindowMinutes,
            'action'             => $action,
        ]);
    }

    /**
     * Find an "active" approval for this module/record/action & requester.
     */
    public function findActiveApproval(
        string $module,
        int $recordId,
        int $companyId,
        string $action,
        ?int $requesterId = null
    ): ?Approval {
        $q = Approval::forModule($module)
            ->forRecord($recordId)
            ->forAction($action)
            ->where('company_id', $companyId)
            ->active()
            ->orderByDesc('id');

        if ($requesterId) {
            $q->where('requester_id', $requesterId);
        }

        return $q->first();
    }

    /**
     * Check if the current user can proceed with an edit protected by approval.
     * Returns an Approval instance if allowed (may be null if not needed).
     */
    public function checkOrThrow(
        string $module,
        int $recordId,
        int $companyId,
        string $action,
        Authenticatable $user
    ): ?Approval {
        // Superusers / supervisors could bypass here if you want:
        // if ($user->hasRole('supervisor')) { return null; }

        $approval = $this->findActiveApproval($module, $recordId, $companyId, $action, $user->id);

        if (!$approval || !$approval->isUsableNow()) {
            abort(403, 'Supervisor approval required for this action.');
        }

        // Optionally mark first time used
        if (!$approval->first_edit_at) {
            $approval->first_edit_at = now();
            $approval->save();
        }

        return $approval;
    }

    /**
     * Consume the approval once the protected edit has been successfully done.
     */
    public function consume(Approval $approval): void
    {
        $approval->markConsumed();
    }
}
