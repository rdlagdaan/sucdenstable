<?php

namespace App\Http\Controllers;

use App\Models\ReceivingEntry;
use App\Models\ReceivingDetail;
use App\Models\PbnEntry;
use App\Models\PbnEntryDetail;
use App\Models\MillList;
use App\Models\MillRateHistory;
use App\Models\PlantersList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReceivingController extends Controller
{
    // RR list with search + posted filter
    public function rrList(Request $req)
    {
        $q = trim((string) $req->get('q', ''));
        $includePosted = $req->boolean('include_posted', false) ? 1 : 0;

        $rows = DB::table('receiving_entry as r')
            ->leftJoin('pbn_entry as p', 'p.pbn_number', '=', 'r.pbn_number')
            ->leftJoin('receiving_details as d', 'd.receipt_no', '=', 'r.receipt_no')
            ->select(
                'r.receipt_no',
                DB::raw('COALESCE(SUM(d.quantity),0) as quantity'),
                'p.sugar_type as sugar_type',
                'r.pbn_number',
                'r.receipt_date',
                'p.vend_code as vendor_code',
                'p.vendor_name'
            )
            ->when($q !== '', function ($qq) use ($q) {
                $like = "%{$q}%";
                $qq->where(function ($w) use ($like) {
                    $w->where('r.receipt_no', 'ilike', $like)
                      ->orWhere('r.pbn_number', 'ilike', $like)
                      ->orWhere('p.vendor_name', 'ilike', $like);
                });
            })
            ->when(!$includePosted, fn ($w) => $w->where('r.posted_flag', 0))
            ->groupBy('r.receipt_no', 'p.sugar_type','r.pbn_number','r.receipt_date','p.vend_code','p.vendor_name')
            ->orderBy('r.receipt_no', 'asc')
            ->limit(200)
            ->get();

        return response()->json($rows);
    }

    // Single Receiving Entry header
    public function getReceivingEntry(Request $req)
    {
        $receiptNo = $req->get('receipt_no');
        $entry = ReceivingEntry::query()
            ->where('receipt_no', $receiptNo)
            ->firstOrFail();

        // include vendor name from PBN (if needed)
        $pbn = PbnEntry::where('pbn_number', $entry->pbn_number)->first();
        $entry->vendor_name = $pbn?->vendor_name ?? '';

        return response()->json($entry);
    }

    // Details for a Receiving Entry
    public function getReceivingDetails(Request $req)
    {
        $receiptNo = $req->get('receipt_no');

        $rows = ReceivingDetail::query()
            ->where('receipt_no', $receiptNo)
            ->orderBy('row')
            ->get()
            ->map(function ($r) {
                return [
                    'id'            => $r->id,
                    'row'           => $r->row,
                    'quedan_no'     => $r->quedan_no,
                    'quantity'      => (float)$r->quantity,
                    'liens'         => (float)$r->liens,
                    'week_ending'   => optional($r->week_ending)->format('Y-m-d'),
                    'date_issued'   => optional($r->date_issued)->format('Y-m-d'),
                    'planter_tin'   => $r->planter_tin,
                    'planter_name'  => $r->planter_name,
                    'item_no'       => $r->item_no,
                    'mill'          => $r->mill,
                    'unit_cost'     => (float)$r->unit_cost,
                    'commission'    => (float)$r->commission,
                    'storage'       => (float)$r->storage,
                    'insurance'     => (float)$r->insurance,
                    'total_ap'      => (float)$r->total_ap,
                ];
            });

        return response()->json($rows);
    }

    // PBN item (unit_cost, commission) for receiving header
    public function pbnItemForReceiving(Request $req)
    {
        $pbn = $req->get('pbn_number');
        $item = $req->get('item_no');

        $row = PbnEntryDetail::query()
            ->where('pbn_number', $pbn)
            ->where('row', $item)
            ->select('unit_cost','commission','mill','mill_code')
            ->first();

        return response()->json($row ?: []);
    }

    // Mill rates "as of" a date
    public function millRateAsOf(Request $req)
    {
        $millName = $req->get('mill_name');
        $asOf = $req->get('as_of'); // YYYY-MM-DD

        $mill = MillList::where('mill_name', $millName)->first();
        if (!$mill) return response()->json(['insurance_rate'=>0,'storage_rate'=>0,'days_free'=>0]);

        $rate = MillRateHistory::where('mill_id', $mill->mill_id)
            ->when($asOf, function($q) use ($asOf) {
                $q->where(function($x) use ($asOf) {
                    $x->whereDate('valid_from', '<=', $asOf)
                      ->where(function($y) use ($asOf) {
                         $y->whereNull('valid_to')->orWhereDate('valid_to', '>=', $asOf);
                      });
                });
            })
            ->orderByDesc('valid_from')
            ->first();

        return response()->json([
            'insurance_rate' => (float)($rate->insurance_rate ?? 0),
            'storage_rate'   => (float)($rate->storage_rate ?? 0),
            'days_free'      => (int)($rate->days_free ?? 0),
        ]);
    }

    // Batch insert/upsert a single edited row (called repeatedly)
    public function batchInsertDetails(Request $req)
    {
        $receiptNo = $req->get('receipt_no');
        $rowIdx    = (int) $req->get('row_index', 0);
        $row       = $req->get('row', []);

        $entry = ReceivingEntry::where('receipt_no', $receiptNo)->firstOrFail();

        // lookup planter name if TIN provided
        $planterName = '';
        if (!empty($row['planter_tin'])) {
            $p = PlantersList::where('tin', $row['planter_tin'])->first();
            $planterName = $p?->display_name ?? '';
        }

        // bring pricing context (unit/commission + mill rates)
        $pbnItem = PbnEntryDetail::where('pbn_number', $entry->pbn_number)
                    ->where('row', $entry->item_number)->first();
        $unitCost    = (float) ($pbnItem->unit_cost ?? 0);
        $commission  = (float) ($pbnItem->commission ?? 0);

        // mill rates as-of receipt date (or latest)
        $rate = $this->resolveMillRate($entry->mill, optional($entry->receipt_date)->format('Y-m-d'));
        $insuranceRate = $entry->no_insurance ? 0 : $rate['insurance_rate'];
        $storageRate   = $entry->no_storage   ? 0 : $rate['storage_rate'];
        $daysFree      = $rate['days_free'];

        // compute insurance/storage/AP per legacy rules
        $weekEnding = $row['week_ending'] ?? null;
        $weISO = $weekEnding ? date('Y-m-d', strtotime($weekEnding)) : null;
        $receDateISO = optional($entry->receipt_date)->format('Y-m-d');

        // week overrides from header if provided
        $weekForIns = $entry->insurance_week ? $entry->insurance_week->format('Y-m-d') : $weISO;
        $weekForSto = $entry->storage_week ? $entry->storage_week->format('Y-m-d') : $weISO;

        $qty = (float) ($row['quantity'] ?? 0);

        $monthsIns = ($weekForIns && $receDateISO) ? $this->monthsCeil($weekForIns, $receDateISO) : 0;
        $monthsSto = ($weekForSto && $receDateISO) ? $this->monthsFloorStorage($weekForSto, $receDateISO, $daysFree) : 0;

        $insurance = $qty * $insuranceRate * $monthsIns;
        $storage   = $qty * $storageRate   * $monthsSto;
        $totalAP   = ($qty * $unitCost) - $insurance - $storage;

        $detail = ReceivingDetail::updateOrCreate(
            ['id' => $row['id'] ?? 0],
            [
                'receiving_entry_id' => $entry->id,
                'row'        => $rowIdx,
                'receipt_no' => $receiptNo,
                'quedan_no'  => $row['quedan_no'] ?? null,
                'quantity'   => $qty,
                'liens'      => (float) ($row['liens'] ?? 0),
                'week_ending'=> $weISO,
                'date_issued'=> $row['date_issued'] ?? null,
                'planter_tin'=> $row['planter_tin'] ?? null,
                'planter_name'=> $planterName,
                'item_no'    => $entry->item_number,
                'mill'       => $entry->mill,
                'unit_cost'  => $unitCost,
                'commission' => $commission,
                'storage'    => $storage,
                'insurance'  => $insurance,
                'total_ap'   => $totalAP,
                'user_id'    => $entry->user_id,
                'workstation_id' => $entry->workstation_id,
            ]
        );

        return response()->json(['id' => $detail->id]);
    }

    public function updateFlag(Request $req)
    {
        $req->validate([
            'receipt_no' => 'required',
            'field'      => 'in:no_storage,no_insurance',
            'value'      => 'required|in:0,1',
        ]);
        $entry = ReceivingEntry::where('receipt_no', $req->receipt_no)->firstOrFail();
        $entry->update([$req->field => (int)$req->value]);
        return response()->json(['ok' => true]);
    }

    public function updateDate(Request $req)
    {
        $req->validate([
            'receipt_no' => 'required',
            'field'      => 'in:storage_week,insurance_week,receipt_date',
        ]);
        $entry = ReceivingEntry::where('receipt_no', $req->receipt_no)->firstOrFail();
        $val = $req->get('value');
        $entry->{$req->field} = $val ? date('Y-m-d', strtotime($val)) : null;
        $entry->save();
        return response()->json(['ok' => true]);
    }

    public function updateGL(Request $req)
    {
        $req->validate([
            'receipt_no' => 'required',
            'gl_account_key' => 'nullable|string|max:25',
        ]);
        ReceivingEntry::where('receipt_no', $req->receipt_no)->update(['gl_account_key' => $req->gl_account_key]);
        return response()->json(['ok' => true]);
    }

    public function updateAssocOthers(Request $req)
    {
        $req->validate([
            'receipt_no' => 'required',
            'assoc_dues' => 'numeric',
            'others'     => 'numeric',
        ]);
        ReceivingEntry::where('receipt_no', $req->receipt_no)->update([
            'assoc_dues' => $req->assoc_dues,
            'others'     => $req->others,
        ]);
        return response()->json(['ok' => true]);
    }

    public function updateMillName(Request $req)
    {
        $req->validate([
            'receipt_no' => 'required',
            'mill'       => 'required|string',
        ]);
        $exists = MillList::where('mill_name', $req->mill)->exists();
        if (!$exists) return response()->json(['ok' => false, 'msg' => 'Mill not found'], 422);

        ReceivingEntry::where('receipt_no', $req->receipt_no)->update(['mill' => $req->mill]);
        return response()->json(['ok' => true]);
    }

    // --- helpers ---
    protected function resolveMillRate(string $millName, ?string $asOf): array
    {
        $mill = MillList::where('mill_name', $millName)->first();
        if (!$mill) return ['insurance_rate'=>0,'storage_rate'=>0,'days_free'=>0];

        $q = MillRateHistory::where('mill_id', $mill->mill_id);
        if ($asOf) {
            $q->where(function($x) use ($asOf) {
                $x->whereDate('valid_from', '<=', $asOf)
                  ->where(function($y) use ($asOf) {
                      $y->whereNull('valid_to')->orWhereDate('valid_to', '>=', $asOf);
                  });
            });
        }
        $rate = $q->orderByDesc('valid_from')->first();

        return [
            'insurance_rate' => (float)($rate->insurance_rate ?? 0),
            'storage_rate'   => (float)($rate->storage_rate ?? 0),
            'days_free'      => (int)($rate->days_free ?? 0),
        ];
    }

    protected function monthsCeil(string $fromISO, string $toISO): int
    {
        $diffDays = (strtotime($toISO) - strtotime($fromISO)) / 86400;
        return (int) ceil(abs($diffDays) / 30);
    }

    protected function monthsFloorStorage(string $fromISO, string $toISO, int $freeDays): int
    {
        $diffDays = (strtotime($toISO) - strtotime($fromISO)) / 86400;
        $diffDays -= $freeDays;
        if ($diffDays < 0) $diffDays = 0;
        return (int) floor($diffDays / 30);
    }


    public function create(Request $req)
    {
        // Validate required fields from the UI
        $v = $req->validate([
            'company_id'    => 'required|integer',
            'pbn_number'    => 'required|string',
            'item_number'   => 'required|string',
            'receipt_date'  => 'required|date',     // YYYY-MM-DD
            'mill'          => 'required|string',
            'user_id'       => 'nullable|integer',
            'workstation_id'=> 'nullable|string',
        ]);

        // Get sugar_type + crop_year from authoritative PBN row
        $pbn = DB::table('pbn_entry')->where('pbn_number', $v['pbn_number'])->first();
        if (!$pbn) {
            return response()->json(['message' => 'PBN not found.'], 422);
        }
        $sugarType = (string) ($pbn->sugar_type ?? '');
        $cropYear  = (string) ($pbn->crop_year ?? '');

        try {
            DB::beginTransaction();

            // Lock counter in application_settings (appset_code='RRNo', company_id, type=sugarType)
            $counterRow = DB::table('application_settings')
                ->where('appset_code', 'RRNo')
                ->where('company_id', $v['company_id'])
                ->where('type', $sugarType)
                ->lockForUpdate()
                ->first();

            if (!$counterRow) {
                DB::rollBack();
                return response()->json(['message' => 'RR counter missing for this sugar type.'], 422);
            }

            $current = (int) ($counterRow->value ?? 0);
            $next    = $current + 1;

            DB::table('application_settings')->where('id', $counterRow->id)->update(['value' => $next]);

            // Build: RR-<sugar_type><crop_year><0001> (4-digit, zero-padded)
            $suffix    = str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $receiptNo = 'RR-' . $sugarType . $cropYear . $suffix;

            // Insert receiving_entry with requested defaults
            $id = DB::table('receiving_entry')->insertGetId([
                'company_id'     => $v['company_id'],
                'receipt_no'     => $receiptNo,
                'pbn_number'     => $v['pbn_number'],
                'receipt_date'   => $v['receipt_date'],
                'item_number'    => $v['item_number'],
                'mill'           => $v['mill'],
                'assoc_dues'     => 0,
                'others'         => 0,
                'gl_account_key' => '0',
                'no_insurance'   => false,
                'insurance_week' => null,
                'no_storage'     => false,
                'storage_week'   => null,
                'posted_flag'    => false,
                'selected_flag'  => false,
                'processed_flag' => false,
                'workstation_id' => $req->input('workstation_id') ?: $req->ip(), // inet
                'user_id'        => $req->input('user_id'),
            ]);

            DB::commit();

            return response()->json([
                'id'          => $id,
                'receipt_no'  => $receiptNo,
                'pbn_number'  => $v['pbn_number'],
                'item_number' => $v['item_number'],
                'receipt_date'=> $v['receipt_date'],
                'mill'        => $v['mill'],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

public function createEntry(\Illuminate\Http\Request $req)
{
    $v = $req->validate([
        'company_id'     => 'required|integer',
        'pbn_number'     => 'required|string',
        'item_number'    => 'required|string',
        'receipt_date'   => 'required|date',     // YYYY-MM-DD
        'mill'           => 'required|string',
        'user_id'        => 'nullable|integer',
        'workstation_id' => 'nullable|string',
    ]);

    // Pull sugar_type and crop_year from the PBN row
    $pbn = \DB::table('pbn_entry')->where('pbn_number', $v['pbn_number'])->first();
    if (!$pbn) {
        return response()->json(['message' => 'PBN not found.'], 422);
    }
    $sugarType = (string) ($pbn->sugar_type ?? '');
    $cropYear  = (string) ($pbn->crop_year ?? '');

    // Detect actual column names in application_settings
    // Some schemas use 'apset_code' (one p) vs 'appset_code' (two p),
    // and 'value' vs 'apset_value'.
    $codeCol  = Schema::hasColumn('application_settings', 'apset_code') ? 'apset_code' : 'appset_code';
    $valueCol = Schema::hasColumn('application_settings', 'value')
        ? 'value'
        : (Schema::hasColumn('application_settings', 'apset_value') ? 'apset_value' : null);

    if ($valueCol === null) {
        return response()->json(['message' => 'Cannot find a value column in application_settings (expected "value" or "apset_value").'], 500);
    }

    try {
        \DB::beginTransaction();

        // Lock the counter row to avoid duplicates under concurrency
        $counterRow = \DB::table('application_settings')
            ->where($codeCol, 'RRNo')
            ->where('company_id', $v['company_id'])
            ->where('type', $sugarType)
            ->lockForUpdate()
            ->first();

        if (!$counterRow) {
            \DB::rollBack();
            return response()->json(['message' => 'RR counter missing for this sugar type in application_settings.'], 422);
        }

        $current = (int) ($counterRow->{$valueCol} ?? 0);
        $next    = $current + 1;

        \DB::table('application_settings')->where('id', $counterRow->id)->update([$valueCol => $next]);

        // Build receipt no: RR-<sugar_type><crop_year><0001> (4-digit pad)
        $suffix    = str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        $receiptNo = 'RR-' . $sugarType . $cropYear . $suffix;

        // Insert receiving_entry with requested defaults
        $id = \DB::table('receiving_entry')->insertGetId([
            'company_id'     => $v['company_id'],
            'receipt_no'     => $receiptNo,
            'pbn_number'     => $v['pbn_number'],
            'receipt_date'   => $v['receipt_date'],
            'item_number'    => $v['item_number'],
            'mill'           => $v['mill'],
            'assoc_dues'     => 0,
            'others'         => 0,
            'gl_account_key' => '0',
            'no_insurance'   => false,
            'insurance_week' => null,
            'no_storage'     => false,
            'storage_week'   => null,
            'posted_flag'    => false,
            'selected_flag'  => false,
            'processed_flag' => false,
            'workstation_id' => $req->input('workstation_id') ?: $req->ip(), // inet column ok
            'user_id'        => $req->input('user_id'),
        ]);

        \DB::commit();

        return response()->json([
            'id'           => $id,
            'receipt_no'   => $receiptNo,
            'pbn_number'   => $v['pbn_number'],
            'item_number'  => $v['item_number'],
            'receipt_date' => $v['receipt_date'],
            'mill'         => $v['mill'],
        ], 201);
    } catch (\Throwable $e) {
        \DB::rollBack();
        return response()->json(['message' => $e->getMessage()], 500);
    }
}


}
