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

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

use TCPDF;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;


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
    $millName  = $req->get('mill_name');
    $companyId = (int) $req->get('company_id');   // pass this from FE if not already
    $cropYear  = $req->get('crop_year');          // pass this from FE OR derive if you want

    if (!$millName || !$companyId || !$cropYear) {
        return response()->json(['insurance_rate'=>0,'storage_rate'=>0,'days_free'=>0]);
    }

    $mill = MillList::where('mill_name', $millName)
        ->where('company_id', $companyId)
        ->first();

    if (!$mill) return response()->json(['insurance_rate'=>0,'storage_rate'=>0,'days_free'=>0]);

    $rate = MillRateHistory::where('mill_id', $mill->mill_id)
        ->where('crop_year', $cropYear)
        ->orderByDesc('updated_at')
        ->orderByDesc('id')
        ->first();

    return response()->json([
        'insurance_rate' => (float)($rate->insurance_rate ?? 0),
        'storage_rate'   => (float)($rate->storage_rate ?? 0),
        'days_free'      => (int)($rate->days_free ?? 0),
    ]);
}



protected function resolveMillRateByCropYear(string $millName, int $companyId, string $cropYear): array
{
    // Mill is company-scoped in your system
    $mill = MillList::query()
        ->where('company_id', $companyId)
        ->where('mill_name', $millName)
        ->first();

    if (!$mill) {
        return ['insurance_rate' => 0, 'storage_rate' => 0, 'days_free' => 0];
    }

    // mill_rate_history has NO valid_from/valid_to; use crop_year match
    $q = MillRateHistory::query()
        ->where('mill_id', $mill->mill_id)
        ->where('crop_year', $cropYear);

    // Choose latest record deterministically
    if (Schema::hasColumn('mill_rate_history', 'updated_at')) {
        $q->orderByDesc('updated_at');
    } elseif (Schema::hasColumn('mill_rate_history', 'created_at')) {
        $q->orderByDesc('created_at');
    }
    $q->orderByDesc('id');

    $rate = $q->first();

    return [
        'insurance_rate' => (float)($rate->insurance_rate ?? 0),
        'storage_rate'   => (float)($rate->storage_rate ?? 0),
        'days_free'      => (int)  ($rate->days_free ?? 0),
    ];
}


    // Batch insert/upsert a single edited row (called repeatedly)

public function batchInsertDetails(Request $req)
{
    try {
        $receiptNo = (string) $req->get('receipt_no');
        $rowIdx    = (int) $req->get('row_index', 0);
        $row       = (array) $req->get('row', []);

        $entry = ReceivingEntry::where('receipt_no', $receiptNo)->firstOrFail();

        // ---- safe date helpers ----
        $toDate = function ($v) {
            if (!$v) return null;
            try { return Carbon::parse($v)->format('Y-m-d'); }
            catch (\Throwable $e) { return null; }
        };

        // lookup planter name if TIN provided
        $planterName = '';
        if (!empty($row['planter_tin'])) {
            $p = PlantersList::where('tin', $row['planter_tin'])->first();
            $planterName = $p?->display_name ?? '';
        }

        // bring pricing context (unit/commission + mill rates)
        $pbnItem = PbnEntryDetail::where('pbn_number', $entry->pbn_number)
            ->where('row', $entry->item_number)
            ->first();

        $unitCost   = (float) ($pbnItem->unit_cost ?? 0);
        $commission = (float) ($pbnItem->commission ?? 0);

        // receipt_date ISO (works whether casted or string)
        $receDateISO = $toDate($entry->receipt_date);

$pbn = PbnEntry::where('pbn_number', $entry->pbn_number)
    ->where('company_id', $entry->company_id)
    ->first();

$cropYear = $pbn?->crop_year ?? null;


        // mill rates as-of receipt date (or latest)
$rate = $this->resolveMillRateByCropYear(
    (string)$entry->mill,
    (int)$entry->company_id,
    $cropYear
);
        $insuranceRate = $entry->no_insurance ? 0 : ($rate['insurance_rate'] ?? 0);
        $storageRate   = $entry->no_storage   ? 0 : ($rate['storage_rate'] ?? 0);
        $daysFree      = (int) ($rate['days_free'] ?? 0);

        $weISO = $toDate($row['week_ending'] ?? null);

        // week overrides from header if provided (works whether casted or string)
        $weekForIns = $toDate($entry->insurance_week) ?: $weISO;
        $weekForSto = $toDate($entry->storage_week)   ?: $weISO;

        $qty = (float) ($row['quantity'] ?? 0);

        $monthsIns = ($weekForIns && $receDateISO) ? $this->monthsCeil($weekForIns, $receDateISO) : 0;
        $monthsSto = ($weekForSto && $receDateISO) ? $this->monthsFloorStorage($weekForSto, $receDateISO, $daysFree) : 0;

$liens     = (float) ($row['liens'] ?? 0);

$insurance = $qty * $insuranceRate * $monthsIns;
$storage   = $qty * $storageRate   * $monthsSto;

// ✅ legacy: AP = cost - liens - insurance - storage
$totalAP   = ($qty * $unitCost) - $liens - $insurance - $storage;


        // ✅ DO NOT upsert by id=0
        // Use (receipt_no,row) as the stable identity
        $detail = ReceivingDetail::updateOrCreate(
            [
                'receipt_no' => $receiptNo,
                'row'        => $rowIdx,
            ],
            [
                'receiving_entry_id' => $entry->id,
                'quedan_no'     => $row['quedan_no'] ?? null,
                'quantity'      => $qty,
                'liens' => $liens,
                'week_ending'   => $weISO,
                'date_issued'   => $toDate($row['date_issued'] ?? null),
                'planter_tin'   => $row['planter_tin'] ?? null,
                'planter_name'  => $planterName,
                'item_no'       => $entry->item_number,
                'mill'          => $entry->mill,
                'unit_cost'     => $unitCost,
                'commission'    => $commission,
                'storage'       => $storage,
                'insurance'     => $insurance,
                'total_ap'      => $totalAP,
                'user_id'       => $entry->user_id,
                'workstation_id'=> $entry->workstation_id,
            ]
        );

        return response()->json([
            'id'           => $detail->id,
            'row'          => $detail->row,
            'planter_tin'  => $detail->planter_tin,
            'planter_name' => $detail->planter_name,

            'item_no'      => $detail->item_no,
            'mill'         => $detail->mill,
            'unit_cost'    => (float)$detail->unit_cost,
            'commission'   => (float)$detail->commission,

            'storage'      => (float)$detail->storage,
            'insurance'    => (float)$detail->insurance,
            'total_ap'     => (float)$detail->total_ap,
        ]);
    } catch (\Throwable $e) {
        Log::error('Receiving batch-insert failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'payload' => $req->all(),
        ]);

        // return something more helpful while debugging
        return response()->json([
            'message' => 'Server Error',
            'debug'   => $e->getMessage(),
        ], 500);
    }
}


public function updateFlag(Request $req)
{
    $companyId = $this->companyIdFromRequest($req);

    $req->validate([
        'receipt_no' => 'required',
        'field'      => 'in:no_storage,no_insurance',
        'value'      => 'required|in:0,1',
    ]);

    $entry = \App\Models\ReceivingEntry::where('company_id', $companyId)
        ->where('receipt_no', $req->receipt_no)
        ->firstOrFail();

    $entry->update([$req->field => (int)$req->value]);

    // ✅ CRITICAL FIX: recompute detail computed fields
    $this->recomputeReceivingDetails($entry->fresh());

    return response()->json(['ok' => true]);
}


public function updateDate(Request $req)
{
    $companyId = $this->companyIdFromRequest($req);

    $req->validate([
        'receipt_no' => 'required',
        'field'      => 'in:storage_week,insurance_week,receipt_date',
    ]);

    $entry = ReceivingEntry::where('company_id', $companyId)
        ->where('receipt_no', $req->receipt_no)
        ->firstOrFail();

    $val = $req->get('value');
    $newDate = $val ? date('Y-m-d', strtotime($val)) : null;

    // ✅ Update header (safe now because $timestamps=false on ReceivingEntry)
    $entry->{$req->field} = $newDate;
    $entry->save();

    // ✅ LEGACY BEHAVIOR:
    // Selecting Storage Week OR Insurance Week forces ALL row.week_ending to that date
    if (in_array($req->field, ['storage_week', 'insurance_week'], true) && $newDate) {
        DB::table('receiving_details')
            ->where('receiving_entry_id', $entry->id)
            ->where('receipt_no', $entry->receipt_no)
            ->update([
                'week_ending' => $newDate,
                'updated_at'  => now(),
            ]);
    }

    // ✅ Recompute after any date change (receipt_date affects months calc)
    $this->recomputeReceivingDetails($entry->fresh());

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
    $companyId = $this->companyIdFromRequest($req);

    $req->validate([
        'receipt_no' => 'required',
        'mill'       => 'required|string',
    ]);

    $entry = ReceivingEntry::where('company_id', $companyId)
        ->where('receipt_no', $req->receipt_no)
        ->firstOrFail();

    $exists = MillList::where('company_id', $companyId)
        ->where('mill_name', $req->mill)
        ->exists();

    if (!$exists) {
        return response()->json(['ok' => false, 'msg' => 'Mill not found'], 422);
    }

    // update header
    $entry->mill = $req->mill;
    $entry->save();

    // ✅ recompute computed fields after mill change
    $this->recomputeReceivingDetails($entry->fresh());

    return response()->json(['ok' => true]);
}


    // --- helpers ---
