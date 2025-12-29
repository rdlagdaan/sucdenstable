<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    protected $table = 'approvals';

    protected $fillable = [
        'module',
        'record_id',
        'requester_id',
        'reason',
        'status',
        'edit_window_minutes',
        'approved_by',
        'approved_at',
        'expires_at',
        'company_id',
        'action',
        'first_edit_at',
        'consumed_at',
    ];

    protected $casts = [
        'record_id'          => 'integer',
        'requester_id'       => 'integer',
        'approved_by'        => 'integer',
        'edit_window_minutes'=> 'integer',
        'approved_at'        => 'datetime',
        'expires_at'         => 'datetime',
        'first_edit_at'      => 'datetime',
        'consumed_at'        => 'datetime',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    /* --------- Status helpers --------- */

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_CONSUMED = 'consumed';

    public function scopeForModule(Builder $q, string $module): Builder
    {
        return $q->where('module', $module);
    }

    public function scopeForRecord(Builder $q, int $recordId): Builder
    {
        return $q->where('record_id', $recordId);
    }

    public function scopeForAction(Builder $q, string $action): Builder
    {
        return $q->where('action', $action);
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeActive(Builder $q): Builder
    {
        // "Active" = approved + within edit_window OR not yet consumed/expired
        return $q->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PENDING])
                 ->where(function (Builder $w) {
                     $w->whereNull('expires_at')
                       ->orWhere('expires_at', '>', now());
                 });
    }

    public function requester()
    {
        return $this->belongsTo(\App\Models\User::class, 'requester_id');
    }

    public function approver()
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    /* --------- State transitions --------- */

    public function markApproved(int $approverId): void
    {
        $this->status      = self::STATUS_APPROVED;
        $this->approved_by = $approverId;
        $this->approved_at = now();

        if ($this->edit_window_minutes && !$this->expires_at) {
            $this->expires_at = now()->addMinutes($this->edit_window_minutes);
        }

        $this->save();
    }

    public function markRejected(int $approverId): void
    {
        $this->status      = self::STATUS_REJECTED;
        $this->approved_by = $approverId;
        $this->approved_at = now();
        $this->save();
    }

    /** Mark that the user already used the approval window. */
    public function markConsumed(): void
    {
        $this->status      = self::STATUS_CONSUMED;
        $this->consumed_at = now();
        $this->save();
    }

    public function isUsableNow(): bool
    {
        if (!in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PENDING], true)) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->lte(now())) {
            return false;
        }
        if ($this->status === self::STATUS_CONSUMED) {
            return false;
        }
        return true;
    }
}
