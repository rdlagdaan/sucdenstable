<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BillOfLadingController extends Controller
{
    private function companyIdFromRequest(Request $req): int
    {
        $companyId = (int) ($req->input('company_id') ?: $req->header('X-Company-ID') ?: 0);
        if ($companyId <= 0) {
            abort(422, 'Missing company_id (param or X-Company-ID header).');
        }
        return $companyId;
    }

    private function toDate($v): ?string
    {
        if (!$v) return null;
        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function money($v, int $decimals = 2): float
    {
        return round((float) ($v ?? 0), $decimals);
    }

    private function normalizeText($v): string
    {
        return trim((string) ($v ?? ''));
    }

    private function boolFlag($v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private function isActiveDeleteFlagQuery($query, string $tableAlias, string $tableName)
    {
        return $query->where(function ($w) use ($tableAlias, $tableName) {
            if (Schema::hasColumn($tableName, 'delete_flag')) {
                $w->whereNull($tableAlias . '.delete_flag')
                  ->orWhere($tableAlias . '.delete_flag', 0)
                  ->orWhere($tableAlias . '.delete_flag', false);
            } else {
                $w->whereRaw('1=1');
            }
        });
    }

    private function nextBlEntryNo(int $companyId): string
    {
        $maxId = (int) DB::table('bill_of_lading')
            ->where('company_id', $companyId)
            ->max('id');

        return 'BL-' . str_pad((string) ($maxId + 1), 6, '0', STR_PAD_LEFT);
    }

    private function formatBlEntryNo(int $id): string
    {
        return 'BL-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private function computeLineValues(array $row): array
    {
        $bags = (int) round((float) ($row['bags'] ?? 0));
        $mt = $this->money($bags / 20, 3);

        $cifPrice = $this->money($row['cif_price'] ?? 0, 6);
        $fxRate = $this->money($row['fx_rate'] ?? 0, 6);

        $cifUsd = $this->money($cifPrice * $mt, 2);
        $cifPhp = $this->money($cifUsd * $fxRate, 2);

        $dutiableValue = $this->money($row['dutiable_value'] ?? 0, 2);
        $duty = $this->money($dutiableValue * 0.05, 2);

        $brokerage = $this->money($row['brokerage'] ?? 0, 2);
        $wharfage = $this->money($row['wharfage'] ?? 0, 2);
        $arrastre = $this->money($row['arrastre'] ?? 0, 2);
        $otherCharges = $this->money($row['other_charges'] ?? 0, 2);
        $adjustment = $this->money($row['adjustment'] ?? 0, 2);

        $landedCost = $this->money(
            $dutiableValue +
            $duty +
            $brokerage +
            $wharfage +
            $arrastre +
            $otherCharges +
            $adjustment,
            2
        );

        $vat = $this->money($landedCost * 0.12, 2);
        $otherTaxes = $this->money($row['other_taxes'] ?? 0, 2);
        $bocTotal = $this->money($otherTaxes + $vat + $duty, 2);

        return [
            'bags'           => $bags,
            'mt'             => $mt,
            'cif_price'      => $cifPrice,
            'fx_rate'        => $fxRate,
            'cif_usd'        => $cifUsd,
            'cif_php'        => $cifPhp,
            'dutiable_value' => $dutiableValue,
            'duty'           => $duty,
            'brokerage'      => $brokerage,
            'wharfage'       => $wharfage,
            'arrastre'       => $arrastre,
            'other_charges'  => $otherCharges,
            'adjustment'     => $adjustment,
            'landed_cost'    => $landedCost,
            'vat'            => $vat,
            'other_taxes'    => $otherTaxes,
            'boc_total'      => $bocTotal,
        ];
    }

    private function amountToWords(float $amount): string
    {
        $amount = round($amount, 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);

        $words = $this->numberToWords($whole) . ' Pesos';
        if ($cents > 0) {
            $words .= ' and ' . $this->numberToWords($cents) . ' Centavos';
        }
        return strtoupper(trim($words . ' ONLY'));
    }

    private function numberToWords(int $num): string
    {
        $ones = [
            0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
            5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
            10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
            14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
            18 => 'Eighteen', 19 => 'Nineteen'
        ];

        $tens = [
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty',
            5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy',
            8 => 'Eighty', 9 => 'Ninety'
        ];

        if ($num < 20) {
            return $ones[$num];
        }

        if ($num < 100) {
            $ten = (int) floor($num / 10);
            $rem = $num % 10;
            return $tens[$ten] . ($rem ? ' ' . $ones[$rem] : '');
        }

        if ($num < 1000) {
            $hund = (int) floor($num / 100);
            $rem = $num % 100;
            return $ones[$hund] . ' Hundred' . ($rem ? ' ' . $this->numberToWords($rem) : '');
        }

        if ($num < 1000000) {
            $th = (int) floor($num / 1000);
            $rem = $num % 1000;
            return $this->numberToWords($th) . ' Thousand' . ($rem ? ' ' . $this->numberToWords($rem) : '');
        }

        if ($num < 1000000000) {
            $m = (int) floor($num / 1000000);
            $rem = $num % 1000000;
            return $this->numberToWords($m) . ' Million' . ($rem ? ' ' . $this->numberToWords($rem) : '');
        }

        $b = (int) floor($num / 1000000000);
        $rem = $num % 1000000000;
        return $this->numberToWords($b) . ' Billion' . ($rem ? ' ' . $this->numberToWords($rem) : '');
    }

    private function getBlHeaderOrFail(int $companyId, int $id)
    {
        return DB::table('bill_of_lading')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->firstOrFail();
    }

    private function getBlTotals(int $headerId): array
    {
        $totals = DB::table('bill_of_lading_line')
            ->where('bill_of_lading_id', $headerId)
            ->selectRaw('
                COALESCE(SUM(cif_php), 0) as cif_php,
                COALESCE(SUM(duty), 0) as duty,
                COALESCE(SUM(other_taxes), 0) as other_taxes,
                COALESCE(SUM(vat), 0) as vat
            ')
            ->first();

        $cifPhp = $this->money($totals->cif_php ?? 0, 2);
        $duty = $this->money($totals->duty ?? 0, 2);
        $otherTaxes = $this->money($totals->other_taxes ?? 0, 2);
        $vat = $this->money($totals->vat ?? 0, 2);
        $creditTotal = $this->money($cifPhp + $duty + $otherTaxes + $vat, 2);

        return [
            'cif_php'      => $cifPhp,
            'duty'         => $duty,
            'other_taxes'  => $otherTaxes,
            'vat'          => $vat,
            'credit_total' => $creditTotal,
        ];
    }

    private function getBlAccountingPreview(int $companyId, int $headerId): array
    {
        $totals = $this->getBlTotals($headerId);

        $codes = ['1204', '6042', '6052', '1501', '3025'];

        $accounts = DB::table('account_code')
            ->where('company_id', $companyId)
            ->whereIn('acct_code', $codes)
            ->when(Schema::hasColumn('account_code', 'active_flag'), function ($q) {
                $q->where(function ($w) {
                    $w->whereNull('active_flag')
                      ->orWhere('active_flag', 1);
                });
            })
            ->get()
            ->keyBy('acct_code');

        foreach ($codes as $code) {
            if (!isset($accounts[$code])) {
                abort(422, "Missing account_code setup for acct_code {$code}.");
            }
        }

        return [
            [
                'acct_code' => '1204',
                'acct_desc' => (string) $accounts['1204']->acct_desc,
                'debit'     => $totals['cif_php'],
                'credit'    => 0,
            ],
            [
                'acct_code' => '6042',
                'acct_desc' => (string) $accounts['6042']->acct_desc,
                'debit'     => $totals['duty'],
                'credit'    => 0,
            ],
            [
                'acct_code' => '6052',
                'acct_desc' => (string) $accounts['6052']->acct_desc,
                'debit'     => $totals['other_taxes'],
                'credit'    => 0,
            ],
            [
                'acct_code' => '1501',
                'acct_desc' => (string) $accounts['1501']->acct_desc,
                'debit'     => $totals['vat'],
                'credit'    => 0,
            ],
            [
                'acct_code' => '3025',
                'acct_desc' => (string) $accounts['3025']->acct_desc,
                'debit'     => 0,
                'credit'    => $totals['credit_total'],
            ],
        ];
    }

    private function getPbnDerivedFields(int $companyId, object $header): array
    {
        $pbn = DB::table('pbn_entry')
            ->where('company_id', $companyId)
            ->where('pbn_number', (string) $header->po_no)
            ->first();

        if (!$pbn) {
            abort(422, 'Matching PBN entry not found for this Bill of Lading.');
        }

        $firstLinkedLine = DB::table('bill_of_lading_line')
            ->where('bill_of_lading_id', $header->id)
            ->whereNotNull('purchase_order_line_id')
            ->orderBy('line_no')
            ->first();

        $millCode = null;

        if ($firstLinkedLine && (int) ($firstLinkedLine->purchase_order_line_id ?? 0) > 0) {
            $pbnDetail = DB::table('pbn_entry_details')
                ->where('id', (int) $firstLinkedLine->purchase_order_line_id)
                ->first();

            $millCode = $pbnDetail?->mill_code ?: null;
        }

        return [
            'booking_no' => $pbn->po_number ?? null,
            'crop_year'  => $pbn->crop_year ?? null,
            'sugar_type' => $pbn->sugar_type ?? null,
            'mill_id'    => $millCode,
        ];
    }

    public function poList(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);
        $q = trim((string) $req->get('q', ''));

        $rows = DB::table('pbn_entry as p')
            ->select([
                'p.id',
                DB::raw('p.pbn_number as po_no'),
                DB::raw('COALESCE(p.vend_code, \'\') as vendor_code'),
                DB::raw('COALESCE(p.vendor_name, \'\') as vendor_name'),
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

    public function poItems(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);

        $purchaseOrderId = (int) ($req->get('purchase_order_id') ?: 0);
        $poNo = trim((string) $req->get('po_no', ''));

        if ($purchaseOrderId <= 0 && $poNo === '') {
            return response()->json([]);
        }

        $headerQ = DB::table('pbn_entry as p')
            ->select(['p.id', 'p.pbn_number'])
            ->where('p.company_id', $companyId);

        if ($purchaseOrderId > 0) {
            $headerQ->where('p.id', $purchaseOrderId);
        } else {
            $headerQ->where('p.pbn_number', $poNo);
        }

        $header = $headerQ->first();

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
            ->where('d.pbn_entry_id', $header->id);

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
                    'id'               => (int) $r->id,
                    'row'              => (int) ($r->row ?? 0),
                    'pbn_entry_id'     => (int) ($r->pbn_entry_id ?? 0),
                    'pbn_number'       => (string) ($r->pbn_number ?? ''),
                    'particulars'      => $particulars,
                    'item_label'       => $particulars !== '' ? $particulars : ('Item ' . ((int) ($r->row ?? 0))),
                    'mill_code'        => (string) ($r->mill_code ?? ''),
                    'mill'             => (string) ($r->mill ?? ''),
                    'quantity'         => (float) ($r->quantity ?? 0),
                    'price'            => (float) ($r->price ?? 0),
                    'cost'             => (float) ($r->cost ?? 0),
                    'total_cost'       => (float) ($r->total_cost ?? 0),
                    'is_refined_sugar' => strtolower($particulars) === 'refined sugar',
                ];
            })
            ->values();

        return response()->json($rows);
    }

    public function list(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);
        $q = trim((string) $req->get('q', ''));

        $rows = DB::table('bill_of_lading as b')
            ->select([
                'b.id',
                DB::raw("('BL-' || lpad(CAST(b.id as text), 6, '0')) as bl_entry_no"),
                'b.po_no',
                'b.vendor_name',
                'b.bl_date',
                'b.status',
                DB::raw('0 as line_count'),
            ])
            ->where('b.company_id', $companyId)
            ->where(function ($w) {
                if (Schema::hasColumn('bill_of_lading', 'delete_flag')) {
                    $w->whereNull('b.delete_flag')
                      ->orWhere('b.delete_flag', 0)
                      ->orWhere('b.delete_flag', false);
                } else {
                    $w->whereRaw('1=1');
                }
            })
            ->when($q !== '', function ($qq) use ($q) {
                $like = '%' . strtolower($q) . '%';
                $qq->where(function ($w) use ($like) {
                    $w->whereRaw("LOWER(('BL-' || lpad(CAST(b.id as text), 6, '0'))) LIKE ?", [$like])
                      ->orWhereRaw('LOWER(CAST(COALESCE(b.po_no, \'\') as text)) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(CAST(COALESCE(b.vendor_name, \'\') as text)) LIKE ?', [$like]);
                });
            })
            ->orderByDesc('b.id')
            ->limit(200)
            ->get();

        return response()->json($rows);
    }

    public function getEntry(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);
        $id = (int) $req->get('id');

        $row = DB::table('bill_of_lading as b')
            ->select([
                'b.*',
                DB::raw("('BL-' || lpad(CAST(b.id as text), 6, '0')) as bl_entry_no"),
                DB::raw("COALESCE(b.processed_flag, false) as processed_flag"),
                'b.processed_by',
                'b.processed_at',
                'b.cash_purchase_id',
                'b.cp_no',
            ])
            ->where('b.company_id', $companyId)
            ->where('b.id', $id)
            ->firstOrFail();

        return response()->json($row);
    }

    public function getDetails(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);
        $id = (int) $req->get('id');

        $exists = DB::table('bill_of_lading')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->exists();

        if (!$exists) {
            abort(404, 'Bill of Lading header not found.');
        }

        $rows = DB::table('bill_of_lading_line')
            ->where('bill_of_lading_id', $id)
            ->orderBy('line_no')
            ->get()
            ->map(function ($r) {
                return [
                    'id'                     => $r->id,
                    'line_no'                => (int) $r->line_no,
                    'item_no'                => (int) ($r->item_no ?? 0),
                    'bl_no'                  => (string) ($r->bl_no ?? ''),
                    'mt'                     => (float) ($r->mt ?? 0),
                    'bags'                   => (float) ($r->bags ?? 0),
                    'cif_price'              => (float) ($r->cif_price ?? 0),
                    'cif_usd'                => (float) ($r->cif_usd ?? 0),
                    'fx_rate'                => (float) ($r->fx_rate ?? 0),
                    'cif_php'                => (float) ($r->cif_php ?? 0),
                    'sad_no'                 => (string) ($r->sad_no ?? ''),
                    'ssdt_no'                => (string) ($r->ssdt_no ?? ''),
                    'fan_no'                 => (string) ($r->fan_no ?? ''),
                    'registration_date'      => $r->registration_date ? date('Y-m-d', strtotime((string) $r->registration_date)) : '',
                    'assessment_date'        => $r->assessment_date ? date('Y-m-d', strtotime((string) $r->assessment_date)) : '',
                    'pay_date'               => $r->pay_date ? date('Y-m-d', strtotime((string) $r->pay_date)) : '',
                    'si_no'                  => (string) ($r->si_no ?? ''),
                    'dutiable_value'         => (float) ($r->dutiable_value ?? 0),
                    'duty'                   => (float) ($r->duty ?? 0),
                    'brokerage'              => (float) ($r->brokerage ?? 0),
                    'wharfage'               => (float) ($r->wharfage ?? 0),
                    'arrastre'               => (float) ($r->arrastre ?? 0),
                    'other_charges'          => (float) ($r->other_charges ?? 0),
                    'adjustment'             => (float) ($r->adjustment ?? 0),
                    'landed_cost'            => (float) ($r->landed_cost ?? 0),
                    'vat'                    => (float) ($r->vat ?? 0),
                    'other_taxes'            => (float) ($r->other_taxes ?? 0),
                    'boc_total'              => (float) ($r->boc_total ?? 0),
                    'remarks'                => (string) ($r->remarks ?? ''),
                    'purchase_order_line_id' => (int) ($r->purchase_order_line_id ?? 0),
                    'consumed_qty_mt'        => (float) ($r->consumed_qty_mt ?? 0),
                    'consumed_bags'          => (int) ($r->consumed_bags ?? 0),
                ];
            });

        return response()->json($rows);
    }

    public function createEntry(Request $req)
    {
        try {
            $companyId = $this->companyIdFromRequest($req);

            $v = $req->validate([
                'purchase_order_id' => 'required|integer',
                'po_no'             => 'required|string|max:50',
                'vendor_code'       => 'nullable|string|max:50',
                'vendor_name'       => 'required|string|max:200',
                'bl_date'           => 'nullable|date',
                'remarks'           => 'nullable|string',
                'user_id'           => 'nullable|integer',
                'workstation_id'    => 'nullable|string|max:100',
            ]);

            $existing = DB::table('bill_of_lading')
                ->where('company_id', $companyId)
                ->where('po_no', $v['po_no'])
                ->where(function ($w) {
                    $w->whereNull('delete_flag')
                      ->orWhere('delete_flag', false)
                      ->orWhere('delete_flag', 0);
                })
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $blEntryNo = $this->formatBlEntryNo((int) $existing->id);

                return response()->json([
                    'id'          => (int) $existing->id,
                    'bl_entry_no' => $blEntryNo,
                    'po_no'       => (string) ($existing->po_no ?? $v['po_no']),
                    'vendor_code' => $existing->vendor_code ?? ($v['vendor_code'] ?? null),
                    'vendor_name' => (string) ($existing->vendor_name ?? $v['vendor_name']),
                    'bl_date'     => $existing->bl_date ? $this->toDate($existing->bl_date) : $this->toDate($v['bl_date'] ?? null),
                    'remarks'     => $existing->remarks ?? ($v['remarks'] ?? null),
                    'reused'      => true,
                ], 200);
            }

            $id = DB::table('bill_of_lading')->insertGetId([
                'purchase_order_id' => (int) $v['purchase_order_id'],
                'po_no'             => $v['po_no'],
                'vendor_id'         => null,
                'vendor_code'       => $v['vendor_code'] ?? null,
                'vendor_name'       => $v['vendor_name'],
                'bl_no'             => null,
                'bl_date'           => $this->toDate($v['bl_date'] ?? null),
                'remarks'           => $v['remarks'] ?? null,
                'status'            => 'draft',
                'posted_flag'       => false,
                'closed_flag'       => false,
                'delete_flag'       => false,
                'visible_flag'      => 1,
                'company_id'        => $companyId,
                'workstation_id'    => $req->input('workstation_id') ?: $req->ip(),
                'created_by'        => (string) ($req->input('user_id') ?? '0'),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $blEntryNo = $this->formatBlEntryNo((int) $id);

            return response()->json([
                'id'          => $id,
                'bl_entry_no' => $blEntryNo,
                'po_no'       => $v['po_no'],
                'vendor_code' => $v['vendor_code'] ?? null,
                'vendor_name' => $v['vendor_name'],
                'bl_date'     => $this->toDate($v['bl_date'] ?? null),
                'remarks'     => $v['remarks'] ?? null,
                'reused'      => false,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('BillOfLading createEntry failed', [
                'message' => $e->getMessage(),
                'payload' => $req->all(),
            ]);

            return response()->json([
                'message' => 'Server Error',
                'debug'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateMain(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);

        $v = $req->validate([
            'id'      => 'required|integer',
            'bl_date' => 'nullable|date',
            'remarks' => 'nullable|string',
        ]);

        DB::table('bill_of_lading')
            ->where('company_id', $companyId)
            ->where('id', $v['id'])
            ->update([
                'bl_date'    => $this->toDate($v['bl_date'] ?? null),
                'remarks'    => $v['remarks'] ?? null,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    public function paymentMethods(Request $req)
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

    public function banks(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);

        $rows = DB::table('bank')
            ->where('company_id', $companyId)
            ->orderBy('bank_name')
            ->get([
                'id',
                'bank_id',
                'bank_name',
                'bank_account_number',
            ]);

        return response()->json($rows);
    }

    public function postEntry(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);

        $v = $req->validate([
            'id'      => 'required|integer',
            'user_id' => 'nullable',
        ]);

        $header = $this->getBlHeaderOrFail($companyId, (int) $v['id']);

        if ($this->boolFlag($header->delete_flag ?? false)) {
            abort(422, 'Deleted Bill of Lading cannot be posted.');
        }

        if ($this->boolFlag($header->processed_flag ?? false)) {
            abort(422, 'Processed Bill of Lading cannot be posted again.');
        }

        $lineCount = DB::table('bill_of_lading_line')
            ->where('bill_of_lading_id', $header->id)
            ->count();

        if ($lineCount <= 0) {
            abort(422, 'Cannot post Bill of Lading without saved shipment lines.');
        }

        DB::table('bill_of_lading')
            ->where('company_id', $companyId)
            ->where('id', $header->id)
            ->update([
                'posted_flag' => true,
                'posted_by'   => (string) ($req->input('user_id') ?? '0'),
                'posted_at'   => now(),
                'status'      => 'posted',
                'updated_at'  => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    public function processPreview(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);
        $id = (int) $req->get('id');

        $header = $this->getBlHeaderOrFail($companyId, $id);

        if (!$this->boolFlag($header->posted_flag ?? false)) {
            abort(422, 'Bill of Lading must be posted before processing.');
        }

        if ($this->boolFlag($header->processed_flag ?? false)) {
            abort(422, 'This Bill of Lading is already processed.');
        }

        $lines = $this->getBlAccountingPreview($companyId, $id);
        $totals = $this->getBlTotals($id);
        $derived = $this->getPbnDerivedFields($companyId, $header);
        $blEntryNo = $this->formatBlEntryNo((int) $header->id);

        return response()->json([
            'cp_no'           => $blEntryNo,
            'vend_id'         => (string) ($header->vendor_code ?? ''),
            'purchase_date'   => $header->bl_date ? $this->toDate($header->bl_date) : null,
            'explanation'     => (string) ($header->remarks ?? ''),
            'amount_in_words' => $this->amountToWords((float) $totals['credit_total']),
            'booking_no'      => $derived['booking_no'],
            'crop_year'       => $derived['crop_year'],
            'sugar_type'      => $derived['sugar_type'],
            'mill_id'         => $derived['mill_id'],
            'rr_no'           => $blEntryNo,
            'lines'           => $lines,
            'sum_debit'       => $totals['credit_total'],
            'sum_credit'      => $totals['credit_total'],
        ]);
    }

    public function processEntry(Request $req)
    {
        $companyId = $this->companyIdFromRequest($req);

        $v = $req->validate([
            'id'             => 'required|integer',
            'user_id'        => 'nullable',
            'payment_method' => 'required|string|max:10',
            'bank_id'        => 'required|string|max:25',
        ]);

        return DB::transaction(function () use ($companyId, $v, $req) {
            $header = $this->getBlHeaderOrFail($companyId, (int) $v['id']);

            if (!$this->boolFlag($header->posted_flag ?? false)) {
                abort(422, 'Bill of Lading must be posted before processing.');
            }

            if ($this->boolFlag($header->processed_flag ?? false)) {
                abort(422, 'This Bill of Lading is already processed.');
            }

            $lineCount = DB::table('bill_of_lading_line')
                ->where('bill_of_lading_id', $header->id)
                ->count();

            if ($lineCount <= 0) {
                abort(422, 'Cannot process Bill of Lading without saved shipment lines.');
            }

            $payMethod = DB::table('payment_method')
                ->where('pay_method_id', $v['payment_method'])
                ->first();

            if (!$payMethod) {
                abort(422, 'Invalid payment method.');
            }

            $bank = DB::table('bank')
                ->where('company_id', $companyId)
                ->where('bank_id', $v['bank_id'])
                ->first();

            if (!$bank) {
                abort(422, 'Invalid bank.');
            }

            $previewLines = $this->getBlAccountingPreview($companyId, $header->id);
            $totals = $this->getBlTotals($header->id);
            $derived = $this->getPbnDerivedFields($companyId, $header);
            $blEntryNo = $this->formatBlEntryNo((int) $header->id);

            $cashPurchaseId = DB::table('cash_purchase')->insertGetId([
                'cp_no'            => $blEntryNo,
                'vend_id'          => (string) ($header->vendor_code ?? ''),
                'purchase_date'    => $header->bl_date ? $this->toDate($header->bl_date) : null,
                'purchase_amount'  => $totals['credit_total'],
                'pay_method'       => (string) $v['payment_method'],
                'bank_id'          => (string) $v['bank_id'],
                'check_ref_no'     => null,
                'explanation'      => (string) ($header->remarks ?? ''),
                'amount_in_words'  => $this->amountToWords((float) $totals['credit_total']),
                'booking_no'       => $derived['booking_no'],
                'is_cancel'        => 'n',
                'crop_year'        => $derived['crop_year'],
                'sugar_type'       => $derived['sugar_type'],
                'mill_id'          => $derived['mill_id'],
                'rr_no'            => $blEntryNo,
                'workstation_id'   => $req->input('workstation_id') ?: $req->ip(),
                'user_id'          => $req->input('user_id') ?? 0,
                'company_id'       => $companyId,
                'sum_debit'        => $totals['credit_total'],
                'sum_credit'       => $totals['credit_total'],
                'is_balanced'      => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            foreach ($previewLines as $line) {
                DB::table('cash_purchase_details')->insert([
                    'transaction_id' => $cashPurchaseId,
                    'acct_code'      => (string) $line['acct_code'],
                    'debit'          => $this->money($line['debit'] ?? 0, 2),
                    'credit'         => $this->money($line['credit'] ?? 0, 2),
                    'workstation_id' => $req->input('workstation_id') ?: $req->ip(),
                    'user_id'        => $req->input('user_id') ?? 0,
                    'company_id'     => $companyId,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            DB::table('bill_of_lading')
                ->where('company_id', $companyId)
                ->where('id', $header->id)
                ->update([
                    'processed_flag'   => true,
                    'processed_by'     => (string) ($req->input('user_id') ?? '0'),
                    'processed_at'     => now(),
                    'cash_purchase_id' => $cashPurchaseId,
                    'cp_no'            => $blEntryNo,
                    'status'           => 'processed',
                    'updated_at'       => now(),
                ]);

            return response()->json([
                'ok'               => true,
                'cash_purchase_id' => $cashPurchaseId,
                'cp_no'            => $blEntryNo,
            ]);
        });
    }

    public function batchInsert(Request $req)
    {
        try {
            $companyId = $this->companyIdFromRequest($req);

            $headerId = (int) $req->get('bill_of_lading_id');
            $rowIdx   = (int) $req->get('row_index', 0);
            $row      = (array) $req->get('row', []);

            $header = DB::table('bill_of_lading')
                ->where('company_id', $companyId)
                ->where('id', $headerId)
                ->firstOrFail();

            $lineNo = $rowIdx + 1;
            $itemNo = isset($row['item_no']) && $row['item_no'] !== '' ? (int) $row['item_no'] : $lineNo;
            $purchaseOrderLineId = (int) ($row['purchase_order_line_id'] ?? 0);

            $computed = $this->computeLineValues($row);

            $record = [
                'bill_of_lading_id'    => $headerId,
                'line_no'              => $lineNo,
                'item_no'              => $itemNo,
                'bl_no'                => $this->normalizeText($row['bl_no'] ?? ''),
                'mt'                   => $computed['mt'],
                'bags'                 => $computed['bags'],
                'cif_price'            => $computed['cif_price'],
                'cif_usd'              => $computed['cif_usd'],
                'fx_rate'              => $computed['fx_rate'],
                'cif_php'              => $computed['cif_php'],
                'sad_no'               => $this->normalizeText($row['sad_no'] ?? ''),
                'ssdt_no'              => $this->normalizeText($row['ssdt_no'] ?? ''),
                'fan_no'               => $this->normalizeText($row['fan_no'] ?? ''),
                'registration_date'    => $this->toDate($row['registration_date'] ?? null),
                'assessment_date'      => $this->toDate($row['assessment_date'] ?? null),
                'pay_date'             => $this->toDate($row['pay_date'] ?? null),
                'si_no'                => $this->normalizeText($row['si_no'] ?? ''),
                'dutiable_value'       => $computed['dutiable_value'],
                'duty'                 => $computed['duty'],
                'brokerage'            => $computed['brokerage'],
                'wharfage'             => $computed['wharfage'],
                'arrastre'             => $computed['arrastre'],
                'other_charges'        => $computed['other_charges'],
                'adjustment'           => $computed['adjustment'],
                'landed_cost'          => $computed['landed_cost'],
                'vat'                  => $computed['vat'],
                'other_taxes'          => $computed['other_taxes'],
                'boc_total'            => $computed['boc_total'],
                'remarks'              => $this->normalizeText($row['remarks'] ?? ''),
                'purchase_order_line_id' => $purchaseOrderLineId > 0 ? $purchaseOrderLineId : null,
                'consumed_qty_mt'      => $computed['mt'],
                'consumed_bags'        => $computed['bags'],
                'updated_by'           => (string) ($req->input('user_id') ?? $header->created_by ?? '0'),
                'updated_at'           => now(),
            ];

            $existing = DB::table('bill_of_lading_line')
                ->where('bill_of_lading_id', $headerId)
                ->where('line_no', $lineNo)
                ->first();

            if ($existing) {
                DB::table('bill_of_lading_line')
                    ->where('id', $existing->id)
                    ->update($record);

                $id = $existing->id;
            } else {
                $record['created_by'] = (string) ($req->input('user_id') ?? $header->created_by ?? '0');
                $record['created_at'] = now();
                $id = DB::table('bill_of_lading_line')->insertGetId($record);
            }

            return response()->json([
                'id'                     => $id,
                'line_no'                => $lineNo,
                'item_no'                => $itemNo,
                'bl_no'                  => $record['bl_no'],
                'mt'                     => $record['mt'],
                'bags'                   => $record['bags'],
                'cif_price'              => $record['cif_price'],
                'cif_usd'                => $record['cif_usd'],
                'fx_rate'                => $record['fx_rate'],
                'cif_php'                => $record['cif_php'],
                'sad_no'                 => $record['sad_no'],
                'ssdt_no'                => $record['ssdt_no'],
                'fan_no'                 => $record['fan_no'],
                'registration_date'      => $record['registration_date'],
                'assessment_date'        => $record['assessment_date'],
                'pay_date'               => $record['pay_date'],
                'si_no'                  => $record['si_no'],
                'dutiable_value'         => $record['dutiable_value'],
                'duty'                   => $record['duty'],
                'brokerage'              => $record['brokerage'],
                'wharfage'               => $record['wharfage'],
                'arrastre'               => $record['arrastre'],
                'other_charges'          => $record['other_charges'],
                'adjustment'             => $record['adjustment'],
                'landed_cost'            => $record['landed_cost'],
                'vat'                    => $record['vat'],
                'other_taxes'            => $record['other_taxes'],
                'boc_total'              => $record['boc_total'],
                'remarks'                => $record['remarks'],
                'purchase_order_line_id' => $record['purchase_order_line_id'],
                'consumed_qty_mt'        => $record['consumed_qty_mt'],
                'consumed_bags'          => $record['consumed_bags'],
            ]);
        } catch (\Throwable $e) {
            Log::error('BillOfLading batchInsert failed', [
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'payload' => $req->all(),
            ]);

            return response()->json([
                'message' => 'Server Error',
                'debug'   => $e->getMessage(),
            ], 500);
        }
    }
}