protected function resolveMillRate(string $millName, ?int $companyId, ?string $cropYear): array
{
    if (!$millName || !$companyId || !$cropYear) {
        return ['insurance_rate'=>0,'storage_rate'=>0,'days_free'=>0];
    }

    // 1) Resolve the mill row in mill_list (company-scoped)
    $mill = MillList::where('mill_name', $millName)
        ->where('company_id', $companyId)
        ->first();

    if (!$mill) return ['insurance_rate'=>0,'storage_rate'=>0,'days_free'=>0];

    // 2) Resolve rate by mill_id + crop_year (no valid_from/valid_to in your schema)
    $rate = MillRateHistory::where('mill_id', $mill->mill_id)
        ->where('crop_year', $cropYear)
        ->orderByDesc('updated_at')
        ->orderByDesc('id')
        ->first();

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



public function pricingContext(Request $req)
{
    $pbnNumber  = (string) $req->get('pbn_number');
    $itemNo     = (string) $req->get('item_no');
    $millName   = (string) $req->get('mill_name');
    $companyId  = (int) ($req->header('X-Company-ID') ?: $req->get('company_id'));

    if (!$pbnNumber || $itemNo === '' || !$millName || !$companyId) {
        return response()->json([
            'unit_cost' => 0, 'commission' => 0,
            'insurance_rate' => 0, 'storage_rate' => 0, 'days_free' => 0,
            'crop_year' => null, 'mill' => $millName,
        ]);
    }

    $pbn = PbnEntry::where('pbn_number', $pbnNumber)
        ->where('company_id', $companyId)
        ->first();

    $cropYear = $pbn?->crop_year ?? null;

    $pbnItem = PbnEntryDetail::query()
        ->where('pbn_number', $pbnNumber)
        ->where('row', $itemNo)
        ->select('unit_cost','commission','mill')
        ->first();

    // prefer mill from pbn detail if present
    $finalMill = (string) ($pbnItem?->mill ?: $millName);

    $rate = $this->resolveMillRateByCropYear($finalMill, $companyId, (string)$cropYear);

    return response()->json([
        'unit_cost'      => (float)($pbnItem?->unit_cost ?? 0),
        'commission'     => (float)($pbnItem?->commission ?? 0),
        'insurance_rate' => (float)($rate['insurance_rate'] ?? 0),
        'storage_rate'   => (float)($rate['storage_rate'] ?? 0),
        'days_free'      => (int)  ($rate['days_free'] ?? 0),
        'crop_year'      => $cropYear,
        'mill'           => $finalMill,
    ]);
}



// =========================
// Helpers: company + recompute
// =========================

private function companyIdFromRequest(\Illuminate\Http\Request $req): int
{
    // Priority: explicit param -> header (axiosnapi usually sets X-Company-ID)
    $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        abort(422, 'Missing company_id (param or X-Company-ID header).');
    }
    return $companyId;
}

private function loadMillRateContext(int $companyId, string $mill, ?string $asOfDate): array
{
    // mill_list table (you gave exact fields)
    $millRow = \Illuminate\Support\Facades\DB::table('mill_list')
        ->where('company_id', $companyId)
        ->where(function ($q) use ($mill) {
            $q->where('mill_name', $mill)
              ->orWhere('mill_id', $mill);
        })
        ->first();

    if (!$millRow) {
        // no mill match -> safe fallback (no rates)
        return ['insurance_rate' => 0, 'storage_rate' => 0, 'days_free' => 0];
    }

    $q = \Illuminate\Support\Facades\DB::table('mill_rate_history')
        ->where('mill_record_id', $millRow->id);

    // choose the rate "as of" receipt_date if possible (created_at <= receipt_date),
    // otherwise fallback to latest record
    if ($asOfDate) {
        $q->whereDate('created_at', '<=', $asOfDate);
    }

    $rate = $q->orderByDesc('created_at')->first();

    if (!$rate) {
        // fallback: latest of all
        $rate = \Illuminate\Support\Facades\DB::table('mill_rate_history')
            ->where('mill_record_id', $millRow->id)
            ->orderByDesc('created_at')
            ->first();
    }

    return [
        'insurance_rate' => (float) ($rate->insurance_rate ?? 0),
        'storage_rate'   => (float) ($rate->storage_rate   ?? 0),
        'days_free'      => (int)   ($rate->days_free      ?? 0),
    ];
}

private function calcMonthsCeil(?string $fromDate, ?string $toDate): int
{
    if (!$fromDate || !$toDate) return 0;

    $from = strtotime($fromDate);
    $to   = strtotime($toDate);
    if (!$from || !$to) return 0;

    $diffDays = max(0, ($to - $from) / 86400);
    return (int) ceil($diffDays / 30);
}

private function calcMonthsFloorStorage(?string $fromDate, ?string $toDate, int $freeDays): int
{
    if (!$fromDate || !$toDate) return 0;

    $from = strtotime($fromDate);
    $to   = strtotime($toDate);
    if (!$from || !$to) return 0;

    $diffDays = max(0, ($to - $from) / 86400);
    $diffDays = max(0, $diffDays - max(0, $freeDays));
    return (int) floor($diffDays / 30);
}

private function recomputeReceivingDetails(ReceivingEntry $entry): void
{
    $ctx = $this->loadMillRateContext((int)$entry->company_id, (string)$entry->mill, $entry->receipt_date);

    $insuranceRate = (float) $ctx['insurance_rate'];
    $storageRate   = (float) $ctx['storage_rate'];
    $daysFree      = (int)   $ctx['days_free'];

    $receiptDate = $entry->receipt_date ? $entry->receipt_date->format('Y-m-d') : null;

    $headerInsWeek = $entry->insurance_week ? date('Y-m-d', strtotime($entry->insurance_week)) : null;
    $headerStoWeek = $entry->storage_week   ? date('Y-m-d', strtotime($entry->storage_week))   : null;

    $details = DB::table('receiving_details')
        ->where('receipt_no', $entry->receipt_no)
        ->where('receiving_entry_id', $entry->id)
        ->get();

    foreach ($details as $d) {
        $qty      = (float) ($d->quantity  ?? 0);
        $unitCost = (float) ($d->unit_cost ?? 0);

        $rowWeek = $d->week_ending ? date('Y-m-d', strtotime($d->week_ending)) : null;

        // ✅ insurance uses insurance_week if present else row week
        $weekForIns = $headerInsWeek ?: $rowWeek;

        // ✅ storage uses storage_week if present else row week
        $weekForSto = $headerStoWeek ?: $rowWeek;

        $ins = 0.0;
        if (!$entry->no_insurance && $weekForIns && $receiptDate) {
            $m = $this->calcMonthsCeil($weekForIns, $receiptDate);
            $ins = $qty * $insuranceRate * $m;
        }

        $sto = 0.0;
        if (!$entry->no_storage && $weekForSto && $receiptDate) {
            $m = $this->calcMonthsFloorStorage($weekForSto, $receiptDate, $daysFree);
            $sto = $qty * $storageRate * $m;
        }

$liens   = (float) ($d->liens ?? 0);
$totalAp = ($qty * $unitCost) - $liens - $sto - $ins;

        DB::table('receiving_details')
            ->where('id', $d->id)
            ->update([
                'insurance' => round($ins, 2),
                'storage'   => round($sto, 2),
                'total_ap'  => round($totalAp, 2),
                'updated_at'=> now(),
            ]);
    }
}


public function millList(Request $req)
{
    try {
        $companyId = $this->companyIdFromRequest($req);
        $q = trim((string) $req->query('q', ''));

        $rows = MillList::query()
            ->select('mill_name')
            ->where('company_id', $companyId)
            ->when($q !== '', function ($qr) use ($q) {
                $qq = strtolower($q);
                $qr->whereRaw('LOWER(mill_name) LIKE ?', ["%{$qq}%"]);
            })
            ->orderBy('mill_name')
            ->limit(200)
            ->get();

        return response()->json($rows);
    } catch (\Throwable $e) {
        Log::error('millList failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'q' => $req->query('q'),
            'company_id_param' => $req->input('company_id'),
            'company_id_header' => $req->header('X-Company-ID'),
        ]);

        return response()->json([
            'message' => 'Server Error',
            // keep this while debugging; remove later if you want
            'debug' => $e->getMessage(),
        ], 500);
    }
}


// App\Http\Controllers\ReceivingController.php



