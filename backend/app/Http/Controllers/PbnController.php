<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PbnController extends Controller
{
    // PBN # combobox (headers: PBN No, Sugar Type, Vendor ID, Vendor Name, Crop Year, PBN Date)
    public function list(Request $req)
    {
        $req->validate([
            'company_id' => 'required|integer',
            'q'          => 'nullable|string',
            'vend_code'  => 'nullable|string',
            'sugar_type' => 'nullable|string',
            'crop_year'  => 'nullable|string',
        ]);

        $companyId = (int) $req->query('company_id');
        $q         = trim((string) $req->query('q', ''));
        $vendCode  = $req->query('vend_code');
        $sugarType = $req->query('sugar_type');
        $cropYear  = $req->query('crop_year');

        $rows = DB::table('pbn_entry')
            ->where('company_id', $companyId)
            ->where('posted_flag', 1)                 // ✅ posted only
            ->where(function ($w) {                   // ✅ not closed
                $w->whereNull('close_flag')->orWhere('close_flag', 0);
            })
            ->where(function ($w) {                   // ✅ not deleted/hidden
                $w->whereNull('delete_flag')->orWhere('delete_flag', 0);
            })
           
            ->when($vendCode,  fn($w) => $w->where('vend_code',  $vendCode))
            ->when($sugarType, fn($w) => $w->where('sugar_type', $sugarType))
            ->when($cropYear,  fn($w) => $w->where('crop_year',  $cropYear))
            ->when($q !== '', function ($w) use ($q) {
                $qq = strtolower($q);
                $w->where(function ($k) use ($qq) {
                    $k->whereRaw('LOWER(pbn_number) LIKE ?', ["%{$qq}%"])
                      ->orWhereRaw("TO_CHAR(pbn_date,'YYYY-MM-DD') LIKE ?", ["%{$qq}%"]);
                });
            })
            ->orderByDesc('pbn_date')
            ->limit(100)
            ->get([
                'id','pbn_number','pbn_date',
                'sugar_type','crop_year','vend_code','vendor_name',
            ]);

        return response()->json($rows);
    }



    // Item # combobox for a given PBN
public function items(Request $req)
{
    try {
        $data = $req->validate([
            'company_id' => 'required|integer',
            'pbn_number' => 'required|string',
        ]);

        $companyId = (int) $data['company_id'];
        $pbnNumber = trim((string) $data['pbn_number']); // ✅ preserve leading zeros

        // ✅ Header scope: ensure PBN belongs to this company and is eligible (posted, not closed, not deleted)
        $pbn = DB::table('pbn_entry as e')
            ->where('e.company_id', $companyId)
            ->where('e.pbn_number', $pbnNumber)
            ->where('e.posted_flag', 1)
            ->where(function ($w) { $w->whereNull('e.close_flag')->orWhere('e.close_flag', 0); })
            ->where(function ($w) { $w->whereNull('e.delete_flag')->orWhere('e.delete_flag', 0); })
            ->first(['e.id']);

        if (!$pbn) {
            // No eligible PBN for this company => return empty list (no 500)
            return response()->json([]);
        }

        $detailTable = 'pbn_entry_details';
        if (!\Illuminate\Support\Facades\Schema::hasTable($detailTable)) {
            // If your build uses a different name, log clearly
            \Log::error('PBN items failed: missing table', ['table' => $detailTable]);
            return response()->json(['message' => 'Server Error'], 500);
        }

        // Some builds use 'row', others use 'item_no'
        $itemCol = \Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'row')
            ? 'row'
            : (\Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'item_no') ? 'item_no' : null);

        if (!$itemCol) {
            \Log::error('PBN items failed: missing item column', ['table' => $detailTable]);
            return response()->json(['message' => 'Server Error'], 500);
        }

        // Relationship may be via pbn_entry_id OR via pbn_number (varies by schema/build)
        $hasPbnEntryId = \Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'pbn_entry_id');
        $hasPbnNumber  = \Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'pbn_number');

        $q = DB::table($detailTable . ' as d');

        if ($hasPbnEntryId) {
            // ✅ Join-less filter (fast) since we already validated header row above
            $q->where('d.pbn_entry_id', (int) $pbn->id);
        } elseif ($hasPbnNumber) {
            // ✅ Direct string match (preserves leading zeros)
            $q->where('d.pbn_number', $pbnNumber);
        } else {
            \Log::error('PBN items failed: no link column found', [
                'table' => $detailTable,
                'expected' => ['pbn_entry_id', 'pbn_number'],
            ]);
            return response()->json(['message' => 'Server Error'], 500);
        }

        // ✅ Item # dropdown rules:
        // 1) selected_flag must be available (NULL or 0)
        // 2) item must still have remaining qty to consume
        if (\Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'selected_flag')) {
            $q->where(function ($w) {
                $w->whereNull('d.selected_flag')
                  ->orWhere('d.selected_flag', 0);
            });
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'delete_flag')) {
            $q->where(function ($w) {
                $w->whereNull('d.delete_flag')
                  ->orWhere('d.delete_flag', 0);
            });
        }

        $rows = $q->orderBy('d.' . $itemCol, 'asc')->get([
            DB::raw('d.' . $itemCol . ' as row'),
            DB::raw('d.' . $itemCol . ' as item_no'),
            DB::raw(\Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'mill') ? 'd.mill' : "NULL as mill"),
            DB::raw(\Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'quantity') ? 'd.quantity' : "0 as quantity"),
            DB::raw(\Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'price') ? 'd.price as unit_cost' : (\Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'unit_cost') ? 'd.unit_cost' : "0 as unit_cost")),
            DB::raw(\Illuminate\Support\Facades\Schema::hasColumn($detailTable, 'commission') ? 'd.commission' : "0 as commission"),
            DB::raw('NULL as mill_code'),
        ])->filter(function ($r) use ($companyId, $pbnNumber) {
            $detailQty = (float) ($r->quantity ?? 0);
            $itemNo    = (string) ($r->item_no ?? $r->row ?? '');

            if ($itemNo === '') {
                return false;
            }

            $consumedQty = (float) DB::table('receiving_details as rd')
                ->join('receiving_entry as re', 're.receipt_no', '=', 'rd.receipt_no')
                ->where('re.company_id', $companyId)
                ->where('re.pbn_number', $pbnNumber)
                ->whereRaw('CAST(rd.item_no AS VARCHAR) = ?', [$itemNo])
                ->where(function ($w) {
                    $w->whereNull('re.deleted_flag')
                      ->orWhere('re.deleted_flag', false);
                })
                ->sum('rd.quantity');

            return ($detailQty - $consumedQty) > 0;
        })->values();

        return response()->json($rows);

    } catch (\Throwable $e) {
        \Log::error('PbnController.items failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'company_id' => $req->input('company_id'),
            'pbn_number' => $req->input('pbn_number'),
        ]);
        return response()->json(['message' => 'Server Error'], 500);
    }
}




    /**
     * POST /api/pbn/remaining-check
     * Body:
     *  - company_id (required)
     *  - pbn_detail_id (required)  => pbn_entry_details.id
     *  - request_qty (required)    => qty user wants to consume in Receiving
     *
     * Returns:
     *  - ok (bool)
     *  - remaining_qty
     *  - used_qty
     *  - message (if not ok)
     *
     * NOTE: receiving usage table name must match your system.
     * Expected: receiving_details(company_id,pbn_entry_id,pbn_detail_id,quantity)
     */
    public function remainingCheck(Request $req)
    {
        $data = $req->validate([
            'company_id'     => ['required','integer'],
            'pbn_detail_id'  => ['required','integer'],
            'request_qty'    => ['required','numeric','min:0.0001'],
        ]);

        $companyId   = (int) $data['company_id'];
        $detailId    = (int) $data['pbn_detail_id'];
        $requestQty  = (float) $data['request_qty'];

        $detail = DB::table('pbn_entry_details as d')
            ->join('pbn_entry as e', 'e.id', '=', 'd.pbn_entry_id')
            ->where('d.id', $detailId)
            ->where('e.company_id', $companyId)
            ->where('e.posted_flag', 1)
            ->where(function ($w) { $w->whereNull('e.close_flag')->orWhere('e.close_flag', 0); })
            ->where(function ($w) { $w->whereNull('d.selected_flag')->orWhere('d.selected_flag', 0); })
            ->where(function ($w) { $w->whereNull('d.delete_flag')->orWhere('d.delete_flag', 0); })
            ->first([
                'd.id',
                'd.pbn_entry_id',
                'd.quantity',
            ]);

        if (!$detail) {
            return response()->json([
                'ok'      => false,
                'message' => 'PBN item not available (not posted / closed / unselected).',
            ], 409);
        }

        $qty = (float) ($detail->quantity ?? 0);

        // Best-effort usage lookup
        $usedQty = 0.0;
        try {
// ===== START REPLACE: receiving table via config =====
$receivingTable = (string) config('pbn.receiving_details_table', 'receiving_details');
// ===== END REPLACE: receiving table via config =====

            $usedQty = (float) DB::table($receivingTable)
                ->where('company_id', $companyId)
                ->where('pbn_entry_id', (int)$detail->pbn_entry_id)
                ->where('pbn_detail_id', $detailId)
                ->sum('quantity');
        } catch (\Throwable $e) {
            // If receiving table not ready yet, used stays 0 (safe for now)
            \Log::warning('remainingCheck: receiving usage not available', ['err' => $e->getMessage()]);
        }

        $remaining = max(0, $qty - $usedQty);

        if ($requestQty > $remaining + 0.00001) {
            return response()->json([
                'ok'            => false,
                'used_qty'      => $usedQty,
                'remaining_qty' => $remaining,
                'message'       => 'Not enough remaining quantity for this PBN item.',
            ], 409);
        }

        return response()->json([
            'ok'            => true,
            'used_qty'      => $usedQty,
            'remaining_qty' => $remaining,
        ]);
    }






}
