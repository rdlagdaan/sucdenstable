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
            
            ->leftJoin('approvals as ap_post', function($j) use ($companyId) {
                $j->on('ap_post.record_id', '=', 'r.id')
                ->where('ap_post.module', '=', 'receiving_entries')
                ->where('ap_post.company_id', '=', $companyId)
                ->whereRaw("LOWER(ap_post.action) = 'post'")
                ->where('ap_post.status', '=', 'pending')
                ->whereNull('ap_post.consumed_at');
            })
->leftJoin('approvals as ap_unpost', function($j) use ($companyId) {
    $j->on('ap_unpost.record_id', '=', 'r.id')
      ->where('ap_unpost.module', '=', 'receiving_entries')
      ->where('ap_unpost.company_id', '=', $companyId)
      ->whereRaw("LOWER(ap_unpost.action) = 'unpost'")
      ->where('ap_unpost.status', '=', 'pending')
      ->whereNull('ap_unpost.consumed_at');
})
->leftJoin('approvals as ap_delete', function($j) use ($companyId) {
    $j->on('ap_delete.record_id', '=', 'r.id')
      ->where('ap_delete.module', '=', 'receiving_entries')
      ->where('ap_delete.company_id', '=', $companyId)
      ->whereRaw("LOWER(ap_delete.action) = 'delete'")
      ->where('ap_delete.status', '=', 'pending')
      ->whereNull('ap_delete.consumed_at');
})
->leftJoin('approvals as ap_process', function($j) use ($companyId) {
    $j->on('ap_process.record_id', '=', 'r.id')
      ->where('ap_process.module', '=', 'receiving_entries')
      ->where('ap_process.company_id', '=', $companyId)
      ->whereRaw("LOWER(ap_process.action) = 'process'")
      ->where('ap_process.status', '=', 'pending')
      ->whereNull('ap_process.consumed_at');
})

            
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
    DB::raw('COALESCE(SUM(d.quantity),0) as quantity'),
    DB::raw("CASE WHEN ap_post.id IS NULL THEN 0 ELSE 1 END as pending_post"),
    DB::raw("CASE WHEN ap_unpost.id IS NULL THEN 0 ELSE 1 END as pending_unpost"),
    DB::raw("CASE WHEN ap_delete.id IS NULL THEN 0 ELSE 1 END as pending_delete"),
    DB::raw("CASE WHEN ap_process.id IS NULL THEN 0 ELSE 1 END as pending_process")
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
                'p.vendor_name','p.vend_code','p.sugar_type','p.crop_year', 'ap_post.id','ap_unpost.id','ap_delete.id', 'ap_process.id'

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
        return response()->json($data);
    } catch (\Throwable $e) {
        \Log::warning('receiving preview journal failed', ['id' => $id, 'err' => $e->getMessage()]);
        return response()->json(['message' => $e->getMessage()], 422);
    }
}




}
