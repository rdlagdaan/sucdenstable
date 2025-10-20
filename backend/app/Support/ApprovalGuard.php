<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Carbon\Carbon;
class ApprovalGuard
{
    /**
     * Throw 403 if the record is not in an active APPROVED edit window.
     *
     * @param string      $module     e.g. 'cash_disbursement'
     * @param int         $recordId
     */
    public static function assertApproved(
        string $module,
        int $recordId,
        ?int $companyId = null,
        array $opts = []
    ): void {
        $allowIfNoApproval = $opts['allowIfNoApproval'] ?? true;
        $token             = $opts['token']            ?? null;

        $q = DB::table('approvals')
            ->where('module', $module)
            ->where('record_id', $recordId);

        if (!is_null($companyId)) $q->where('company_id', $companyId);

        $row = $q->orderByDesc('id')->first();

        // No approval row -> allow if this is a "new/draft" situation
        if (!$row) {
            if ($allowIfNoApproval) return;
            throw new HttpException(403, 'Editing not approved.');
        }

        $status     = strtolower((string)($row->status ?? ''));
        $expiresAt  = $row->expires_at ? Carbon::parse($row->expires_at) : null;
        $consumed   = !empty($row->consumed_at);

        $active = ($status === 'approved')
               && ($expiresAt && $expiresAt->isFuture())
               && !$consumed;

        if (!$active) throw new HttpException(403, 'Editing not approved.');

        if (!empty($row->approval_token) && $token && $token !== $row->approval_token) {
            throw new HttpException(403, 'Invalid approval token.');
        }
    }

}
