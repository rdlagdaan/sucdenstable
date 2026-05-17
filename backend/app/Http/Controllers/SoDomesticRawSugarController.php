<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SoDomesticRawSugarController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');

        $rows = DB::table('so_domestic_raw_sugar')
            ->where('company_id', $companyId)
            ->where(function ($q) {
                $q->whereNull('delete_flag')->orWhere('delete_flag', false);
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json($rows);
    }

public function show(Request $request, int $id): JsonResponse
{
    $companyId = (int) $request->query('company_id');

    $main = DB::table('so_domestic_raw_sugar')
        ->where('id', $id)
        ->where('company_id', $companyId)
        ->first();

    if (!$main) {
        return response()->json(['message' => 'Sales Invoice not found.'], 404);
    }

    $po = null;
    if (!empty($main->po_no)) {
        $po = DB::table('pbn_entry')
            ->whereRaw('CAST(company_id as text) = ?', [(string) $companyId])
            ->where('pbn_number', $main->po_no)
            ->first(['id', 'pbn_number', 'vend_code', 'vendor_name']);
    }

    $poItem = null;
    if (!empty($main->po_entry_id)) {
        $poItem = DB::table('pbn_entry_details')
            ->where('pbn_entry_id', (int) $main->po_entry_id)
            ->where(function ($q) {
                $q->whereNull('delete_flag')
                  ->orWhere('delete_flag', 0)
                  ->orWhere('delete_flag', false);
            })
            ->orderBy('row')
            ->orderBy('id')
            ->first(['id', 'particulars', 'mill_code', 'mill']);
    }

    $savedQuedan = DB::table('so_domestic_raw_sugar_quedans')
        ->where('company_id', $companyId)
        ->where('so_domestic_raw_sugar_id', $id)
        ->where('selected_flag', true)
        ->orderBy('id')
        ->first(['receipt_no']);

    $details = DB::table('so_domestic_raw_sugar_details')
        ->where('so_domestic_raw_sugar_id', $id)
        ->where('company_id', $companyId)
        ->where(function ($q) {
            $q->whereNull('delete_flag')->orWhere('delete_flag', false);
        })
        ->orderBy('row_no')
        ->get();

    $salesJournal = DB::table('cash_sales')
        ->where('company_id', $companyId)
        ->where('si_no', (string) $main->si_no)
        ->where(function ($q) {
            $q->whereNull('is_cancel')
              ->orWhereNotIn('is_cancel', ['d', 'c', 'y']);
        })
        ->first(['id', 'cs_no']);

    $main->vendor_code = $po?->vend_code ?? '';
    $main->vendor_name = $po?->vendor_name ?? $main->buyer_name ?? '';
    $main->item_id = $poItem?->id ?? null;
    $main->item_label = $poItem?->particulars ?: 'RAW SUGAR';
    $main->rr_no = $savedQuedan?->receipt_no ?? '';

    $main->processed_to_sales_journal = $salesJournal ? true : false;
    $main->cash_sales_id = $salesJournal?->id ?? null;
    $main->sales_journal_no = $salesJournal?->cs_no ?? null;

    return response()->json([
        'main' => $main,
        'details' => $details,
    ]);
}

    public function storeMain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],

            'si_date' => ['nullable', 'date'],

            'po_entry_id' => ['nullable', 'integer'],
            'po_no' => ['nullable', 'string', 'max:50'],
            'po_date' => ['nullable', 'date'],

            'bl_id' => ['nullable', 'integer'],
            'bl_no' => ['nullable', 'string', 'max:100'],

            'mill_id' => ['nullable', 'string', 'max:50'],
            'mill_name' => ['nullable', 'string', 'max:255'],

            'selling_price' => ['nullable', 'numeric'],
            'quantity' => ['nullable', 'numeric'],

            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_address' => ['nullable', 'string'],
            'tin' => ['nullable', 'string', 'max:50'],

            'vatable_flag' => ['nullable', 'boolean'],
            'withholding_tax_flag' => ['nullable', 'boolean'],
            'withholding_tax_amount' => ['nullable', 'numeric'],
        ]);

        return DB::transaction(function () use ($data, $request) {
            $setting = DB::table('application_settings')
                ->where('apset_code', 'SINo')
                ->lockForUpdate()
                ->first();

            if (!$setting) {
                return response()->json([
                    'message' => 'SINo setting not found in application_settings.',
                ], 500);
            }

            $current = (int) ($setting->value ?? 0);
            $next = $current + 1;
            $width = max(strlen((string) ($setting->value ?? '0')), 5);
            $siNo = str_pad((string) $next, $width, '0', STR_PAD_LEFT);

            DB::table('application_settings')
                ->where('apset_code', 'SINo')
                ->update([
                    'value' => $siNo,
                    'updated_at' => now(),
                ]);

            $id = DB::table('so_domestic_raw_sugar')->insertGetId([
                'company_id' => (int) $data['company_id'],

                'si_no' => $siNo,
                'si_date' => $data['si_date'] ?? null,

                'po_entry_id' => $data['po_entry_id'] ?? null,
                'po_no' => $data['po_no'] ?? null,
                'po_date' => $data['po_date'] ?? null,

                'bl_id' => $data['bl_id'] ?? null,
                'bl_no' => $data['bl_no'] ?? null,

                'mill_id' => $data['mill_id'] ?? null,
                'mill_name' => $data['mill_name'] ?? null,

                'selling_price' => $data['selling_price'] ?? 0,
                'quantity' => $data['quantity'] ?? 0,

                'buyer_name' => $data['buyer_name'] ?? null,
                'buyer_address' => $data['buyer_address'] ?? null,
                'tin' => $data['tin'] ?? null,

                'vatable_flag' => $data['vatable_flag'] ?? false,
                'withholding_tax_flag' => $data['withholding_tax_flag'] ?? false,
                'withholding_tax_amount' => $data['withholding_tax_amount'] ?? 0,

                'posted_flag' => false,
                'processed_flag' => false,
                'delete_flag' => false,
                'visible_flag' => 1,

                'workstation_id' => $request->ip(),
                'user_id' => auth()->id(),

                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'id' => $id,
                'si_no' => $siNo,
                'message' => 'Sales Invoice saved.',
            ]);
        });
    }

    public function updateMain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],

            'si_date' => ['nullable', 'date'],

            'po_entry_id' => ['nullable', 'integer'],
            'po_no' => ['nullable', 'string', 'max:50'],
            'po_date' => ['nullable', 'date'],

            'bl_id' => ['nullable', 'integer'],
            'bl_no' => ['nullable', 'string', 'max:100'],

            'mill_id' => ['nullable', 'string', 'max:50'],
            'mill_name' => ['nullable', 'string', 'max:255'],

            'selling_price' => ['nullable', 'numeric'],
            'quantity' => ['nullable', 'numeric'],

            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_address' => ['nullable', 'string'],
            'tin' => ['nullable', 'string', 'max:50'],

            'vatable_flag' => ['nullable', 'boolean'],
            'withholding_tax_flag' => ['nullable', 'boolean'],
            'withholding_tax_amount' => ['nullable', 'numeric'],
        ]);

        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', (int) $data['id'])
            ->where('company_id', (int) $data['company_id'])
            ->first();

        if (!$main) {
            return response()->json(['message' => 'Sales Invoice not found.'], 404);
        }

        if ((bool) ($main->posted_flag ?? false)) {
            return response()->json(['message' => 'Cannot update. Sales Invoice is already posted.'], 409);
        }

        DB::table('so_domestic_raw_sugar')
            ->where('id', (int) $data['id'])
            ->where('company_id', (int) $data['company_id'])
            ->update([
                'si_date' => $data['si_date'] ?? null,

                'po_entry_id' => $data['po_entry_id'] ?? null,
                'po_no' => $data['po_no'] ?? null,
                'po_date' => $data['po_date'] ?? null,

                'bl_id' => $data['bl_id'] ?? null,
                'bl_no' => $data['bl_no'] ?? null,

                'mill_id' => $data['mill_id'] ?? null,
                'mill_name' => $data['mill_name'] ?? null,

                'selling_price' => $data['selling_price'] ?? 0,
                'quantity' => $data['quantity'] ?? 0,

                'buyer_name' => $data['buyer_name'] ?? null,
                'buyer_address' => $data['buyer_address'] ?? null,
                'tin' => $data['tin'] ?? null,

                'vatable_flag' => $data['vatable_flag'] ?? false,
                'withholding_tax_flag' => $data['withholding_tax_flag'] ?? false,
                'withholding_tax_amount' => $data['withholding_tax_amount'] ?? 0,

                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Sales Invoice main updated.']);
    }

    public function saveDetail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'so_domestic_raw_sugar_id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
            'row_no' => ['required', 'integer'],
            'so_type' => ['required', 'string', 'in:DR#,SO#'],
            'number' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'numeric'],
        ]);

        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', (int) $data['so_domestic_raw_sugar_id'])
            ->where('company_id', (int) $data['company_id'])
            ->first();

        if (!$main) {
            return response()->json(['message' => 'Sales Invoice main not found.'], 404);
        }

        if ((bool) ($main->posted_flag ?? false)) {
            return response()->json(['message' => 'Cannot save detail. Sales Invoice is already posted.'], 409);
        }

        $id = DB::table('so_domestic_raw_sugar_details')->insertGetId([
            'so_domestic_raw_sugar_id' => (int) $data['so_domestic_raw_sugar_id'],
            'company_id' => (int) $data['company_id'],
            'row_no' => (int) $data['row_no'],
            'so_type' => $data['so_type'],
            'number' => $data['number'],
            'quantity' => $data['quantity'],
            'delete_flag' => false,
            'workstation_id' => $request->ip(),
            'user_id' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->recalcQuantity((int) $data['so_domestic_raw_sugar_id'], (int) $data['company_id']);

        return response()->json([
            'id' => $id,
            'message' => 'Detail saved.',
        ]);
    }

    public function updateDetail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'so_domestic_raw_sugar_id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
            'row_no' => ['required', 'integer'],
            'so_type' => ['required', 'string', 'in:DR#,SO#'],
            'number' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'numeric'],
        ]);

        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', (int) $data['so_domestic_raw_sugar_id'])
            ->where('company_id', (int) $data['company_id'])
            ->first();

        if (!$main) {
            return response()->json(['message' => 'Sales Invoice main not found.'], 404);
        }

        if ((bool) ($main->posted_flag ?? false)) {
            return response()->json(['message' => 'Cannot update detail. Sales Invoice is already posted.'], 409);
        }

        DB::table('so_domestic_raw_sugar_details')
            ->where('id', (int) $data['id'])
            ->where('so_domestic_raw_sugar_id', (int) $data['so_domestic_raw_sugar_id'])
            ->where('company_id', (int) $data['company_id'])
            ->update([
                'row_no' => (int) $data['row_no'],
                'so_type' => $data['so_type'],
                'number' => $data['number'],
                'quantity' => $data['quantity'],
                'updated_at' => now(),
            ]);

        $this->recalcQuantity((int) $data['so_domestic_raw_sugar_id'], (int) $data['company_id']);

        return response()->json(['message' => 'Detail updated.']);
    }

    public function deleteDetail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'so_domestic_raw_sugar_id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
        ]);

        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', (int) $data['so_domestic_raw_sugar_id'])
            ->where('company_id', (int) $data['company_id'])
            ->first();

        if (!$main) {
            return response()->json(['message' => 'Sales Invoice main not found.'], 404);
        }

        if ((bool) ($main->posted_flag ?? false)) {
            return response()->json(['message' => 'Cannot delete detail. Sales Invoice is already posted.'], 409);
        }

        DB::table('so_domestic_raw_sugar_details')
            ->where('id', (int) $data['id'])
            ->where('so_domestic_raw_sugar_id', (int) $data['so_domestic_raw_sugar_id'])
            ->where('company_id', (int) $data['company_id'])
            ->update([
                'delete_flag' => true,
                'updated_at' => now(),
            ]);

        $this->recalcQuantity((int) $data['so_domestic_raw_sugar_id'], (int) $data['company_id']);

        return response()->json(['message' => 'Detail deleted.']);
    }

    public function siDropdown(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $q = trim((string) $request->query('q', ''));

        $rows = DB::table('so_domestic_raw_sugar')
            ->where('company_id', $companyId)
            ->where(function ($w) {
                $w->whereNull('delete_flag')
                  ->orWhere('delete_flag', false);
            })
            ->when($q !== '', function ($qq) use ($q) {
                $like = '%' . strtolower($q) . '%';

                $qq->where(function ($w) use ($like) {
                    $w->whereRaw('LOWER(CAST(COALESCE(si_no, \'\') as text)) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(CAST(COALESCE(po_no, \'\') as text)) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(CAST(COALESCE(buyer_name, \'\') as text)) LIKE ?', [$like]);
                });
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get([
                'id',
                'si_no',
                'si_date',
                'po_no',
                'buyer_name',
                'quantity',
            ]);

        return response()->json($rows);
    }

    public function poDropdown(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $q = trim((string) $request->query('q', ''));

        $rows = DB::table('pbn_entry as p')
            ->select([
                'p.id',
                DB::raw('p.pbn_number as po_no'),
                DB::raw('p.pbn_date as po_date'),
                DB::raw('COALESCE(p.vend_code, \'\') as vendor_code'),
                DB::raw('COALESCE(p.vendor_name, \'\') as vendor_name'),
                'p.sugar_type',
                'p.crop_year',
            ])
            ->where('p.company_id', $companyId)
            ->where('p.posted_flag', 1)
            ->where(function ($w) {
                if (Schema::hasColumn('pbn_entry', 'delete_flag')) {
                    $w->whereNull('p.delete_flag')
                      ->orWhere('p.delete_flag', 0)
                      ->orWhere('p.delete_flag', false);
                } else {
                    $w->whereRaw('1=1');
                }
            })
            ->where(function ($w) {
                if (Schema::hasColumn('pbn_entry', 'close_flag')) {
                    $w->whereNull('p.close_flag')
                      ->orWhere('p.close_flag', 0)
                      ->orWhere('p.close_flag', false);
                } else {
                    $w->whereRaw('1=1');
                }
            })
            ->when($q !== '', function ($qq) use ($q) {
                $like = '%' . strtolower($q) . '%';

                $qq->where(function ($w) use ($like) {
                    $w->whereRaw('LOWER(CAST(p.pbn_number as text)) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(CAST(COALESCE(p.vend_code, \'\') as text)) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(CAST(COALESCE(p.vendor_name, \'\') as text)) LIKE ?', [$like]);
                });
            })
            ->orderByDesc('p.id')
            ->limit(200)
            ->get();

        return response()->json($rows);
    }


    public function poItemsDropdown(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $purchaseOrderId = (int) ($request->query('purchase_order_id') ?: 0);
        $poNo = trim((string) $request->query('po_no', ''));

        if ($companyId <= 0 || ($purchaseOrderId <= 0 && $poNo === '')) {
            return response()->json([]);
        }

        $headerQ = DB::table('pbn_entry as p')
            ->where('p.company_id', $companyId)
            ->where('p.posted_flag', 1);

        if ($purchaseOrderId > 0) {
            $headerQ->where('p.id', $purchaseOrderId);
        } else {
            $headerQ->where('p.pbn_number', $poNo);
        }

        $header = $headerQ->first(['p.id', 'p.pbn_number']);

        if (!$header) {
            return response()->json([]);
        }

        $detailQ = DB::table('pbn_entry_details as d')
            ->select([
                'd.id',
                'd.row',
                'd.pbn_entry_id',
                'd.pbn_number',
                DB::raw('COALESCE(d.particulars, \'\') as particulars'),
                DB::raw('COALESCE(d.mill_code, \'\') as mill_code'),
                DB::raw('COALESCE(d.mill, \'\') as mill'),
                DB::raw('COALESCE(d.quantity, 0) as quantity'),
                DB::raw('COALESCE(d.price, 0) as price'),
                DB::raw('COALESCE(d.cost, 0) as cost'),
                DB::raw('COALESCE(d.total_cost, 0) as total_cost'),
            ])
            ->where('d.pbn_entry_id', (int) $header->id);

        if (Schema::hasColumn('pbn_entry_details', 'company_id')) {
            $detailQ->where(function ($w) use ($companyId) {
                $w->whereNull('d.company_id')
                  ->orWhere('d.company_id', '')
                  ->orWhereRaw('CAST(d.company_id as text) = ?', [(string) $companyId]);
            });
        }

        if (Schema::hasColumn('pbn_entry_details', 'delete_flag')) {
            $detailQ->where(function ($w) {
                $w->whereNull('d.delete_flag')
                  ->orWhere('d.delete_flag', 0)
                  ->orWhere('d.delete_flag', false);
            });
        }

        $rows = $detailQ
            ->orderBy('d.row')
            ->orderBy('d.id')
            ->get()
            ->map(function ($r) {
                $particulars = trim((string) ($r->particulars ?? ''));

                return [
                    'id'           => (int) $r->id,
                    'row'          => (int) ($r->row ?? 0),
                    'pbn_entry_id' => (int) ($r->pbn_entry_id ?? 0),
                    'pbn_number'   => (string) ($r->pbn_number ?? ''),
                    'item_label'   => $particulars !== '' ? $particulars : ('Item ' . ((int) ($r->row ?? 0))),
                    'particulars'  => $particulars,
                    'mill_code'    => (string) ($r->mill_code ?? ''),
                    'mill'         => (string) ($r->mill ?? ''),
                    'quantity'     => (float) ($r->quantity ?? 0),
                    'price'        => (float) ($r->price ?? 0),
                    'cost'         => (float) ($r->cost ?? 0),
                    'total_cost'   => (float) ($r->total_cost ?? 0),
                ];
            })
            ->values();

        return response()->json($rows);
    }


    public function rrDropdown(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $q = trim((string) $request->query('q', ''));
        $poNo = trim((string) $request->query('po_no', ''));

        $rows = DB::table('receiving_entry as r')
            ->leftJoin('pbn_entry as p', function ($join) {
                $join->on('p.pbn_number', '=', 'r.pbn_number')
                     ->on('p.company_id', '=', 'r.company_id');
            })
            ->leftJoin('receiving_details as d', function ($join) {
                $join->on('d.receipt_no', '=', 'r.receipt_no')
                     ->on('d.receiving_entry_id', '=', 'r.id');
            })
            ->select([
                'r.receipt_no',
                DB::raw('COALESCE(SUM(d.quantity), 0) as quantity'),
                DB::raw('COALESCE(p.sugar_type, \'\') as sugar_type'),
                'r.pbn_number',
                'r.receipt_date',
                DB::raw('COALESCE(p.vend_code, \'\') as vendor_code'),
                DB::raw('COALESCE(p.vendor_name, \'\') as vendor_name'),
            ])
            ->where('r.company_id', $companyId)
            ->where(function ($w) {
                $w->whereNull('r.deleted_flag')
                  ->orWhere('r.deleted_flag', false)
                  ->orWhere('r.deleted_flag', 0);
            })
            ->when($poNo !== '', function ($qq) use ($poNo) {
                $qq->where('r.pbn_number', $poNo);
            })
            ->when($q !== '', function ($qq) use ($q) {
                $like = '%' . strtolower($q) . '%';

                $qq->where(function ($w) use ($like) {
                    $w->whereRaw('LOWER(CAST(r.receipt_no as text)) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(CAST(r.pbn_number as text)) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(CAST(COALESCE(p.vendor_name, \'\') as text)) LIKE ?', [$like]);
                });
            })
            ->groupBy(
                'r.receipt_no',
                'p.sugar_type',
                'r.pbn_number',
                'r.receipt_date',
                'p.vend_code',
                'p.vendor_name'
            )
            ->orderBy('r.receipt_no', 'asc')
            ->limit(200)
            ->get();

        return response()->json($rows);
    }

    public function quedanList(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $receiptNo = trim((string) $request->query('receipt_no', ''));
        $poNo = trim((string) $request->query('po_no', ''));
        $mainId = (int) ($request->query('so_domestic_raw_sugar_id') ?: 0);

        if ($companyId <= 0 || $receiptNo === '') {
            return response()->json([]);
        }

        $saved = collect();

        if ($mainId > 0) {
            $saved = DB::table('so_domestic_raw_sugar_quedans')
                ->where('company_id', $companyId)
                ->where('so_domestic_raw_sugar_id', $mainId)
                ->get()
                ->keyBy('quedan_no');
        }

        $usedByOthers = DB::table('so_domestic_raw_sugar_quedans')
            ->where('company_id', $companyId)
            ->where('selected_flag', true)
            ->when($mainId > 0, function ($q) use ($mainId) {
                $q->where('so_domestic_raw_sugar_id', '<>', $mainId);
            })
            ->select('quedan_no', DB::raw('COALESCE(SUM(selected_quantity), 0) as used_qty'))
            ->groupBy('quedan_no')
            ->get()
            ->keyBy('quedan_no');

        $rows = DB::table('receiving_details as d')
            ->join('receiving_entry as r', 'r.id', '=', 'd.receiving_entry_id')
            ->where('r.company_id', $companyId)
            ->where('r.receipt_no', $receiptNo)
            ->when($poNo !== '', function ($q) use ($poNo) {
                $q->where('r.pbn_number', $poNo);
            })
            ->where(function ($q) {
                $q->whereNull('r.deleted_flag')
                  ->orWhere('r.deleted_flag', false);
            })
            ->orderBy('d.row')
            ->orderBy('d.id')
            ->get([
                'd.id',
                'd.receipt_no',
                'd.quedan_no',
                'd.quantity',
                'd.planter_tin',
                'd.planter_name',
                'd.unit_cost',
                'd.week_ending',
                'd.date_issued',
                'd.item_no',
                'd.mill',
            ])
            ->map(function ($r) use ($saved, $usedByOthers) {
                $quedanNo = (string) $r->quedan_no;

                $originalQty = (float) $r->quantity;
                $savedRow = $saved->get($quedanNo);
                $otherUsedQty = (float) ($usedByOthers->get($quedanNo)->used_qty ?? 0);

                $thisSiUsedQty = $savedRow ? (float) $savedRow->selected_quantity : 0;
                $remainingQty = max(0, $originalQty - $otherUsedQty);

                return [
                    'id' => (int) $r->id,
                    'receipt_no' => (string) $r->receipt_no,
                    'quedan_no' => $quedanNo,
                    'quantity' => $originalQty,
                    'planter_tin' => (string) ($r->planter_tin ?? ''),
                    'planter_name' => (string) ($r->planter_name ?? ''),
                    'unit_cost' => (float) ($r->unit_cost ?? 0),
                    'week_ending' => $r->week_ending,
                    'date_issued' => $r->date_issued,
                    'item_no' => (string) ($r->item_no ?? ''),
                    'mill' => (string) ($r->mill ?? ''),

                    'selected_flag' => $savedRow ? (bool) $savedRow->selected_flag : false,
                    'override_flag' => $savedRow ? (bool) $savedRow->override_flag : false,
                    'override_quantity' => $savedRow ? $savedRow->override_quantity : null,
                    'selected_quantity' => $thisSiUsedQty,

                    'used_by_other_si' => $otherUsedQty,
                    'remaining_quantity' => $remainingQty,
                    'available_for_this_si' => $remainingQty + $thisSiUsedQty,
                ];
            })
            ->values();

        return response()->json($rows);
    }



    public function saveQuedans(Request $request): JsonResponse
    {
        $data = $request->validate([
            'so_domestic_raw_sugar_id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
            'items' => ['required', 'array'],
            'items.*.receipt_no' => ['nullable', 'string', 'max:25'],
            'items.*.quedan_no' => ['required', 'string', 'max:50'],
            'items.*.planter_tin' => ['nullable', 'string', 'max:50'],
            'items.*.planter_name' => ['nullable', 'string', 'max:255'],
            'items.*.original_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.selected_flag' => ['required', 'boolean'],
            'items.*.override_flag' => ['required', 'boolean'],
            'items.*.override_quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        $mainId = (int) $data['so_domestic_raw_sugar_id'];
        $companyId = (int) $data['company_id'];

        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', $mainId)
            ->where('company_id', $companyId)
            ->first();

        if (!$main) {
            return response()->json(['message' => 'Sales Invoice main not found.'], 404);
        }

        if ((bool) ($main->posted_flag ?? false)) {
            return response()->json(['message' => 'Cannot update quedans. Sales Invoice is already posted.'], 409);
        }

        return DB::transaction(function () use ($data, $mainId, $companyId, $request) {
            $totalQty = 0;
            $incomingQuedans = [];

            foreach ($data['items'] as $item) {
                $quedanNo = trim((string) $item['quedan_no']);
                $originalQty = (float) $item['original_quantity'];
                $selectedFlag = (bool) $item['selected_flag'];
                $overrideFlag = (bool) $item['override_flag'];
                $overrideQty = $item['override_quantity'] === null ? null : (float) $item['override_quantity'];

                $otherUsedQty = (float) DB::table('so_domestic_raw_sugar_quedans')
                    ->where('company_id', $companyId)
                    ->where('quedan_no', $quedanNo)
                    ->where('selected_flag', true)
                    ->where('so_domestic_raw_sugar_id', '<>', $mainId)
                    ->sum('selected_quantity');

                $availableQty = max(0, $originalQty - $otherUsedQty);

                if ($overrideFlag) {
                    if ($overrideQty === null) {
                        return response()->json([
                            'message' => "Override quantity is required for quedan {$quedanNo}.",
                        ], 422);
                    }

                    if ($overrideQty > $availableQty) {
                        return response()->json([
                            'message' => "Override quantity for quedan {$quedanNo} cannot exceed remaining quantity {$availableQty}.",
                        ], 422);
                    }
                }

                $selectedQty = $selectedFlag
                    ? ($overrideFlag ? (float) $overrideQty : $availableQty)
                    : 0;

                if ($selectedFlag && $selectedQty > $availableQty) {
                    return response()->json([
                        'message' => "Selected quantity for quedan {$quedanNo} cannot exceed remaining quantity {$availableQty}.",
                    ], 422);
                }

                $incomingQuedans[] = $quedanNo;
                $totalQty += $selectedQty;

                DB::table('so_domestic_raw_sugar_quedans')->updateOrInsert(
                    [
                        'company_id' => $companyId,
                        'so_domestic_raw_sugar_id' => $mainId,
                        'quedan_no' => $quedanNo,
                    ],
                    [
                        'receipt_no' => $item['receipt_no'] ?? null,
                        'planter_tin' => $item['planter_tin'] ?? null,
                        'planter_name' => $item['planter_name'] ?? null,
                        'original_quantity' => $originalQty,
                        'selected_quantity' => $selectedQty,
                        'override_flag' => $overrideFlag,
                        'override_quantity' => $overrideFlag ? $overrideQty : null,
                        'selected_flag' => $selectedFlag,
                        'workstation_id' => $request->ip(),
                        'user_id' => auth()->id(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            DB::table('so_domestic_raw_sugar_quedans')
                ->where('company_id', $companyId)
                ->where('so_domestic_raw_sugar_id', $mainId)
                ->whereNotIn('quedan_no', $incomingQuedans)
                ->update([
                    'selected_flag' => false,
                    'selected_quantity' => 0,
                    'override_flag' => false,
                    'override_quantity' => null,
                    'updated_at' => now(),
                ]);

            DB::table('so_domestic_raw_sugar')
                ->where('id', $mainId)
                ->where('company_id', $companyId)
                ->update([
                    'quantity' => $totalQty,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Selected quedans saved.',
                'quantity' => $totalQty,
            ]);
        });
    }


    public function postTransaction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
        ]);

        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', $data['id'])
            ->where('company_id', $data['company_id'])
            ->first();

        if (!$main) {
            return response()->json(['message' => 'Sales Invoice not found.'], 404);
        }

        DB::table('so_domestic_raw_sugar')
            ->where('id', $data['id'])
            ->where('company_id', $data['company_id'])
            ->update([
                'posted_flag' => true,
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Sales Invoice posted.']);
    }

    public function processTransaction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
        ]);

        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', $data['id'])
            ->where('company_id', $data['company_id'])
            ->first();

        if (!$main) {
            return response()->json(['message' => 'Sales Invoice not found.'], 404);
        }

        if (!(bool) ($main->posted_flag ?? false)) {
            return response()->json(['message' => 'Please post the Sales Invoice first before processing.'], 409);
        }

        $gross = round(((float) $main->quantity) * ((float) $main->selling_price), 2);
        $wtax = (bool) ($main->withholding_tax_flag ?? false) ? round($gross * 0.01, 2) : 0;
        $arDebit = round($gross - $wtax, 2);

        $acctCodes = ['1502', '1101', '5023'];

        $accounts = DB::table('account_code')
            ->where('company_id', (int) $data['company_id'])
            ->whereIn('acct_code', $acctCodes)
            ->get(['acct_code', 'acct_desc'])
            ->keyBy('acct_code');

        foreach ($acctCodes as $code) {
            if (!$accounts->has($code)) {
                return response()->json([
                    'message' => "Account code {$code} not found in account_code.",
                ], 422);
            }
        }

        $lines = [
            [
                'line_no' => 1,
                'acct_code' => '1502',
                'acct_desc' => $accounts['1502']->acct_desc,
                'debit' => $arDebit,
                'credit' => 0,
                'remarks' => 'Accounts Receivable / Customer Debit',
            ],
        ];

        if ($wtax > 0) {
            $lines[] = [
                'line_no' => 2,
                'acct_code' => '1101',
                'acct_desc' => $accounts['1101']->acct_desc,
                'debit' => $wtax,
                'credit' => 0,
                'remarks' => 'Withholding Tax 1%',
            ];
        }

        $lines[] = [
            'line_no' => count($lines) + 1,
            'acct_code' => '5023',
            'acct_desc' => $accounts['5023']->acct_desc,
            'debit' => 0,
            'credit' => $gross,
            'remarks' => 'Sales Credit',
        ];

        return DB::transaction(function () use ($data, $lines) {
            DB::table('so_domestic_raw_sugar_process_entries')
                ->where('so_domestic_raw_sugar_id', $data['id'])
                ->where('company_id', $data['company_id'])
                ->delete();

            foreach ($lines as $line) {
                DB::table('so_domestic_raw_sugar_process_entries')->insert([
                    'so_domestic_raw_sugar_id' => $data['id'],
                    'company_id' => $data['company_id'],
                    'line_no' => $line['line_no'],
                    'acct_code' => $line['acct_code'],
                    'acct_desc' => $line['acct_desc'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'remarks' => $line['remarks'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('so_domestic_raw_sugar')
                ->where('id', $data['id'])
                ->where('company_id', $data['company_id'])
                ->update([
                    'processed_flag' => true,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Sales Invoice processed.',
                'entries' => $lines,
            ]);
        });
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $rows = DB::table('payment_method')
            ->orderBy('pay_method')
            ->get([
                'id',
                'pay_method_id',
                'pay_method',
            ]);

        return response()->json($rows);
    }

    public function banks(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');

        $rows = DB::table('bank')
            ->where('company_id', $companyId)
            ->orderBy('bank_name')
            ->get([
                'id',
                'bank_id',
                'bank_name',
            ]);

        return response()->json($rows);
    }

    public function processToSalesJournal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],

            'user_id' => ['nullable', 'integer'],
        ]);

        $companyId = (int) $data['company_id'];
        $sourceId = (int) $data['id'];

        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', $sourceId)
            ->where('company_id', $companyId)
            ->first();

        if (!$main) {
            return response()->json(['message' => 'Sales Invoice not found.'], 404);
        }

        if (!(bool) ($main->processed_flag ?? false)) {
            return response()->json([
                'message' => 'Please click Process first before processing to Sales Journal.',
            ], 409);
        }



        $customer = DB::table('customer_list')
            ->whereRaw('CAST(company_id as text) = ?', [(string) $companyId])
            ->whereRaw('LOWER(TRIM(cust_name)) = LOWER(TRIM(?))', [(string) ($main->buyer_name ?? '')])
            ->first();

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found in customer_list for buyer name: ' . (string) ($main->buyer_name ?? ''),
            ], 422);
        }

        $entries = DB::table('so_domestic_raw_sugar_process_entries')
            ->where('so_domestic_raw_sugar_id', $sourceId)
            ->where('company_id', $companyId)
            ->orderBy('line_no')
            ->get();

        if ($entries->isEmpty()) {
            return response()->json([
                'message' => 'No processed accounting entries found. Click Process first.',
            ], 422);
        }

        $sumDebit = round((float) $entries->sum('debit'), 2);
        $sumCredit = round((float) $entries->sum('credit'), 2);

        if (abs($sumDebit - $sumCredit) > 0.005) {
            return response()->json([
                'message' => 'Cannot process to Sales Journal because entries are not balanced.',
            ], 422);
        }

        $existing = DB::table('cash_sales')
            ->where('company_id', $companyId)
            ->where('si_no', (string) $main->si_no)
            ->where(function ($q) {
                $q->whereNull('is_cancel')
                  ->orWhereNotIn('is_cancel', ['d', 'c', 'y']);
            })
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This Sales Invoice is already processed to Sales Journal. CS No: ' . $existing->cs_no,
            ], 409);
        }

        return DB::transaction(function () use ($request, $data, $companyId, $main, $entries, $sumDebit, $sumCredit) {
            $last = DB::table('cash_sales')
                ->where('company_id', $companyId)
                ->orderBy('cs_no', 'desc')
                ->lockForUpdate()
                ->value('cs_no');

            $base = is_numeric($last) ? (int) $last : 100000;
            $csNo = (string) ($base + 1);

            $qty = (float) ($main->quantity ?? 0);
            $price = (float) ($main->selling_price ?? 0);
            $gross = round($qty * $price, 2);

            $explanation = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')
                . ' Bags @ '
                . number_format($price, 2, '.', '');

            $cashSalesId = DB::table('cash_sales')->insertGetId([
                'cs_no' => $csNo,
                'cust_id' => (string) DB::table('customer_list')
                    ->whereRaw('CAST(company_id as text) = ?', [(string) $companyId])
                    ->whereRaw('LOWER(TRIM(cust_name)) = LOWER(TRIM(?))', [(string) ($main->buyer_name ?? '')])
                    ->value('cust_id'),

                'si_no' => (string) ($main->si_no ?? ''),
                'sales_date' => $main->si_date ?? now()->toDateString(),
                'sales_amount' => $gross,

                'pay_method' => null,
                'bank_id' => null,
                'check_ref_no' => null,

                'explanation' => $explanation,
                'amount_in_words' => $this->amountToWords($gross),
                'booking_no' => null,
                'is_cancel' => 'n',

                'workstation_id' => $request->ip(),
                'user_id' => $data['user_id'] ?? auth()->id(),
                'company_id' => $companyId,

                'sum_debit' => $sumDebit,
                'sum_credit' => $sumCredit,
                'is_balanced' => true,

                'exported_at' => null,
                'exported_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($entries as $entry) {
                DB::table('cash_sales_details')->insert([
                    'transaction_id' => (string) $cashSalesId,
                    'acct_code' => (string) $entry->acct_code,
                    'debit' => round((float) $entry->debit, 2),
                    'credit' => round((float) $entry->credit, 2),
                    'workstation_id' => $request->ip(),
                    'user_id' => $data['user_id'] ?? auth()->id(),
                    'company_id' => $companyId,
                    'updated_at' => now(),
                ]);
            }

            DB::table('so_domestic_raw_sugar')
                ->where('id', (int) $main->id)
                ->where('company_id', $companyId)
                ->update([
                    'processed_flag' => true,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Processed to Sales Journal.',
                'cash_sales_id' => $cashSalesId,
                'cs_no' => $csNo,
            ]);
        });
    }

    private function amountToWords(float $amount): string
    {
        $amount = round($amount, 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);

        $words = trim($this->numberToWords($whole)) . ' PESOS';

        if ($cents > 0) {
            $words .= ' AND ' . trim($this->numberToWords($cents)) . ' CENTAVOS';
        }

        return strtoupper($words . ' ONLY');
    }

    private function numberToWords(int $number): string
    {
        if ($number === 0) return 'ZERO';

        $ones = [
            '', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE',
            'TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN',
            'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'
        ];

        $tens = [
            '', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'
        ];

        if ($number < 20) {
            return $ones[$number];
        }

        if ($number < 100) {
            return $tens[intdiv($number, 10)] . (($number % 10) ? ' ' . $ones[$number % 10] : '');
        }

        if ($number < 1000) {
            return $ones[intdiv($number, 100)] . ' HUNDRED' . (($number % 100) ? ' ' . $this->numberToWords($number % 100) : '');
        }

        if ($number < 1000000) {
            return $this->numberToWords(intdiv($number, 1000)) . ' THOUSAND' . (($number % 1000) ? ' ' . $this->numberToWords($number % 1000) : '');
        }

        if ($number < 1000000000) {
            return $this->numberToWords(intdiv($number, 1000000)) . ' MILLION' . (($number % 1000000) ? ' ' . $this->numberToWords($number % 1000000) : '');
        }

        return $this->numberToWords(intdiv($number, 1000000000)) . ' BILLION' . (($number % 1000000000) ? ' ' . $this->numberToWords($number % 1000000000) : '');
    }

    public function blDropdown(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $poNo = trim((string) $request->query('po_no', ''));

        $rows = DB::table('bill_of_lading')
            ->where('company_id', $companyId)
            ->where(function ($q) {
                $q->whereNull('delete_flag')->orWhere('delete_flag', false);
            })
            ->when($poNo !== '', function ($q) use ($poNo) {
                $q->where('po_no', $poNo);
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get([
                'id',
                'po_no',
                'bl_no',
                'bl_date',
                'vendor_code',
                'vendor_name',
            ]);

        return response()->json($rows);
    }

    public function customerDropdown(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $q = trim((string) $request->query('q', ''));

        $rows = DB::table('customer_list')
            ->select([
                'id',
                DB::raw('COALESCE(cust_id, \'\') as cust_id'),
                DB::raw('COALESCE(cust_name, \'\') as cust_name'),
            ])
            ->whereRaw('CAST(company_id as text) = ?', [(string) $companyId])
            ->when($q !== '', function ($qq) use ($q) {
                $like = '%' . strtolower($q) . '%';

                $qq->where(function ($w) use ($like) {
                    $w->whereRaw('LOWER(CAST(COALESCE(cust_id, \'\') as text)) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(CAST(COALESCE(cust_name, \'\') as text)) LIKE ?', [$like]);
                });
            })
            ->orderBy('cust_name')
            ->limit(200)
            ->get();

        return response()->json($rows);
    }

    public function millDropdown(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');

        $rows = DB::table('mill_list')
            ->where('company_id', $companyId)
            ->orderBy('mill_name')
            ->get([
                'id',
                'mill_id',
                'mill_name',
                'prefix',
            ]);

        return response()->json($rows);
    }


public function formPdf(Request $request, $id = null)
{
    @ini_set('zlib.output_compression', '0');
    try { while (ob_get_level() > 0) { @ob_end_clean(); } } catch (\Throwable $e) {}

    if (!class_exists('\TCPDF', false)) {
        $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
        if (file_exists($tcpdfPath)) {
            require_once $tcpdfPath;
        }
    }

    $companyId = (int) ($request->query('company_id') ?: $request->header('X-Company-ID') ?: 0);

    $main = null;
    if ($id && $id !== 'template') {
        $main = DB::table('so_domestic_raw_sugar')
            ->where('id', (int) $id)
            ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
            ->first();
    }

    $siRaw = (string) ($main->si_no ?? '0501');
    $siDigits = preg_replace('/\D+/', '', $siRaw);
    $siNo = $siDigits !== '' ? str_pad($siDigits, 4, '0', STR_PAD_LEFT) : '0501';

    $siDate = !empty($main?->si_date) ? date('m/d/Y', strtotime((string) $main->si_date)) : '';

    $buyerName = (string) ($main->buyer_name ?? '');
    $tin = (string) ($main->tin ?? '');
    $buyerAddress = (string) ($main->buyer_address ?? '');

    $qty = (float) ($main->quantity ?? 0);
    $unitPrice = (float) ($main->selling_price ?? 0);
    $amount = round($qty * $unitPrice, 2);

    $isWithholding = (bool) ($main->withholding_tax_flag ?? false);
    $isVatExemptSales = !$isWithholding;

    // Domestic Raw Sugar SI currently has no VAT checkbox in frontend.
    // Therefore VATable/VAT Amount/Net of VAT/Add VAT are blank.
    $vatableSales = 0;
    $vatAmount = 0;
    $vatExemptSales = $isVatExemptSales ? $amount : 0;

    // W/Tax is always computed as 1% of gross sales.
    $withholdingTax = $isWithholding ? round($amount * 0.01, 2) : 0;
    $totalDue = round($amount - $withholdingTax, 2);

    $money = fn ($n) => ((float) $n == 0.0) ? '' : number_format((float) $n, 2);

    $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetCreator('Sucden');
    $pdf->SetAuthor('Sucden Philippines Inc.');
    $pdf->SetTitle('Sales Invoice');
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddPage('P', 'LETTER');

    $x = 12;
    $w = 192;

    /*
    |--------------------------------------------------------------------------
    | HEADER
    |--------------------------------------------------------------------------
    */

    $pdf->SetDrawColor(0, 85, 160);
    $pdf->SetTextColor(0, 85, 160);
    $pdf->SetLineWidth(0.7);

    $logoX = 8.5;
    $logoY = 8.5;
    $logoW = 21.5;
    $logoH = 21.5;

    $pdf->Rect($logoX, $logoY, $logoW, $logoH);
    $pdf->SetFont('helvetica', 'B', 17);
    $pdf->SetXY($logoX + 1, $logoY + 6.2);
    $pdf->Cell($logoW - 2, 7, 'S&D', 0, 0, 'C');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);

    $pdf->SetFont('helvetica', '', 12.5);
    $pdf->SetXY(35, 8.5);
    $pdf->Cell(90, 5.5, 'SUCDEN PHILIPINES INC.', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 8.8);
    $pdf->SetXY(35, 16.2);
    $pdf->Cell(110, 4, 'VAT Reg. TIN: 000-105-267-00000', 0, 1, 'L');

    $pdf->SetFont('helvetica', 'B', 6.7);
    $pdf->SetXY(35, 21.3);
    $pdf->Cell(128, 3.2, 'UNIT 2202 THE PODIUM WEST TOWER 12 ADB AVENUE ORTIGAS CENTER WACK WACK', 0, 1, 'L');

    $pdf->SetXY(35, 25.0);
    $pdf->Cell(128, 3.2, 'GREENHILLS, 1550 CITY OF MANDALUYONG NCR, SECOND DISTRICT PHILIPPINES', 0, 1, 'L');

    if ($isVatExemptSales) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(148, 6.5);
        $pdf->Cell(55, 5, 'EXEMPT SALES', 0, 1, 'C');
    }

    $pdf->SetFont('helvetica', 'B', 17);
    $pdf->SetXY(145, 12.0);
    $pdf->Cell(28, 7, 'SALES', 0, 0, 'R');

    $pdf->SetFont('helvetica', 'B', 24);
    $pdf->SetXY(174, 10.2);
    $pdf->Cell(40, 9, 'INVOICE', 0, 1, 'L');

    $pdf->SetFont('times', '', 19);
    $pdf->SetXY(162, 29.2);
    $pdf->Cell(42, 8, 'No.  ' . $siNo, 0, 1, 'L');

    /*
    |--------------------------------------------------------------------------
    | CASH / CHARGE + DATE
    |--------------------------------------------------------------------------
    */

    $pdf->SetFont('helvetica', '', 5.5);
    $pdf->SetLineWidth(0.25);

    $pdf->Rect(14, 48, 3.5, 3.5);
    $pdf->SetXY(18, 48);
    $pdf->Cell(15, 3.5, 'CASH SALES', 0, 0, 'L');

    $pdf->Rect(34, 48, 3.5, 3.5);
    $pdf->SetXY(38, 48);
    $pdf->Cell(18, 3.5, 'CHARGE SALES', 0, 0, 'L');

    $pdf->SetXY(154, 49);
    $pdf->Cell(9, 3, 'Date:', 0, 0, 'R');
    $pdf->Line(164, 51.5, 205, 51.5);

    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetXY(165, 47.5);
    $pdf->Cell(38, 4, $siDate, 0, 0, 'L');

    /*
    |--------------------------------------------------------------------------
    | SOLD TO BOX
    |--------------------------------------------------------------------------
    */

    $pdf->SetLineWidth(0.25);
    $pdf->Rect($x, 54, $w, 37);
    $pdf->Line($x, 62, $x + $w, 62);
    $pdf->Line($x, 70, $x + $w, 70);
    $pdf->Line($x, 78, $x + $w, 78);

    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY($x + 1, 55);
    $pdf->Cell(40, 6, 'SOLD TO:', 0, 0, 'L');

    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetXY($x + 1, 64);
    $pdf->Cell(35, 4, 'Registered Name:', 0, 0, 'L');
    $pdf->SetXY($x + 38, 64);
    $pdf->Cell(145, 4, $buyerName, 0, 0, 'L');

    $pdf->SetXY($x + 1, 72);
    $pdf->Cell(15, 4, 'TIN:', 0, 0, 'L');
    $pdf->SetXY($x + 20, 72);
    $pdf->Cell(145, 4, $tin, 0, 0, 'L');

    $pdf->SetXY($x + 1, 80);
    $pdf->Cell(30, 4, 'Business Address:', 0, 0, 'L');
    $pdf->SetXY($x + 38, 80);
    $pdf->MultiCell(145, 4, $buyerAddress, 0, 'L');

    /*
    |--------------------------------------------------------------------------
    | ITEM TABLE
    |--------------------------------------------------------------------------
    */

    $tableY = 93;
    $tableH = 86;

    $descW = 112;
    $qtyW = 24;
    $priceW = 24;
    $amtW = 32;
    $headerH = 14;

    // Important aligned x positions
    $mainDescLineX = $x + $descW;                         // 124
    $mainQtyLineX = $x + $descW + $qtyW;                  // 148
    $mainAmountLineX = $x + $descW + $qtyW + $priceW;     // 172

    $pdf->SetLineWidth(0.35);
    $pdf->Rect($x, $tableY, $w, $tableH);

    $pdf->Line($mainDescLineX, $tableY, $mainDescLineX, $tableY + $tableH);
    $pdf->Line($mainQtyLineX, $tableY, $mainQtyLineX, $tableY + $tableH);
    $pdf->Line($mainAmountLineX, $tableY, $mainAmountLineX, $tableY + $tableH);

    $pdf->Line($x, $tableY + $headerH, $x + $w, $tableY + $headerH);

    for ($i = 1; $i <= 11; $i++) {
        $yy = $tableY + $headerH + ($i * 6.0);
        if ($yy < ($tableY + $tableH)) {
            $pdf->Line($x, $yy, $x + $w, $yy);
        }
    }

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY($x + 40, $tableY + 2);
    $pdf->MultiCell(60, 5, "ITEM  DESCRIPTION /\nNATURE OF SERVICE", 0, 'C');

    $pdf->SetFont('times', 'B', 10);
    $pdf->SetXY($mainDescLineX, $tableY + 5);
    $pdf->Cell($qtyW, 5, 'QUANTITY', 0, 0, 'C');

    $pdf->SetXY($mainQtyLineX, $tableY + 3);
    $pdf->MultiCell($priceW, 5, "UNIT\nPRICE", 0, 'C');

    $pdf->SetXY($mainAmountLineX, $tableY + 5);
    $pdf->Cell($amtW, 5, 'AMOUNT', 0, 0, 'C');

    if ($main) {
        $lineY = $tableY + $headerH + 2;

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY($x + 2, $lineY);
        $pdf->Cell($descW - 4, 5, 'RAW SUGAR', 0, 0, 'L');

        $pdf->SetXY($mainDescLineX + 1, $lineY);
        $pdf->Cell($qtyW - 2, 5, $qty ? number_format($qty, 2) : '', 0, 0, 'R');

        $pdf->SetXY($mainQtyLineX + 1, $lineY);
        $pdf->Cell($priceW - 2, 5, $unitPrice ? number_format($unitPrice, 2) : '', 0, 0, 'R');

        $pdf->SetXY($mainAmountLineX + 1, $lineY);
        $pdf->Cell($amtW - 2, 5, $amount ? number_format($amount, 2) : '', 0, 0, 'R');
    }

    /*
    |--------------------------------------------------------------------------
    | BOTTOM TAX / TOTALS / SIGNATURE / FOOTER AREA
    |--------------------------------------------------------------------------
    */

    // This Y is intentionally lower to match your screen 3.
    $bottomY = 181;

    /*
     * LEFT TAX BOX
     * Its separator is aligned with main description vertical line at x = 124.
     */
    $leftBoxX = 16;
    $leftBoxY = $bottomY;
    $leftBoxRightX = 114;
    $leftBoxW = $leftBoxRightX - $leftBoxX;
    $leftBoxH = 36;
    $leftSeparatorX = 65;

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);

    $pdf->Rect($leftBoxX, $leftBoxY, $leftBoxW, $leftBoxH);
    $pdf->Line($leftBoxX, $leftBoxY + 9, $leftBoxRightX, $leftBoxY + 9);
    $pdf->Line($leftBoxX, $leftBoxY + 18, $leftBoxRightX, $leftBoxY + 18);
    $pdf->Line($leftBoxX, $leftBoxY + 27, $leftBoxRightX, $leftBoxY + 27);
    $pdf->Line($leftSeparatorX, $leftBoxY, $leftSeparatorX, $leftBoxY + $leftBoxH);

    $pdf->SetFont('helvetica', '', 8);

    $pdf->SetXY($leftBoxX + 2, $leftBoxY + 2);
    $pdf->Cell(45, 5, 'VATable Sales', 0, 0, 'L');
    $pdf->SetXY($leftSeparatorX + 2, $leftBoxY + 2);
    $pdf->Cell($leftBoxRightX - $leftSeparatorX - 4, 5, $money($vatableSales), 0, 0, 'R');

    $pdf->SetXY($leftBoxX + 2, $leftBoxY + 11);
    $pdf->Cell(45, 5, 'VAT Amount', 0, 0, 'L');
    $pdf->SetXY($leftSeparatorX + 2, $leftBoxY + 11);
    $pdf->Cell($leftBoxRightX - $leftSeparatorX - 4, 5, $money($vatAmount), 0, 0, 'R');

    $pdf->SetXY($leftBoxX + 2, $leftBoxY + 20);
    $pdf->Cell(45, 5, 'Zero Rated Sales', 0, 0, 'L');

    $pdf->SetXY($leftBoxX + 2, $leftBoxY + 29);
    $pdf->Cell(45, 5, 'VAT-Exempt Sales', 0, 0, 'L');
    $pdf->SetXY($leftSeparatorX + 2, $leftBoxY + 29);
    $pdf->Cell($leftBoxRightX - $leftSeparatorX - 4, 5, $money($vatExemptSales), 0, 0, 'R');

    /*
     * RIGHT TOTALS BOX
     * Critical fix:
     * The vertical separator of this box is EXACTLY aligned with the
     * main item table's amount separator at x = 172.
     */
    $rightX = 126;
    $rightY = $bottomY;
    $rightRightX = 204;
    $rightW = $rightRightX - $rightX;
    $rightH = 44;
    $rightSeparatorX = $mainAmountLineX; // EXACTLY 172

    $pdf->Rect($rightX, $rightY, $rightW, $rightH);

    for ($i = 1; $i <= 6; $i++) {
        $lineY = $rightY + ($i * 7.33);
        $pdf->Line($rightX, $lineY, $rightRightX, $lineY);
    }

    $pdf->Line($rightSeparatorX, $rightY, $rightSeparatorX, $rightY + $rightH);

    $labels = [
        'Total Sales (VAT Inclusive)' => $amount,
        'Less : VAT' => 0,
        'Amount : Net of VAT' => 0,
        'Add : VAT' => 0,
        'Less: Withholding Tax' => $withholdingTax,
        'TOTAL AMOUNT DUE' => $totalDue,
    ];

    $yy = $rightY + 1.2;
    foreach ($labels as $label => $val) {
        $isTotal = $label === 'TOTAL AMOUNT DUE';

        $pdf->SetFont('helvetica', $isTotal ? 'B' : '', $isTotal ? 9 : 7);

        $pdf->SetXY($rightX + 2, $yy);
        $pdf->Cell($rightSeparatorX - $rightX - 4, 5, $label, 0, 0, 'R');

        $pdf->SetXY($rightSeparatorX + 2, $yy);
        $pdf->Cell($rightRightX - $rightSeparatorX - 4, 5, $money($val), 0, 0, 'R');

        $yy += 7.33;
    }

    /*
    |--------------------------------------------------------------------------
    | RECEIVED / SIGNATURE AREA
    |--------------------------------------------------------------------------
    */

    $pdf->SetLineWidth(0.25);
    $pdf->Rect(16, 225, 6, 4);

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetXY(25, 224.2);
    $pdf->Cell(55, 5, 'Received the amount of', 0, 0, 'L');

    $pdf->Line(26, 237, 70, 237);

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetXY(16, 247.0);
    $pdf->Cell(8, 5, 'By:', 0, 0, 'L');

    $pdf->Line(25, 252.0, 95, 252.0);

    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetXY(38, 253.0);
    $pdf->Cell(45, 4, "Cashier's / Authorized Signature", 0, 0, 'C');

    /*
    |--------------------------------------------------------------------------
    | FOOTER WITH REAL BOTTOM BREATHING SPACE
    |--------------------------------------------------------------------------
    */

    // Border line above footer, not too low.
    // Border line above footer, moved up to create bottom margin.
    $pdf->SetLineWidth(0.35);
    $pdf->Line(12, 258, 204, 258);

    // Footer moved up; bottom margin is now preserved.
    $pdf->SetFont('courier', '', 5.0);

    $pdf->SetXY(12, 260.5);
    $pdf->MultiCell(
        86,
        2.1,
        "10Bkts(50X3)0501A-1000A\n" .
        "BIR Authority To Print No: OCN041ADB000000XXX\n" .
        "Date Issued: XX-XX-2025\n" .
        "Non-Vat Reg. Tin: 216-796-684-0000\n" .
        "Grace L. Restauro - Prop.",
        0,
        'L'
    );

    $pdf->SetXY(145, 260.5);
    $pdf->MultiCell(
        62,
        2.1,
        "275-A Sto. Rosario St., Brgy. Plainview, Mandaluyong City\n" .
        "Printers Accreditation No.: 041MP202300000003\n" .
        "Date of Issued: DEC 04, 2023\n" .
        "Date Expired: DEC 03, 2028\n" .
        "Mobile Nos.: 0915 425 7520 / 0947 837 1877",
        0,
        'L'
    );

    $bytes = $pdf->Output('', 'S');

    return response($bytes, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="sales-invoice.pdf"')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache');
}

    private function recalcQuantity(int $mainId, int $companyId): void
    {
        $qty = DB::table('so_domestic_raw_sugar_details')
            ->where('so_domestic_raw_sugar_id', $mainId)
            ->where('company_id', $companyId)
            ->where(function ($q) {
                $q->whereNull('delete_flag')->orWhere('delete_flag', false);
            })
            ->sum('quantity');

        DB::table('so_domestic_raw_sugar')
            ->where('id', $mainId)
            ->where('company_id', $companyId)
            ->update([
                'quantity' => $qty,
                'updated_at' => now(),
            ]);
    }
}