public function receivingReportPdf(Request $req, string $receiptNo)
{
    $companyId = (int) ($req->query('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    // ----------------------------
    // 1) Load header + PBN context
    // ----------------------------
    $entry = ReceivingEntry::query()
        ->where('company_id', $companyId)
        ->where('receipt_no', $receiptNo)
        ->firstOrFail();

    $pbn = PbnEntry::query()
        ->where('company_id', $companyId)
        ->where('pbn_number', $entry->pbn_number)
        ->first();

    $sugarType  = (string) ($pbn?->sugar_type ?? '');
    $cropYear   = (string) ($pbn?->crop_year ?? '');
    $vendorName = (string) ($pbn?->vendor_name ?? '');
    $vendorCode = (string) ($pbn?->vend_code ?? '');

    // Legacy "yearPrefix" is last 2 digits of PBN date (mm/dd/yyyy)
    // New system: safest equivalent is last 2 digits of crop_year if present, else receipt year
    $yearPrefix = '';
    if ($cropYear !== '') {
        $yearPrefix = substr($cropYear, -2);
    } else {
        $yearPrefix = date('y', strtotime((string)$entry->receipt_date));
    }

    $pbnNo     = (string) $entry->pbn_number;
    $pbnDate   = $pbn?->pbn_date ? date('m/d/Y', strtotime($pbn->pbn_date)) : ''; // if your pbn_entry has pbn_date
    $receiptDt = $entry->receipt_date ? date('m/d/Y', strtotime((string)$entry->receipt_date)) : '';

    // ----------------------------
    // 2) Control no (new system)
    // ----------------------------
    // Uses application_settings row:
    //   appset_code = 'ReceivingReportControlNum'
    //   company_id  = ?
    //   type        = sugarType (optional but matches legacy style)
    $controlNo = $this->nextReceivingReportControlNo($companyId, $sugarType);

    // ----------------------------
    // 3) Load details (quedan lines)
    // ----------------------------
    $details = ReceivingDetail::query()
        ->where('receiving_entry_id', $entry->id)
        ->where('receipt_no', $entry->receipt_no)
        ->orderBy('row')
        ->get();

    // ----------------------------
    // 4) Totals (legacy-style)
    // ----------------------------
    // In legacy table header, it shows ONE line per RR No (receiptNo).
    // Your new module is already per receipt_no, so we summarize all details into ONE RR row.
    $totalQty   = 0.0;
    $totalLiens = 0.0;
    $unitCost   = (float)($details->first()?->unit_cost ?? 0);

    foreach ($details as $d) {
        $totalQty   += (float)$d->quantity;
        $totalLiens += (float)$d->liens;
    }

    // Determine rates (company + mill + crop_year)
    $rate = $this->resolveMillRateByCropYear((string)$entry->mill, (int)$entry->company_id, (string)$cropYear);
    $insuranceRate = $entry->no_insurance ? 0.0 : (float)($rate['insurance_rate'] ?? 0);
    $storageRate   = $entry->no_storage   ? 0.0 : (float)($rate['storage_rate'] ?? 0);
    $daysFree      = (int) ($rate['days_free'] ?? 0);

    // Recompute Insurance/Storage exactly like your legacy report:
    // legacy loops each detail row and sums insurance+storage based on receiptDate vs weekEnding
    $receiptISO = $entry->receipt_date ? date('Y-m-d', strtotime((string)$entry->receipt_date)) : null;

    $totalInsurance = 0.0;
    $totalStorage   = 0.0;
    $totalAssocDues = 0.0;

    foreach ($details as $d) {
        $qty = (float)$d->quantity;

        $rowWeekISO = $d->week_ending ? date('Y-m-d', strtotime((string)$d->week_ending)) : null;

        $insWeekISO = $entry->insurance_week
            ? date('Y-m-d', strtotime((string)$entry->insurance_week))
            : $rowWeekISO;

        $stoWeekISO = $entry->storage_week
            ? date('Y-m-d', strtotime((string)$entry->storage_week))
            : $rowWeekISO;

        if (!$entry->no_insurance && $insWeekISO && $receiptISO) {
            $monthsIns = $this->monthsCeil($insWeekISO, $receiptISO);
            $totalInsurance += ($qty * $insuranceRate * $monthsIns);
        }

        if (!$entry->no_storage && $stoWeekISO && $receiptISO) {
            $monthsSto = $this->monthsFloorStorage($stoWeekISO, $receiptISO, $daysFree);
            $totalStorage += ($qty * $storageRate * $monthsSto);
        }
    }

    // If you store assoc_dues in header (receiving_entry.assoc_dues), use that.
    $totalAssocDues = (float)($entry->assoc_dues ?? 0);

    $totalCost = $unitCost * $totalQty;
    $totalAP   = $totalCost - ($totalLiens + $totalInsurance + $totalStorage);

    // Withholding Tax: 1% of COST, truncated (floor to 2 decimals)
    $withHoldingTax = $totalCost * 0.01;
    $withHoldingTaxV = floor($withHoldingTax * 100) / 100;

    $netAP = $totalAP - ($withHoldingTax + $totalAssocDues);

    // Formatting
    $fmt2 = fn($n) => number_format((float)$n, 2);

    $totalQtyV       = $fmt2($totalQty);
    $unitCostV       = $fmt2($unitCost);
    $totalCostV      = $fmt2($totalCost);
    $totalLiensV     = $fmt2($totalLiens);
    $totalInsV       = $fmt2($totalInsurance);
    $totalStoV       = $fmt2($totalStorage);
    $totalAPV        = $fmt2($totalAP);
    $assocDuesV      = $fmt2($totalAssocDues);
    $withHoldingTaxV = $fmt2($withHoldingTaxV);
    $netAPV          = $fmt2($netAP);

    // ----------------------------
    // 5) Account mapping (legacy)
    // ----------------------------
    $inventoryAcct = match ($sugarType) {
        'A' => '1201',
        'B' => '1203',
        'C' => '1202',
        'D' => '1204',
        default => '1201',
    };

    $liensPayAcct = match ($sugarType) {
        'A' => '3031',
        'B' => '3033',
        'C' => '3032',
        default => '3031',
    };
    $insurancePayAcct = match ($sugarType) {
        'A' => '3041',
        'B' => '3043',
        'C' => '3042',
        default => '3041',
    };
    $storagePayAcct = match ($sugarType) {
        'A' => '3051',
        'B' => '3053',
        'C' => '3052',
        default => '3051',
    };

    $withHoldingTaxAcct = '3074';
    $assocDueAcct       = '1401';
    $apAcct             = '3023';

    // fetch descriptions from account_code.acct_code
    $acctDesc = function (string $acct) use ($companyId) {
        $row = DB::table('account_code')
            ->where('acct_code', $acct)
            ->first();
        return (string)($row->acct_desc ?? '');
    };

    $inventoryDesc = $acctDesc($inventoryAcct);
    $liensDesc     = $acctDesc($liensPayAcct);
    $insDesc       = $acctDesc($insurancePayAcct);
    $stoDesc       = $acctDesc($storagePayAcct);
    $whtDesc       = $acctDesc($withHoldingTaxAcct);
    $assocDesc     = $acctDesc($assocDueAcct);
    $apDesc        = $acctDesc($apAcct);

    // ----------------------------
    // 6) TCPDF (legacy layout)
    // ----------------------------
    // NOTE: adjust this path to your actual logo location in Laravel
// ----------------------------
// 6) TCPDF (legacy layout) + LOGO
// ----------------------------

// Try common logo locations (supports jpg/png)
// ----------------------------
// 6) TCPDF (legacy layout) + LOGO (company-based)
// ----------------------------

// company_id=1 => sucdenLogo
// company_id=2 => ameropLogo
$logoCandidates = match ((int)$companyId) {
    1 => [
        public_path('sucdenLogo.jpg'),
        public_path('sucdenLogo.png'),
        public_path('images/sucdenLogo.jpg'),
        public_path('images/sucdenLogo.png'),
    ],
    2 => [
        public_path('ameropLogo.jpg'),
        public_path('ameropLogo.png'),
        public_path('images/ameropLogo.jpg'),
        public_path('images/ameropLogo.png'),
    ],
    default => [
        public_path('sucdenLogo.jpg'),
        public_path('sucdenLogo.png'),
        public_path('images/sucdenLogo.jpg'),
        public_path('images/sucdenLogo.png'),
    ],
};

$logoPath = '';
foreach ($logoCandidates as $p) {
    if (is_file($p)) { $logoPath = $p; break; }
}


// Custom PDF class for header + last-page footer
$pdf = new class($logoPath) extends \TCPDF {
    private bool $last_page_flag = false;
    private string $logoPath;

    public function __construct(string $logoPath)
    {
        $this->logoPath = $logoPath;
        parent::__construct('P', 'mm', 'LETTER', true, 'UTF-8', false);
    }

    public function Close(): void
    {
        $this->last_page_flag = true;
        parent::Close();
    }

    public function Header(): void
    {
        if ($this->logoPath && is_file($this->logoPath)) {
            $ext  = strtolower(pathinfo($this->logoPath, PATHINFO_EXTENSION));
            $type = ($ext === 'png') ? 'PNG' : 'JPG';

            // Same coordinates as legacy/PBN feel
            $this->Image($this->logoPath, 15, 10, 50, 0, $type, '', 'T', false, 200);
        }
    }

    public function Footer(): void
    {
        if (!$this->last_page_flag) return;

        $this->SetY(-40);
        $this->SetFont('helvetica', 'I', 8);

        $currentDate = date('M d, Y');
        $currentTime = date('h:i');

        $preparedBy = session('userName', '');
        $checkedBy  = session('checkedBy', '');
        $notedBy    = session('notedBy', '');
        $postedBy   = session('postedBy', '');
        $encodedBy  = session('encodedBy', '');

        $html = '';
        $html .= '<table border="0">';
        $html .= '  <tr>';
        $html .= '    <td width="28%">';
        $html .= '      <table border="0" cellpadding="5">';
        $html .= '        <tr><td align="left"><font size="6"><br><br><br></font></td></tr>';
        $html .= '        <tr><td><font size="7">APV #________<br>Print Date: '.$currentDate.' '.$currentTime.'</font></td></tr>';
        $html .= '      </table>';
        $html .= '    </td>';
        $html .= '    <td width="2%"></td>';
        $html .= '    <td width="70%">';
        $html .= '      <table border="1" cellpadding="5">';
        $html .= '        <tr>';
        $html .= '          <td><font size="8">Encoded by:<br><br><br><br><br>'.$encodedBy.'</font></td>';
        $html .= '          <td><font size="8">Prepared by:<br><br><br><br><br>'.$preparedBy.'</font></td>';
        $html .= '          <td><font size="8">Checked by:<br><br><br><br><br>'.$checkedBy.'</font></td>';
        $html .= '          <td><font size="8">Noted by:<br><br><br><br><br>'.$notedBy.'</font></td>';
        $html .= '          <td><font size="8">Posted by:<br><br><br><br><br>'.$postedBy.'</font></td>';
        $html .= '        </tr>';
        $html .= '      </table>';
        $html .= '    </td>';
        $html .= '  </tr>';
        $html .= '</table>';

        $this->writeHTML($html, true, false, false, false, '');
    }
};

    // PDF setup similar to legacy
    $pdf->SetMargins(15, 27, 15);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(15);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->AddPage('P', 'LETTER');
    $pdf->SetFont('helvetica', '', 7);

    // Title block (legacy-like)
    $controlDisplay = htmlspecialchars($sugarType.$yearPrefix.'-'.$controlNo);

    $tbl  = '<br><br>';
    $tbl .= '<table border="0" cellpadding="1" cellspacing="0" nobr="true" width="100%">';
    $tbl .= '  <tr>
                <td width="15%"></td>
                <td width="30%"></td>
                <td width="20%"></td>
                <td width="40%" colspan="2"><div><font size="14"><b>Receiving Report</b></font></div></td>
              </tr>';
    $tbl .= '  <tr><td colspan="5"></td></tr>';

    $tbl .= '  <tr>
                <td width="15%"><font size="8">PBN No:</font></td>
                <td width="30%"><font size="8">'.htmlspecialchars($pbnNo).'</font></td>
                <td width="20%"></td>
                <td width="35%" colspan="2">
                    <font size="8">Control No:</font>
                    <font size="14" color="blue"><b><u>               '.$controlDisplay.'</u></b></font><br>
                </td>
              </tr>';

    $tbl .= '  <tr>
                <td width="15%"><font size="8">PBN Date:</font></td>
                <td width="30%"><font size="8">'.htmlspecialchars($pbnDate).'</font></td>
                <td width="20%"></td>
                <td width="15%"><font size="8">Receipt Date:</font></td>
                <td width="20%"><font size="8">'.htmlspecialchars($receiptDt).'</font></td>
              </tr>';

    $tbl .= '  <tr>
                <td width="15%"><font size="8">Coop/Supplier:</font></td>
                <td width="30%"><font size="8">'.htmlspecialchars($vendorName).'</font></td>
                <td width="20%"></td>
                <td width="15%"><font size="8">Sugar Type:</font></td>
                <td width="20%"><font size="8">'.htmlspecialchars($sugarType).'</font></td>
              </tr>';

    $tbl .= '  <tr>
                <td width="15%"><font size="8">Trader:</font></td>
                <td width="30%"><font size="8">'.htmlspecialchars($vendorCode).'</font></td>
                <td width="20%"></td>
                <td width="15%"></td>
                <td width="20%"></td>
              </tr>';
    $tbl .= '</table>';

    // Detail table header (legacy)
    $tbl .= '<table border="0" cellpadding="0" cellspacing="0" nobr="true" width="100%">';
    $tbl .= '  <tr><td colspan="10"><hr height="2px"></td></tr>';
    $tbl .= '  <tr align="left">
                <td width="12%"><font size="8">RR No:</font></td>
                <td width="11%"><font size="8">Mill</font></td>
                <td width="11%" align="center"><font size="8">LKG</font></td>
                <td width="11%" align="center"><font size="8">Unit Cost</font></td>
                <td width="11%" align="center"><font size="8">Cost</font></td>
                <td width="11%" align="center"><font size="8">Liens</font></td>
                <td width="11%" align="center"><font size="8">Insurance</font></td>
                <td width="11%" align="center"><font size="8">Storage</font></td>
                <td width="11%" align="center"><font size="8">AP</font></td>
              </tr>';
    $tbl .= '  <tr><td colspan="10"><hr height="1px"></td></tr>';

    // Single summary row (new module = one receipt_no)
    $tbl .= '  <tr>
                <td align="left">'.htmlspecialchars($entry->receipt_no).'</td>
                <td align="left">'.htmlspecialchars((string)$entry->mill).'</td>
                <td align="right">'.$totalQtyV.'</td>
                <td align="right">'.$unitCostV.'</td>
                <td align="right">'.$totalCostV.'</td>
                <td align="right">'.$totalLiensV.'</td>
                <td align="right">'.$totalInsV.'</td>
                <td align="right">'.$totalStoV.'</td>
                <td align="right">'.$totalAPV.'</td>
              </tr>';

    // Totals underline section (legacy look)
    $tbl .= '  <tr>
                <td></td><td></td>
                <td align="right"><hr></td>
                <td></td>
                <td align="right"><hr></td>
                <td align="right" colspan="4"><hr></td>
              </tr>';

    $tbl .= '  <tr>
                <td align="left"></td>
                <td align="left"></td>
                <td align="right">'.$totalQtyV.'</td>
                <td align="right"></td>
                <td align="right">'.$totalCostV.'</td>
                <td align="right">'.$totalLiensV.'</td>
                <td align="right">'.$totalInsV.'</td>
                <td align="right">'.$totalStoV.'</td>
                <td align="right">'.$totalAPV.'</td>
              </tr>';

    $tbl .= '  <tr>
                <td></td><td></td>
                <td align="right"><hr height="2px"></td>
                <td></td>
                <td align="right"><hr height="2px"></td>
                <td align="right" colspan="4"><hr height="2px"></td>
              </tr>';

    // Summary box (legacy)
    $tbl .= '  <tr><td align="right" colspan="9"><br></td></tr>';

    $tbl .= '  <tr><td colspan="9">
                <table cellspacing="2" cellpadding="2">
                  <tr>
                    <td colspan="7"></td>
                    <td align="left"><font size="8">Assoc Due</font></td>
                    <td align="right"></td>
                    <td align="right"><font size="8">'.$assocDuesV.'</font></td>
                  </tr>
                  <tr>
                    <td colspan="7"></td>
                    <td align="left"><font size="8">Insurance</font></td>
                    <td align="right"></td>
                    <td align="right"><font size="8">'.$totalInsV.'</font></td>
                  </tr>
                  <tr>
                    <td colspan="7"></td>
                    <td align="left"><font size="8">Storage</font></td>
                    <td align="right"></td>
                    <td align="right"><font size="8">'.$totalStoV.'</font></td>
                  </tr>
                  <tr>
                    <td colspan="7"></td>
                    <td align="left" colspan="2"><font size="8">Withholding Tax%</font></td>
                    <td align="right"><font size="8">'.$withHoldingTaxV.'</font></td>
                  </tr>
                  <tr>
                    <td colspan="7"></td>
                    <td align="left"></td>
                    <td align="right" colspan="2"><hr></td>
                  </tr>
                  <tr>
                    <td colspan="7"></td>
                    <td align="left"><font size="8">Net AP</font></td>
                    <td align="right"></td>
                    <td align="right"><font size="8">'.$netAPV.'</font></td>
                  </tr>
                  <tr>
                    <td colspan="7"></td>
                    <td align="left"></td>
                    <td align="right" colspan="2"><hr height="2px"></td>
                  </tr>
                </table>
              </td></tr>';

    $tbl .= '  <tr><td colspan="10">Note: Quedan Listings Attached</td></tr>';
    $tbl .= '  <tr><td colspan="10"><br><br></td></tr>';

    // Accounting entries (legacy block)
    $tbl .= '  <tr>
                <td colspan="7">
                  <table border="1">
                    <tr>
                      <td>
                        <table cellpadding="2">
                          <tr>
                            <td width="15%"><font size="8">Account</font></td>
                            <td width="35%"><font size="8">Description</font></td>
                            <td width="20%" align="center"><font size="8">Debit</font></td>
                            <td width="20%" align="center"><font size="8">Credit</font></td>
                          </tr>

                          <tr>
                            <td><font size="8">'.$inventoryAcct.'</font></td>
                            <td><font size="8">'.htmlspecialchars($inventoryDesc).'</font></td>
                            <td align="right"><font size="8">'.$totalCostV.'</font></td>
                            <td align="right"><font size="8"></font></td>
                          </tr>

                          <tr>
                            <td><font size="8">'.$liensPayAcct.'</font></td>
                            <td><font size="8">'.htmlspecialchars($liensDesc).'</font></td>
                            <td align="right"><font size="8"></font></td>
                            <td align="right"><font size="8">'.$totalLiensV.'</font></td>
                          </tr>

                          <tr>
                            <td><font size="8">'.$insurancePayAcct.'</font></td>
                            <td><font size="8">'.htmlspecialchars($insDesc).'</font></td>
                            <td align="right"><font size="8"></font></td>
                            <td align="right"><font size="8">'.$totalInsV.'</font></td>
                          </tr>

                          <tr>
                            <td><font size="8">'.$storagePayAcct.'</font></td>
                            <td><font size="8">'.htmlspecialchars($stoDesc).'</font></td>
                            <td align="right"><font size="8"></font></td>
                            <td align="right"><font size="8">'.$totalStoV.'</font></td>
                          </tr>

                          <tr>
                            <td><font size="8">'.$withHoldingTaxAcct.'</font></td>
                            <td><font size="8">'.htmlspecialchars($whtDesc).'</font></td>
                            <td align="right"><font size="8"></font></td>
                            <td align="right"><font size="8">'.$withHoldingTaxV.'</font></td>
                          </tr>

                          <tr>
                            <td><font size="8">'.$assocDueAcct.'</font></td>
                            <td><font size="8">'.htmlspecialchars($assocDesc).'</font></td>
                            <td align="right"><font size="8">'.$assocDuesV.'</font></td>
                            <td align="right"><font size="8"></font></td>
                          </tr>

                          <tr>
                            <td><font size="8">'.$apAcct.'</font></td>
                            <td><font size="8">'.htmlspecialchars($apDesc).'</font></td>
                            <td align="right"><font size="8"></font></td>
                            <td align="right"><font size="8">'.$netAPV.'</font></td>
                          </tr>

                        </table>
                      </td>
                    </tr>
                  </table>
                </td>
                <td colspan="3"></td>
              </tr>';

    $tbl .= '</table>';

    $pdf->writeHTML($tbl, true, false, false, false, '');

    $pdfBytes = $pdf->Output('', 'S'); // return as string

    $fileName = "receiving-report-{$entry->receipt_no}.pdf";

    return response($pdfBytes, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="'.$fileName.'"')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache');
}

/**
 * Generate control no like legacy _generateIDNumberMaximum999 (zero-pad to 3)
 * and store/advance a counter in application_settings.
 */
private function nextReceivingReportControlNo(int $companyId, string $sugarType): string
{
    $codeCol  = Schema::hasColumn('application_settings', 'apset_code') ? 'apset_code' : 'appset_code';
    $valueCol = Schema::hasColumn('application_settings', 'value')
        ? 'value'
        : (Schema::hasColumn('application_settings', 'apset_value') ? 'apset_value' : null);

    if (!$valueCol) {
        // fallback if schema is unexpected
        return str_pad('1', 3, '0', STR_PAD_LEFT);
    }

    return DB::transaction(function () use ($companyId, $sugarType, $codeCol, $valueCol) {
        $row = DB::table('application_settings')
            ->where($codeCol, 'ReceivingReportControlNum')
            ->where('company_id', $companyId)
            ->when($sugarType !== '', fn($q) => $q->where('type', $sugarType))
            ->lockForUpdate()
            ->first();

        if (!$row) {
            // create seed row if missing
            $id = DB::table('application_settings')->insertGetId([
                $codeCol      => 'ReceivingReportControlNum',
                $valueCol     => 1,
                'company_id'  => $companyId,
                'type'        => $sugarType,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $num = 1;
        } else {
            $current = (int)($row->{$valueCol} ?? 0);
            $num = $current + 1;
            DB::table('application_settings')->where('id', $row->id)->update([
                $valueCol    => $num,
                'updated_at' => now(),
            ]);
        }

        // legacy: maximum 999 formatting
        if ($num > 999) $num = $num % 1000;
        if ($num === 0) $num = 1;

        return str_pad((string)$num, 3, '0', STR_PAD_LEFT);
    });
}



// =========================
// Quedan Listing - PDF
// =========================
public function quedanListingPdf(Request $req, string $receiptNo)
{
    $companyId = (int) ($req->query('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    // 1) Load receiving entry (scoped)
    $entry = ReceivingEntry::query()
        ->where('company_id', $companyId)
        ->where('receipt_no', $receiptNo)
        ->firstOrFail();

    // 2) Load PBN context (scoped)
    $pbn = PbnEntry::query()
        ->where('company_id', $companyId)
        ->where('pbn_number', $entry->pbn_number)
        ->first();

    $sugarType = strtoupper((string)($pbn?->sugar_type ?? ''));
    $cropYearRaw = (string)($pbn?->crop_year ?? '');

    // legacy-like crop year display (CY2022-2023)
    $cropYearDisplay = $cropYearRaw;
    if ($cropYearDisplay !== '' && strpos($cropYearDisplay, '-') === false) {
        // if numeric year like "2022", show "2022-2023"
        if (preg_match('/^\d{4}$/', $cropYearDisplay)) {
            $cropYearDisplay = $cropYearDisplay . '-' . ((int)$cropYearDisplay + 1);
        }
    }

    // 3) Load receiving details (quedan lines)
    $rows = ReceivingDetail::query()
        ->where('receiving_entry_id', $entry->id)
        ->where('receipt_no', $entry->receipt_no)
        ->orderBy('row', 'asc')
        ->get();

    // 4) Resolve mill prefix + millmark (legacy gets from PBN detail + mill_list)
    // We’ll try: mill_name from entry->mill; prefix from mill_list (company-scoped)
    $millName = (string)($entry->mill ?? '');
    $millRow = MillList::query()
        ->where('company_id', $companyId)
        ->where('mill_name', $millName)
        ->first();

    $prefix   = (string)($millRow->prefix ?? '');
    $millMark = $prefix !== '' ? $prefix : $millName; // legacy shows "BISCOM" etc; adjust if your prefix is separate

    // 5) Header text by company_id
    $shipper = 'SUCDEN PHILIPPINES, INC.';
    $buyer   = ($companyId === 2) ? 'AMEROP AMERICAS CORP' : 'SUCDEN AMERICAS CORP';
    $currentDate = date('M d, Y');

    // 6) TCPDF setup (no header/footer like legacy)
    if (!class_exists('\TCPDF', false)) {
        $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
        if (file_exists($tcpdfPath)) require_once $tcpdfPath;
        else abort(500, 'TCPDF not installed. Run: composer require tecnickcom/tcpdf');
    }

    $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);

    // legacy-ish margins
    $pdf->SetMargins(3, 5, 3);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage('P', 'LETTER');
    $pdf->SetFont('helvetica', '', 7);

    // 7) Build HTML with legacy paging logic (50 rows/page)
    $tbl = '';
    $tbl .= '<br><br>';
    $tbl .= '<table border="0" cellpadding="0" cellspacing="1" nobr="true" width="100%">';
    $tbl .= '  <tr>
                <td colspan="8"><font size="8">'
                .'Shipper:  '.htmlspecialchars($shipper, ENT_QUOTES, 'UTF-8').'<br>'
                .'Buyer:  '.htmlspecialchars($buyer, ENT_QUOTES, 'UTF-8').'<br>'
                .'Quedan Listings (CY'.htmlspecialchars($cropYearDisplay, ENT_QUOTES, 'UTF-8').')<br>'
                .htmlspecialchars($currentDate, ENT_QUOTES, 'UTF-8').'<br>'
                .'RR No.:  '.htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8').'<br>'
                .'</font></td>
              </tr>';

    $tbl .= '  <tr align="center">
                <td width="15%"><font size="8">MillMark</font></td>
                <td width="10%"><font size="8">Quedan No.</font></td>
                <td width="8%"><font size="8">Quantity</font></td>
                <td width="8%"><font size="8">Liens</font></td>
                <td width="8%"><font size="7">Week Ending</font></td>
                <td width="8%"><font size="8">Date Issued</font></td>
                <td width="10%"><font size="8">TIN</font></td>
                <td width="33%" align="left"><font size="8">PLANTER</font></td>
              </tr>';

    $grandQty  = 0.0;
    $grandLiens= 0.0;

    $pageQty   = 0.0;
    $pageLiens = 0.0;
    $ctr       = 0;
    $pcs       = 0;
    $totalPcs  = 0;

    $formatDateMDY = function ($v) {
        if (!$v) return '';
        try { return date('m/d/Y', strtotime((string)$v)); } catch (\Throwable $e) { return ''; }
    };

    $formatQuedanNo = function ($raw) use ($sugarType, $prefix) {
        $raw = trim((string)$raw);

        // if already formatted like "B26-000123" keep it
        if (preg_match('/^[A-Z].+-\d+$/', $raw)) {
            return $raw;
        }

        // otherwise extract digits and pad to 6
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') $digits = '0';
        $num = (int)$digits;
        $pad = str_pad((string)$num, 6, '0', STR_PAD_LEFT);
        return $sugarType . $prefix . '-' . $pad;
    };

    foreach ($rows as $r) {
        $qty   = (float)($r->quantity ?? 0);
        $liens = (float)($r->liens ?? 0);

        $quedanNo = $formatQuedanNo($r->quedan_no ?? '');
        $weekEnding = $formatDateMDY($r->week_ending);
        $dateIssued = $formatDateMDY($r->date_issued);

        $planterTIN  = (string)($r->planter_tin ?? '');
        $planterName = (string)($r->planter_name ?? '');

        $ctr++;
        $pcs = $ctr;
        $totalPcs++;

        $pageQty   += $qty;
        $pageLiens += $liens;

        $grandQty   += $qty;
        $grandLiens += $liens;

        $tbl .= '<tr>
            <td align="center"><font size="8">'.htmlspecialchars($millMark).'</font></td>
            <td align="center"><font size="8">'.htmlspecialchars($quedanNo).'</font></td>
            <td align="right"><font size="8">'.number_format($qty, 2).'</font></td>
            <td align="right"><font size="8">'.number_format($liens, 2).'</font></td>
            <td align="center"><font size="8">'.htmlspecialchars($weekEnding).'</font></td>
            <td align="center"><font size="8">'.htmlspecialchars($dateIssued).'</font></td>
            <td align="center"><font size="8">'.htmlspecialchars($planterTIN).'</font></td>
            <td align="left"><font size="7">'.htmlspecialchars($planterName).'</font></td>
        </tr>';

        // legacy: page total every 50 rows
        if (($ctr % 50) === 0) {
            $tbl .= '<tr>
                <td align="right"><font size="8">PAGE TOTAL:</font></td>
                <td align="right"><font size="8">'.(int)$pcs.' PCS.</font></td>
                <td align="right"><font size="8">'.number_format($pageQty, 2).'</font></td>
                <td align="right"><font size="8">'.number_format($pageLiens, 2).'</font></td>
                <td align="right" colspan="4"></td>
            </tr>';

            $tbl .= '<br pagebreak="true"/>';

            // repeat header + column headers after page break
            $tbl .= '<tr><td colspan="8"></td></tr>';
            $tbl .= '<tr>
                <td colspan="8"><font size="8">'
                .'Shipper:  '.htmlspecialchars($shipper, ENT_QUOTES, 'UTF-8').'<br>'
                .'Buyer:  '.htmlspecialchars($buyer, ENT_QUOTES, 'UTF-8').'<br>'
                .'Quedan Listings (CY'.htmlspecialchars($cropYearDisplay, ENT_QUOTES, 'UTF-8').')<br>'
                .htmlspecialchars($currentDate, ENT_QUOTES, 'UTF-8').'<br>'
                .'RR No.:  '.htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8').'<br>'
                .'</font></td>
            </tr>';

            $tbl .= '<tr align="center">
                <td width="15%"><font size="8">MillMark</font></td>
                <td width="10%"><font size="8">Quedan No.</font></td>
                <td width="8%"><font size="8">Quantity</font></td>
                <td width="8%"><font size="8">Liens</font></td>
                <td width="8%"><font size="7">Week Ending</font></td>
                <td width="8%"><font size="8">Date Issued</font></td>
                <td width="10%"><font size="8">TIN</font></td>
                <td width="33%" align="left"><font size="8">PLANTER</font></td>
            </tr>';

            // reset page totals
            $pageQty = 0.0;
            $pageLiens = 0.0;
            $ctr = 0;
        }
    }

    // final page total + grand total
    $tbl .= '<tr>
        <td align="right"><font size="8">PAGE TOTAL:</font></td>
        <td align="right"><font size="8">'.(int)$pcs.' PCS.</font></td>
        <td align="right"><font size="8">'.number_format($pageQty, 2).'</font></td>
        <td align="right"><font size="8">'.number_format($pageLiens, 2).'</font></td>
        <td align="right" colspan="4"></td>
    </tr>';

    $tbl .= '<tr>
        <td align="right"><font size="8">GRAND TOTAL:</font></td>
        <td align="right"><font size="8">'.(int)$totalPcs.' PCS.</font></td>
        <td align="right"><font size="8">'.number_format($grandQty, 2).'</font></td>
        <td align="right"><font size="8">'.number_format($grandLiens, 2).'</font></td>
        <td align="right" colspan="4"></td>
    </tr>';

    $tbl .= '</table>';

    $pdf->writeHTML($tbl, true, false, false, false, '');

    $pdfBytes = $pdf->Output('', 'S');
    $fileName = "quedan-listing-{$receiptNo}.pdf";

    return response($pdfBytes, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="'.$fileName.'"')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache');
}




// =========================
// Quedan Listing Insurance/Storage - PDF
// =========================
public function quedanListingInsStoPdf(Request $req, string $receiptNo)
{
    $companyId = (int) ($req->query('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    // 1) Load receiving entry (scoped)
    $entry = ReceivingEntry::query()
        ->where('company_id', $companyId)
        ->where('receipt_no', $receiptNo)
        ->firstOrFail();

    // 2) Load PBN context (scoped)
    $pbn = PbnEntry::query()
        ->where('company_id', $companyId)
        ->where('pbn_number', $entry->pbn_number)
        ->first();

    $sugarType   = strtoupper((string)($pbn?->sugar_type ?? ''));
    $cropYearRaw = (string)($pbn?->crop_year ?? '');

    // legacy-like crop year display (CY2022-2023)
    $cropYearDisplay = $cropYearRaw;
    if ($cropYearDisplay !== '' && strpos($cropYearDisplay, '-') === false) {
        if (preg_match('/^\d{4}$/', $cropYearDisplay)) {
            $cropYearDisplay = $cropYearDisplay . '-' . ((int)$cropYearDisplay + 1);
        }
    }

    // 3) Load receiving details (quedan lines)
    $rows = ReceivingDetail::query()
        ->where('receiving_entry_id', $entry->id)
        ->where('receipt_no', $entry->receipt_no)
        ->orderBy('row', 'asc')
        ->get();

    // 4) Resolve mill prefix + millmark (same approach as regular listing)
    $millName = (string)($entry->mill ?? '');
    $millRow = MillList::query()
        ->where('company_id', $companyId)
        ->where('mill_name', $millName)
        ->first();

    $prefix   = (string)($millRow->prefix ?? '');
    $millMark = $prefix !== '' ? $prefix : $millName;

    // 5) Header company text (legacy)
    if ($companyId === 2) {
        $shipper = 'AMEROP PHILIPPINES, INC.';
        $buyer   = 'AMEROP AMERICAS CORP';
    } else {
        $shipper = 'SUCDEN PHILIPPINES, INC.';
        $buyer   = 'SUCDEN AMERICAS CORP';
    }

    $currentDate = date('M d, Y');

    // 6) Resolve rates by crop_year (matches your existing logic)
    $rate = $this->resolveMillRateByCropYear($millName, $companyId, (string)$cropYearRaw);
    $insuranceRate = $entry->no_insurance ? 0.0 : (float)($rate['insurance_rate'] ?? 0);
    $storageRate   = $entry->no_storage   ? 0.0 : (float)($rate['storage_rate'] ?? 0);
    $daysFree      = (int)  ($rate['days_free'] ?? 0);

    // legacy uses receiptDate vs row.weekEnding
    $receiptDateISO = $entry->receipt_date ? date('Y-m-d', strtotime((string)$entry->receipt_date)) : null;

    // 7) TCPDF setup (same as regular listing)
    if (!class_exists('\TCPDF', false)) {
        $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
        if (file_exists($tcpdfPath)) require_once $tcpdfPath;
        else abort(500, 'TCPDF not installed. Run: composer require tecnickcom/tcpdf');
    }

    $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetMargins(3, 5, 3);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage('P', 'LETTER');
    $pdf->SetFont('helvetica', '', 7);

    // ---- helpers ----
    $formatDateMDY = function ($v) {
        if (!$v) return '';
        try { return date('m/d/Y', strtotime((string)$v)); } catch (\Throwable $e) { return ''; }
    };

    // ✅ IMPORTANT: legacy Ins/Storage uses: prefix-000001 (NO sugarType)
    $formatQuedanNoInsSto = function ($raw) use ($prefix) {
        $raw = trim((string)$raw);

        // If already formatted "BISCOM-000001" or "XX-000001", keep it
        if (preg_match('/^[A-Z0-9]+-\d{1,}$/', $raw)) {
            return $raw;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') $digits = '0';
        $num = (int)$digits;

        // legacy pad to 6
        $pad = str_pad((string)$num, 6, '0', STR_PAD_LEFT);
        return $prefix . '-' . $pad;
    };

    $monthsCeilLegacy = function (?string $receiptISO, ?string $weekEndingMDY): int {
        if (!$receiptISO || !$weekEndingMDY) return 0;
        $r = strtotime($receiptISO);
        $w = strtotime($weekEndingMDY);
        if (!$r || !$w) return 0;
        $days = (new \DateTime(date('Y-m-d', $r)))->diff(new \DateTime(date('Y-m-d', $w)))->days;
        return (int) ceil($days / 30);
    };

    $monthsFloorStorageLegacy = function (?string $receiptISO, ?string $weekEndingMDY, int $daysFree): int {
        if (!$receiptISO || !$weekEndingMDY) return 0;
        $r = strtotime($receiptISO);
        $w = strtotime($weekEndingMDY);
        if (!$r || !$w) return 0;
        $days = (new \DateTime(date('Y-m-d', $r)))->diff(new \DateTime(date('Y-m-d', $w)))->days;
        $days -= $daysFree;
        if ($days < 0) $days = 0;
        return (int) floor($days / 30);
    };

    // 8) Build HTML with legacy paging logic (50 rows/page)
    $renderHeader = function () use ($shipper, $buyer, $cropYearDisplay, $currentDate, $receiptNo) {
        return '
        <tr>
          <td colspan="10"><font size="8">
            Shipper:  '.htmlspecialchars($shipper, ENT_QUOTES, 'UTF-8').'<br>
            Buyer:  '.htmlspecialchars($buyer, ENT_QUOTES, 'UTF-8').'<br>
            Quedan Listings (CY'.htmlspecialchars($cropYearDisplay, ENT_QUOTES, 'UTF-8').')<br>
            '.htmlspecialchars($currentDate, ENT_QUOTES, 'UTF-8').'<br>
            RR No.:  '.htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8').'<br>
          </font></td>
        </tr>
        <tr align="center">
          <td width="15%"><font size="8">MillMark</font></td>
          <td width="10%"><font size="8">Quedan No.</font></td>
          <td width="8%"><font size="8">Quantity</font></td>
          <td width="8%"><font size="8">Liens</font></td>
          <td width="8%"><font size="8">Insurance</font></td>
          <td width="8%"><font size="8">Storage</font></td>
          <td width="8%"><font size="7">Week Ending</font></td>
          <td width="8%"><font size="8">Date Issued</font></td>
          <td width="10%"><font size="8">TIN</font></td>
          <td width="17%" align="left"><font size="8">PLANTER</font></td>
        </tr>
        ';
    };

    $tbl  = '<br><br>';
    $tbl .= '<table border="0" cellpadding="0" cellspacing="1" nobr="true" width="100%">';
    $tbl .= $renderHeader();

    $grandQty   = 0.0;
    $grandLiens = 0.0;

    $pageQty    = 0.0;
    $pageLiens  = 0.0;
    $ctr        = 0;
    $pcs        = 0;
    $totalPcs   = 0;

    foreach ($rows as $r) {
        $qty   = (float)($r->quantity ?? 0);
        $liens = (float)($r->liens ?? 0);

        $weekEndingMDY = $formatDateMDY($r->week_ending);
        $dateIssuedMDY = $formatDateMDY($r->date_issued);

        // legacy calc uses receipt_date vs weekEnding
        $monthsIns = $monthsCeilLegacy($receiptDateISO, $weekEndingMDY);
        $monthsSto = $monthsFloorStorageLegacy($receiptDateISO, $weekEndingMDY, $daysFree);

        $insurance = $qty * $insuranceRate * $monthsIns;
        $storage   = $qty * $storageRate   * $monthsSto;

        $quedanNo = $formatQuedanNoInsSto($r->quedan_no ?? '');

        $planterTIN  = (string)($r->planter_tin ?? '');
        $planterName = (string)($r->planter_name ?? '');

        $ctr++;
        $pcs = $ctr;
        $totalPcs++;

        $pageQty   += $qty;
        $pageLiens += $liens;

        $grandQty   += $qty;
        $grandLiens += $liens;

        $tbl .= '<tr>
          <td align="center"><font size="8">'.htmlspecialchars($millMark).'</font></td>
          <td align="center"><font size="8">'.htmlspecialchars($quedanNo).'</font></td>
          <td align="right"><font size="8">'.number_format($qty, 2).'</font></td>
          <td align="right"><font size="8">'.number_format($liens, 2).'</font></td>
          <td align="right"><font size="8">'.number_format($insurance, 2).'</font></td>
          <td align="right"><font size="8">'.number_format($storage, 2).'</font></td>
          <td align="center"><font size="8">'.htmlspecialchars($weekEndingMDY).'</font></td>
          <td align="center"><font size="8">'.htmlspecialchars($dateIssuedMDY).'</font></td>
          <td align="center"><font size="8">'.htmlspecialchars($planterTIN).'</font></td>
          <td align="left"><font size="7">'.htmlspecialchars($planterName).'</font></td>
        </tr>';

        // legacy: page total every 50 rows
        if (($ctr % 50) === 0) {
            $tbl .= '<tr>
              <td align="right"><font size="8">PAGE TOTAL:</font></td>
              <td align="right"><font size="8">'.(int)$pcs.' PCS.</font></td>
              <td align="right"><font size="8">'.number_format($pageQty, 2).'</font></td>
              <td align="right"><font size="8">'.number_format($pageLiens, 2).'</font></td>
              <td align="right" colspan="6"></td>
            </tr>';

            $tbl .= '<br pagebreak="true"/>';

            $tbl .= '<tr><td colspan="10"></td></tr>';
            $tbl .= $renderHeader();

            // reset page totals
            $pageQty   = 0.0;
            $pageLiens = 0.0;
            $ctr = 0;
        }
    }

    // final page total + grand total
    $tbl .= '<tr>
      <td align="right"><font size="8">PAGE TOTAL:</font></td>
      <td align="right"><font size="8">'.(int)$pcs.' PCS.</font></td>
      <td align="right"><font size="8">'.number_format($pageQty, 2).'</font></td>
      <td align="right"><font size="8">'.number_format($pageLiens, 2).'</font></td>
      <td align="right" colspan="6"></td>
    </tr>';

    $tbl .= '<tr>
      <td align="right"><font size="8">GRAND TOTAL:</font></td>
      <td align="right"><font size="8">'.(int)$totalPcs.' PCS.</font></td>
      <td align="right"><font size="8">'.number_format($grandQty, 2).'</font></td>
      <td align="right"><font size="8">'.number_format($grandLiens, 2).'</font></td>
      <td align="right" colspan="6"></td>
    </tr>';

    $tbl .= '</table>';

    $pdf->writeHTML($tbl, true, false, false, false, '');

    $pdfBytes = $pdf->Output('', 'S');
    $fileName = "quedan-listing-inssto-{$receiptNo}.pdf";

    return response($pdfBytes, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="'.$fileName.'"')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache');
}



public function quedanListingExcel(Request $req, ?string $receiptNo = null)
{
    // ✅ supports both: /.../{receiptNo} and /...?receipt_no=...
    $receiptNo = (string) ($receiptNo ?: $req->query('receipt_no') ?: '');
    if ($receiptNo === '') {
        return response()->json(['message' => 'Missing receipt_no'], 422);
    }

    $companyId = (int) ($req->query('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    // 1) Load receiving entry (scoped)
    $entry = ReceivingEntry::query()
        ->where('company_id', $companyId)
        ->where('receipt_no', $receiptNo)
        ->firstOrFail();

    // 2) Load PBN context (scoped)
    $pbn = PbnEntry::query()
        ->where('company_id', $companyId)
        ->where('pbn_number', $entry->pbn_number)
        ->first();

    $sugarType   = strtoupper((string)($pbn?->sugar_type ?? ''));
    $cropYearRaw = (string)($pbn?->crop_year ?? '');

    // legacy-like crop year display (CY2022-2023)
    $cropYearDisplay = $cropYearRaw;
    if ($cropYearDisplay !== '' && strpos($cropYearDisplay, '-') === false) {
        if (preg_match('/^\d{4}$/', $cropYearDisplay)) {
            $cropYearDisplay = $cropYearDisplay . '-' . ((int)$cropYearDisplay + 1);
        }
    }

    // 3) Load receiving details (quedan lines)
    $rows = ReceivingDetail::query()
        ->where('receiving_entry_id', $entry->id)
        ->where('receipt_no', $entry->receipt_no)
        ->orderBy('row', 'asc')
        ->get();

    // 4) Resolve mill prefix + millmark (same as PDF)
    $millName = (string)($entry->mill ?? '');
    $millRow  = MillList::query()
        ->where('company_id', $companyId)
        ->where('mill_name', $millName)
        ->first();

    $prefix   = (string)($millRow->prefix ?? '');
    $millMark = $prefix !== '' ? $prefix : $millName;

    // 5) Header text by company_id (same as your PDF)
    $shipper = ($companyId === 2) ? 'AMEROP PHILIPPINES, INC.' : 'SUCDEN PHILIPPINES, INC.';
    $buyer   = ($companyId === 2) ? 'AMEROP AMERICAS CORP'     : 'SUCDEN AMERICAS CORP';
    $currentDate = now()->format('M d, Y H:i');

    // 6) Helpers
    $formatDateMDY = function ($v) {
        if (!$v) return '';
        try { return date('m/d/Y', strtotime((string)$v)); } catch (\Throwable $e) { return ''; }
    };

    // Quedan Listing uses: sugarType + prefix + "-" + 6 digits
    $formatQuedanNo = function ($raw) use ($sugarType, $prefix) {
        $raw = trim((string)$raw);

        // if already formatted like "B26-000123" keep it
        if (preg_match('/^[A-Z].+-\d+$/', $raw)) {
            return $raw;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') $digits = '0';
        $num = (int)$digits;

        $pad = str_pad((string)$num, 6, '0', STR_PAD_LEFT);
        return $sugarType . $prefix . '-' . $pad;
    };

    // 7) Spreadsheet setup
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Quedan Listing');

    // Column widths (8 cols)
    $sheet->getColumnDimension('A')->setWidth(15); // MillMark
    $sheet->getColumnDimension('B')->setWidth(20); // Quedan No.
    $sheet->getColumnDimension('C')->setWidth(15); // Quantity
    $sheet->getColumnDimension('D')->setWidth(15); // Liens
    $sheet->getColumnDimension('E')->setWidth(15); // Week Ending
    $sheet->getColumnDimension('F')->setWidth(15); // Date Issued
    $sheet->getColumnDimension('G')->setWidth(18); // TIN
    $sheet->getColumnDimension('H')->setWidth(35); // Planter

    $thinAll = function (string $range) use ($sheet) {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    };

    $writeTopHeader = function (int $startRow) use ($sheet, $shipper, $buyer, $cropYearDisplay, $currentDate, $receiptNo) {
        $sheet->setCellValue("A{$startRow}", "Shipper: {$shipper}");
        $sheet->mergeCells("A{$startRow}:H{$startRow}");
        $sheet->getStyle("A{$startRow}")->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue("A".($startRow+1), "Buyer: {$buyer}");
        $sheet->mergeCells("A".($startRow+1).":H".($startRow+1));
        $sheet->getStyle("A".($startRow+1))->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue("A".($startRow+2), "Quedan Listings (CY{$cropYearDisplay})");
        $sheet->mergeCells("A".($startRow+2).":H".($startRow+2));
        $sheet->getStyle("A".($startRow+2))->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue("A".($startRow+3), $currentDate);
        $sheet->mergeCells("A".($startRow+3).":H".($startRow+3));
        $sheet->getStyle("A".($startRow+3))->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue("A".($startRow+4), "RR No.: {$receiptNo}");
        $sheet->mergeCells("A".($startRow+4).":H".($startRow+4));
        $sheet->getStyle("A".($startRow+4))->getFont()->setBold(true)->setSize(10);

        return $startRow + 6; // leaves one blank row
    };

    $writeColumnHeader = function (int $row) use ($sheet, $thinAll) {
        $sheet->setCellValue("A{$row}", 'MillMark');
        $sheet->setCellValue("B{$row}", 'Quedan No.');
        $sheet->setCellValue("C{$row}", 'Quantity');
        $sheet->setCellValue("D{$row}", 'Liens');
        $sheet->setCellValue("E{$row}", 'Week Ending');
        $sheet->setCellValue("F{$row}", 'Date Issued');
        $sheet->setCellValue("G{$row}", 'TIN');
        $sheet->setCellValue("H{$row}", 'Planter');

        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("C{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("E{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $thinAll("A{$row}:H{$row}");
        $sheet->freezePane("A".($row+1));
        return $row + 1;
    };

    // 8) Write first header + headers row
    $rowCursor = 1;
    $rowCursor = $writeTopHeader($rowCursor);
    $rowCursor = $writeColumnHeader($rowCursor);

    // 9) Legacy paging counters (50 rows/page)
    $pageCount  = 0;
    $pcs        = 0;
    $totalPcs   = 0;

    $pageQty    = 0.0;
    $pageLiens  = 0.0;
    $grandQty   = 0.0;
    $grandLiens = 0.0;

    foreach ($rows as $r) {
        $qty   = (float)($r->quantity ?? 0);
        $liens = (float)($r->liens ?? 0);

        $pageCount++; $pcs++; $totalPcs++;
        $pageQty   += $qty;   $pageLiens += $liens;
        $grandQty  += $qty;   $grandLiens += $liens;

        $sheet->setCellValue("A{$rowCursor}", $millMark);
        $sheet->setCellValueExplicit("B{$rowCursor}", $formatQuedanNo($r->quedan_no ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue("C{$rowCursor}", $qty);
        $sheet->setCellValue("D{$rowCursor}", $liens);
        $sheet->setCellValue("E{$rowCursor}", $formatDateMDY($r->week_ending));
        $sheet->setCellValue("F{$rowCursor}", $formatDateMDY($r->date_issued));
        $sheet->setCellValueExplicit("G{$rowCursor}", (string)($r->planter_tin ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue("H{$rowCursor}", (string)($r->planter_name ?? ''));

        $sheet->getStyle("C{$rowCursor}:D{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $rowCursor++;

        // Every 50 rows -> PAGE TOTAL + repeat headers (legacy)
        if (($pageCount % 50) === 0) {
            $sheet->setCellValue("A{$rowCursor}", 'PAGE TOTAL:');
            $sheet->setCellValue("B{$rowCursor}", "{$pcs} PCS.");
            $sheet->setCellValue("C{$rowCursor}", $pageQty);
            $sheet->setCellValue("D{$rowCursor}", $pageLiens);

            $sheet->getStyle("A{$rowCursor}:D{$rowCursor}")->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle("A{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("B{$rowCursor}:D{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $rowCursor++;

            // spacer row
            $sheet->mergeCells("A{$rowCursor}:H{$rowCursor}");
            $rowCursor++;

            // repeat header
            $rowCursor = $writeTopHeader($rowCursor);
            $rowCursor = $writeColumnHeader($rowCursor);

            // reset page
            $pageCount = 0;
            $pcs = 0;
            $pageQty = 0.0;
            $pageLiens = 0.0;
        }
    }

    // 10) final PAGE TOTAL
    $sheet->setCellValue("A{$rowCursor}", 'PAGE TOTAL:');
    $sheet->setCellValue("B{$rowCursor}", "{$pcs} PCS.");
    $sheet->setCellValue("C{$rowCursor}", $pageQty);
    $sheet->setCellValue("D{$rowCursor}", $pageLiens);
    $sheet->getStyle("A{$rowCursor}:D{$rowCursor}")->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle("A{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle("B{$rowCursor}:D{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $rowCursor++;

    // 11) GRAND TOTAL
    $sheet->setCellValue("A{$rowCursor}", 'GRAND TOTAL:');
    $sheet->setCellValue("B{$rowCursor}", "{$totalPcs} PCS.");
    $sheet->setCellValue("C{$rowCursor}", $grandQty);
    $sheet->setCellValue("D{$rowCursor}", $grandLiens);
    $sheet->getStyle("A{$rowCursor}:D{$rowCursor}")->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle("A{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle("B{$rowCursor}:D{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // 12) Output as XLSX (fixes Excel open warning)
    $filename = "Quedan_Listing_{$receiptNo}.xlsx";
    $tmpDir = storage_path('app/tmp');
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
    $path = $tmpDir . '/' . $filename;

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);

    return response()->download($path, $filename, [
        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
    ])->deleteFileAfterSend(true);
}


public function quedanListingInsuranceStorageExcel(Request $req, ?string $receiptNo = null)
{
    $receiptNo = (string) ($receiptNo ?: $req->query('receipt_no') ?: '');
    $receiptNo = trim($receiptNo); // ✅ important
    if ($receiptNo === '') {
        return response()->json(['message' => 'Missing receipt_no'], 422);
    }

    $companyId = (int) ($req->query('company_id') ?: $req->header('X-Company-ID') ?: 0);
    if ($companyId <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    $entry = ReceivingEntry::query()
        ->where('company_id', $companyId)
        ->where('receipt_no', $receiptNo)
        ->firstOrFail();

    $pbn = PbnEntry::query()
        ->where('company_id', $companyId)
        ->where('pbn_number', $entry->pbn_number)
        ->first();

    $sugarType   = strtoupper((string)($pbn?->sugar_type ?? ''));
    $cropYearRaw = (string)($pbn?->crop_year ?? '');

    $cropYearDisplay = $cropYearRaw;
    if ($cropYearDisplay !== '' && strpos($cropYearDisplay, '-') === false) {
        if (preg_match('/^\d{4}$/', $cropYearDisplay)) {
            $cropYearDisplay = $cropYearDisplay . '-' . ((int)$cropYearDisplay + 1);
        }
    }

    $rows = ReceivingDetail::query()
        ->where('receiving_entry_id', $entry->id)
        ->where('receipt_no', $entry->receipt_no)
        ->orderBy('row', 'asc')
        ->get();

    $millName = (string)($entry->mill ?? '');
    $millRow  = MillList::query()
        ->where('company_id', $companyId)
        ->where('mill_name', $millName)
        ->first();

    $prefix   = (string)($millRow->prefix ?? '');
    $millMark = $prefix !== '' ? $prefix : $millName;

    $shipper = ($companyId === 2) ? 'AMEROP PHILIPPINES, INC.' : 'SUCDEN PHILIPPINES, INC.';
    $buyer   = ($companyId === 2) ? 'AMEROP AMERICAS CORP'     : 'SUCDEN AMERICAS CORP';
    $currentDate = now()->format('M d, Y H:i');

    $formatDateMDY = function ($v) {
        if (!$v) return '';
        try { return date('m/d/Y', strtotime((string)$v)); } catch (\Throwable $e) { return ''; }
    };

    $formatQuedanNo = function ($raw) use ($sugarType, $prefix) {
        $raw = trim((string)$raw);
        if (preg_match('/^[A-Z].+-\d+$/', $raw)) return $raw;

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') $digits = '0';
        $num = (int)$digits;

        return $sugarType . $prefix . '-' . str_pad((string)$num, 6, '0', STR_PAD_LEFT);
    };

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Quedan Listing');

    // ✅ 11 columns now: A..K
    $sheet->getColumnDimension('A')->setWidth(15); // MillMark
    $sheet->getColumnDimension('B')->setWidth(20); // Quedan No.
    $sheet->getColumnDimension('C')->setWidth(12); // Quantity
    $sheet->getColumnDimension('D')->setWidth(12); // Liens
    $sheet->getColumnDimension('E')->setWidth(14); // Week Ending
    $sheet->getColumnDimension('F')->setWidth(14); // Date Issued
    $sheet->getColumnDimension('G')->setWidth(18); // TIN
    $sheet->getColumnDimension('H')->setWidth(35); // Planter
    $sheet->getColumnDimension('I')->setWidth(12); // Storage
    $sheet->getColumnDimension('J')->setWidth(12); // Insurance
    $sheet->getColumnDimension('K')->setWidth(12); // Total AP

    $thinAll = function (string $range) use ($sheet) {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    };

    $writeTopHeader = function (int $startRow) use ($sheet, $shipper, $buyer, $cropYearDisplay, $currentDate, $receiptNo) {
        $sheet->setCellValue("A{$startRow}", "Shipper: {$shipper}");
        $sheet->mergeCells("A{$startRow}:K{$startRow}");
        $sheet->getStyle("A{$startRow}")->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue("A".($startRow+1), "Buyer: {$buyer}");
        $sheet->mergeCells("A".($startRow+1).":K".($startRow+1));
        $sheet->getStyle("A".($startRow+1))->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue("A".($startRow+2), "Quedan Listings (CY{$cropYearDisplay})");
        $sheet->mergeCells("A".($startRow+2).":K".($startRow+2));
        $sheet->getStyle("A".($startRow+2))->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue("A".($startRow+3), $currentDate);
        $sheet->mergeCells("A".($startRow+3).":K".($startRow+3));
        $sheet->getStyle("A".($startRow+3))->getFont()->setBold(true)->setSize(10);

        $sheet->setCellValue("A".($startRow+4), "RR No.: {$receiptNo}");
        $sheet->mergeCells("A".($startRow+4).":K".($startRow+4));
        $sheet->getStyle("A".($startRow+4))->getFont()->setBold(true)->setSize(10);

        return $startRow + 6;
    };

    $writeColumnHeader = function (int $row) use ($sheet, $thinAll) {
        $sheet->setCellValue("A{$row}", 'MillMark');
        $sheet->setCellValue("B{$row}", 'Quedan No.');
        $sheet->setCellValue("C{$row}", 'Quantity');
        $sheet->setCellValue("D{$row}", 'Liens');
        $sheet->setCellValue("E{$row}", 'Week Ending');
        $sheet->setCellValue("F{$row}", 'Date Issued');
        $sheet->setCellValue("G{$row}", 'TIN');
        $sheet->setCellValue("H{$row}", 'Planter');
        $sheet->setCellValue("I{$row}", 'Storage');
        $sheet->setCellValue("J{$row}", 'Insurance');
        $sheet->setCellValue("K{$row}", 'Total AP');

        $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("C{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("E{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("I{$row}:K{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $thinAll("A{$row}:K{$row}");
        $sheet->freezePane("A".($row+1));
        return $row + 1;
    };

    $rowCursor = 1;
    $rowCursor = $writeTopHeader($rowCursor);
    $rowCursor = $writeColumnHeader($rowCursor);

    // paging counters
    $pageCount = 0; $pcs = 0; $totalPcs = 0;
    $pageQty = 0; $pageLiens = 0; $pageSto = 0; $pageIns = 0; $pageAp = 0;
    $grandQty = 0; $grandLiens = 0; $grandSto = 0; $grandIns = 0; $grandAp = 0;

    foreach ($rows as $r) {
        $qty = (float)($r->quantity ?? 0);
        $li  = (float)($r->liens ?? 0);
        $sto = (float)($r->storage ?? 0);
        $ins = (float)($r->insurance ?? 0);
        $ap  = (float)($r->total_ap ?? 0);

        $pageCount++; $pcs++; $totalPcs++;
        $pageQty += $qty; $pageLiens += $li; $pageSto += $sto; $pageIns += $ins; $pageAp += $ap;
        $grandQty += $qty; $grandLiens += $li; $grandSto += $sto; $grandIns += $ins; $grandAp += $ap;

        $sheet->setCellValue("A{$rowCursor}", $millMark);
        $sheet->setCellValueExplicit("B{$rowCursor}", $formatQuedanNo($r->quedan_no ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue("C{$rowCursor}", $qty);
        $sheet->setCellValue("D{$rowCursor}", $li);
        $sheet->setCellValue("E{$rowCursor}", $formatDateMDY($r->week_ending));
        $sheet->setCellValue("F{$rowCursor}", $formatDateMDY($r->date_issued));
        $sheet->setCellValueExplicit("G{$rowCursor}", (string)($r->planter_tin ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue("H{$rowCursor}", (string)($r->planter_name ?? ''));
        $sheet->setCellValue("I{$rowCursor}", $sto);
        $sheet->setCellValue("J{$rowCursor}", $ins);
        $sheet->setCellValue("K{$rowCursor}", $ap);

        $sheet->getStyle("C{$rowCursor}:D{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("I{$rowCursor}:K{$rowCursor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $rowCursor++;

        if (($pageCount % 50) === 0) {
            $sheet->setCellValue("A{$rowCursor}", 'PAGE TOTAL:');
            $sheet->setCellValue("B{$rowCursor}", "{$pcs} PCS.");
            $sheet->setCellValue("C{$rowCursor}", $pageQty);
            $sheet->setCellValue("D{$rowCursor}", $pageLiens);
            $sheet->setCellValue("I{$rowCursor}", $pageSto);
            $sheet->setCellValue("J{$rowCursor}", $pageIns);
            $sheet->setCellValue("K{$rowCursor}", $pageAp);

            $sheet->getStyle("A{$rowCursor}:K{$rowCursor}")->getFont()->setBold(true)->setSize(11);
            $rowCursor++;

            $sheet->mergeCells("A{$rowCursor}:K{$rowCursor}");
            $rowCursor++;

            $rowCursor = $writeTopHeader($rowCursor);
            $rowCursor = $writeColumnHeader($rowCursor);

            $pageCount = 0; $pcs = 0;
            $pageQty = 0; $pageLiens = 0; $pageSto = 0; $pageIns = 0; $pageAp = 0;
        }
    }

    // final PAGE TOTAL
    $sheet->setCellValue("A{$rowCursor}", 'PAGE TOTAL:');
    $sheet->setCellValue("B{$rowCursor}", "{$pcs} PCS.");
    $sheet->setCellValue("C{$rowCursor}", $pageQty);
    $sheet->setCellValue("D{$rowCursor}", $pageLiens);
    $sheet->setCellValue("I{$rowCursor}", $pageSto);
    $sheet->setCellValue("J{$rowCursor}", $pageIns);
    $sheet->setCellValue("K{$rowCursor}", $pageAp);
    $sheet->getStyle("A{$rowCursor}:K{$rowCursor}")->getFont()->setBold(true)->setSize(11);
    $rowCursor++;

    // GRAND TOTAL
    $sheet->setCellValue("A{$rowCursor}", 'GRAND TOTAL:');
    $sheet->setCellValue("B{$rowCursor}", "{$totalPcs} PCS.");
    $sheet->setCellValue("C{$rowCursor}", $grandQty);
    $sheet->setCellValue("D{$rowCursor}", $grandLiens);
    $sheet->setCellValue("I{$rowCursor}", $grandSto);
    $sheet->setCellValue("J{$rowCursor}", $grandIns);
    $sheet->setCellValue("K{$rowCursor}", $grandAp);
    $sheet->getStyle("A{$rowCursor}:K{$rowCursor}")->getFont()->setBold(true)->setSize(11);

    $filename = "Quedan_Listing_InsSto_{$receiptNo}.xlsx";
    $tmpDir = storage_path('app/tmp');
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
    $path = $tmpDir . '/' . $filename;

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);

    return response()->download($path, $filename, [
        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
    ])->deleteFileAfterSend(true);
}






}
