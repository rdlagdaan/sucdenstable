<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReceivingPurchaseJournalService
{
    /**
     * ✅ CHANGE THESE CONSTANTS ONCE to match your real schema
     */
    private const TBL_RECEIVING_ENTRY   = 'receiving_entry';
    private const TBL_RECEIVING_DETAILS = 'receiving_details'; // adjust if yours is receiving_detail
    private const TBL_PBN_ENTRY         = 'pbn_entry';

    // Purchase Journal tables (adjust if different)
    private const TBL_CP_HDR = 'cash_purchase';
    private const TBL_CP_DTL = 'cash_purchase_details';

    // Detail column names (adjust if different)
    private const COL_QTY       = 'quantity';
    private const COL_UNIT_COST = 'unit_cost';
    private const COL_LIENS     = 'liens';
    private const COL_WEEK_END  = 'week_ending';   // if you use insurance_week/storage_week, map accordingly
private const COL_INSURANCE = 'insurance';
private const COL_STORAGE   = 'storage';
    // Posting tolerance
    private const BAL_TOL = 0.005;

    /**
     * Accounts based on legacy receiving report mapping.
     */
    public static function acctMap(string $sugarType): array
    {
        $s = strtoupper(trim($sugarType));

        return match ($s) {
            'A' => [
                'inventory' => '1201',
                'liens'     => '3031',
                'insurance' => '3041',
                'storage'   => '3051',
            ],
            'B' => [
                'inventory' => '1203',
                'liens'     => '3033',
                'insurance' => '3043',
                'storage'   => '3053',
            ],
            'C' => [
                'inventory' => '1202',
                'liens'     => '3032',
                'insurance' => '3042',
                'storage'   => '3052',
            ],
            'D' => [
                'inventory' => '1204',
                // ⚠️ If you have D mappings for these, replace:
                'liens'     => '3031',
                'insurance' => '3041',
                'storage'   => '3051',
            ],
            default => [
                'inventory' => '1201',
                'liens'     => '3031',
                'insurance' => '3041',
                'storage'   => '3051',
            ],
        } + [
            'withholding' => '3074',
            'assoc_due'   => '1401',
            'ap'          => '3023',
        ];
    }

    /**
     * Preview (no writes): returns lines + totals + balance info.
     */
    public function buildJournalPreview(int $companyId, int $receivingEntryId): array
    {
        $r = DB::table(self::TBL_RECEIVING_ENTRY . ' as r')
            ->leftJoin(self::TBL_PBN_ENTRY . ' as p', function ($j) use ($companyId) {
                $j->on('p.pbn_number', '=', 'r.pbn_number')
                  ->where('p.company_id', '=', $companyId);
            })
            ->where('r.company_id', $companyId)
            ->where('r.id', $receivingEntryId)
            ->select([
                'r.id','r.receipt_no','r.pbn_number','r.receipt_date','r.mill',
                'r.assoc_dues','r.no_insurance','r.insurance_week','r.no_storage','r.storage_week',
                'p.vendor_name','p.vend_code','p.sugar_type','p.crop_year',
            ])
            ->first();

        if (!$r) {
            throw new \RuntimeException('Receiving entry not found');
        }

        // details: you must ensure receiving_details contains the needed numeric columns
$details = DB::table(self::TBL_RECEIVING_DETAILS . ' as d')
    ->join(self::TBL_RECEIVING_ENTRY . ' as r2', 'r2.id', '=', 'd.receiving_entry_id')
    ->where('r2.company_id', $companyId)
    ->where('r2.id', $receivingEntryId)
    ->select('d.*')
    ->orderBy('d.row')
    ->get();


        // ---- Totals (legacy-inspired; adjust formulas to match your existing ReceivingController)
$totalQty      = 0.0;
$totalCost     = 0.0;
$totalLiens    = 0.0;
$totalInsurance= 0.0;
$totalStorage  = 0.0;

$skipInsurance = (bool)($r->no_insurance ?? false);
$skipStorage   = (bool)($r->no_storage ?? false);

foreach ($details as $d) {
    $qty  = (float)($d->{self::COL_QTY} ?? 0);
    $uc   = (float)($d->{self::COL_UNIT_COST} ?? 0);
    $li   = (float)($d->{self::COL_LIENS} ?? 0);

    $ins  = (float)($d->{self::COL_INSURANCE} ?? 0);
    $sto  = (float)($d->{self::COL_STORAGE} ?? 0);

    $totalQty   += $qty;
    $totalCost  += ($qty * $uc);
    $totalLiens += $li;

    if (!$skipInsurance) $totalInsurance += $ins;
    if (!$skipStorage)   $totalStorage   += $sto;
}


        // If you already compute insurance/storage elsewhere (ReceivingController), you should copy that exact computation here.
        // For now, keep them at 0 if your schema doesn't support them yet.
$totalInsurance = 0.0;
$totalStorage   = 0.0;

// ✅ If your receiving_details has per-line insurance/storage columns, sum them.
// If not, it will safely stay 0 (but then it won't match Screen2).
$hasInsCol = Schema::hasColumn(self::TBL_RECEIVING_DETAILS, 'insurance');
$hasStoCol = Schema::hasColumn(self::TBL_RECEIVING_DETAILS, 'storage');

if ($hasInsCol || $hasStoCol) {
    foreach ($details as $d) {
        if ($hasInsCol) $totalInsurance += (float)($d->insurance ?? 0);
        if ($hasStoCol) $totalStorage   += (float)($d->storage ?? 0);
    }
} else {
    // ✅ optional: log once so you know why preview doesn't match Screen2
    \Log::warning('ReceivingPurchaseJournalService: receiving_details has no insurance/storage columns, totals stay 0', [
        'table' => self::TBL_RECEIVING_DETAILS,
    ]);
}


        // Withholding = 1% of totalCost, truncated (legacy: floor(x*100)/100)
        $withholding = floor(($totalCost * 0.01) * 100) / 100;

        $assocDues = (float)($r->assoc_dues ?? 0);

        // AP = cost - (liens + insurance + storage)
        $grossAP = $totalCost - ($totalLiens + $totalInsurance + $totalStorage);

        // Net AP = grossAP - (withholding + assocDues)
        $netAP = $grossAP - ($withholding + $assocDues);

        $map = self::acctMap((string)($r->sugar_type ?? 'A'));

        // Journal lines (debit/credit)
        $lines = [];

        // DR Inventory = totalCost
        $lines[] = $this->line($map['inventory'], 'Inventory', $totalCost, 0);

        // CR Liens/Insurance/Storage
        if ($totalLiens > 0)     $lines[] = $this->line($map['liens'], 'Liens Payable', 0, $totalLiens);
        if ($totalInsurance > 0) $lines[] = $this->line($map['insurance'], 'Insurance Payable', 0, $totalInsurance);
        if ($totalStorage > 0)   $lines[] = $this->line($map['storage'], 'Storage Payable', 0, $totalStorage);

        // CR Withholding
        if ($withholding > 0)    $lines[] = $this->line($map['withholding'], 'Withholding Tax', 0, $withholding);

        // DR Assoc Due
// CR Assoc Due  ✅ (so it behaves like a deduction payable)
if ($assocDues > 0) $lines[] = $this->line($map['assoc_due'], 'Association Dues', 0, $assocDues);

        // CR AP (Net AP)
        if ($netAP != 0)         $lines[] = $this->line($map['ap'], 'Accounts Payable', 0, $netAP);

        $sumDr = 0.0; $sumCr = 0.0;
        foreach ($lines as $ln) { $sumDr += $ln['debit']; $sumCr += $ln['credit']; }

        $balanced = abs($sumDr - $sumCr) <= self::BAL_TOL;

        return [
            'receiving' => [
                'id'          => $r->id,
                'receipt_no'  => $r->receipt_no,
                'pbn_number'  => $r->pbn_number,
                'receipt_date'=> $r->receipt_date,
                'vendor_name' => $r->vendor_name ?? null,
                'vendor_code' => $r->vend_code ?? null,
                'sugar_type'  => $r->sugar_type ?? null,
                'crop_year'   => $r->crop_year ?? null,
                'mill'        => $r->mill ?? null,
            ],
'totals' => [
  'total_qty'       => $totalQty,
  'total_cost'      => $totalCost,
  'total_liens'     => $totalLiens,
  'total_insurance' => $totalInsurance,
  'total_storage'   => $totalStorage,
  'withholding'     => $withholding,
  'assoc_dues'      => $assocDues,
  'net_ap'          => $netAP,

  // keep old keys (your code already uses these)
  'sum_debit'       => $sumDr,
  'sum_credit'      => $sumCr,

  // ✅ add new keys expected by the modal
  'debit'           => $sumDr,
  'credit'          => $sumCr,

  'balanced'        => $balanced,
],

            'lines' => $lines,
        ];
    }

    private function line(string $acct, string $desc, float $dr, float $cr): array
    {
        return [
            'acct_code' => $acct,
            'acct_desc' => $desc,
            'debit'     => round($dr, 2),
            'credit'    => round($cr, 2),
        ];
    }

    /**
     * ✅ WRITE: create/update Purchase Journal for a receiving entry.
     * Returns cash_purchase_id.
     *
     * You MUST map the header/detail column names here to match your schema.
     */
public function upsertPurchaseJournalFromReceiving(
    int $companyId,
    int $receivingEntryId,
    int $approvedByUserId,
    string $workstationId,
    array $inputs
): int {
    $preview = $this->buildJournalPreview($companyId, $receivingEntryId);

    if (empty($preview['totals']['balanced'])) {
        throw new \RuntimeException('Cannot PROCESS: computed Purchase Journal is not balanced.');
    }

    // Required fields from approval screen
    $explanation  = trim((string)($inputs['explanation'] ?? ''));
    $bookingNo    = trim((string)($inputs['booking_no'] ?? ''));

if ($explanation === '') {
    throw new \RuntimeException('Missing required field: explanation.');
}


    $r     = $preview['receiving'];
    $lines = $preview['lines'];

    $sumDebit  = (float)($preview['totals']['debit'] ?? 0);
    $sumCredit = (float)($preview['totals']['credit'] ?? 0);

    return DB::transaction(function () use (
        $companyId, $receivingEntryId, $approvedByUserId, $workstationId,
        $r, $lines, $sumDebit, $sumCredit,
        $explanation, $bookingNo
    ) {
        $now = now();

        // Load receiving_entry again (for update + integrity)
        $re = DB::table(self::TBL_RECEIVING_ENTRY)
            ->where('company_id', $companyId)
            ->where('id', $receivingEntryId)
            ->first();

        if (!$re) {
            throw new \RuntimeException('Receiving entry not found.');
        }

        // Generate cp_no if not already linked
        $existingCpId = (int)($re->cash_purchase_id ?? 0);
        $cpNo = ($existingCpId > 0)
            ? (string)(DB::table(self::TBL_CP_HDR)->where('id', $existingCpId)->value('cp_no') ?? '')
            : '';

        if ($cpNo === '') {
            $cpNo = $this->generateNextCpNo($companyId);
        }

        $purchaseAmount = round($sumDebit, 2);
        $amountInWords  = $this->numberToPesoWords($purchaseAmount);

        $headerData = [
            // required
            'cp_no'          => $cpNo,
            'vend_id'        => (string)($r['vendor_code'] ?? ''),          // ✅ pbn_entry.vend_code
            'purchase_date'  => $r['receipt_date'],
            'purchase_amount'=> $purchaseAmount,

            // from approval modal
            'explanation'    => $explanation,
            'amount_in_words'=> $amountInWords,
            'booking_no'     => ($bookingNo !== '' ? $bookingNo : null),

            // status
            'is_cancel'      => 'n',

            // from PBN join
            'crop_year'      => (string)($r['crop_year'] ?? ''),
            'sugar_type'     => (string)($r['sugar_type'] ?? ''),

            // from receiving header
            'mill_id'        => (string)($r['mill'] ?? ''),
            'rr_no'          => (string)($r['receipt_no'] ?? ''),

            // system
            'workstation_id' => $workstationId,
            'user_id'        => $approvedByUserId,
            'company_id'     => $companyId,

            // totals
            'sum_debit'      => $purchaseAmount,
            'sum_credit'     => round($sumCredit, 2),
            'is_balanced'    => true,

            'updated_at'     => $now,
        ];

        if ($existingCpId > 0) {
            // update header
            DB::table(self::TBL_CP_HDR)
                ->where('company_id', $companyId)
                ->where('id', $existingCpId)
                ->update($headerData);

            // replace details
            DB::table(self::TBL_CP_DTL)
                ->where('company_id', $companyId)
                ->where('transaction_id', $existingCpId)
                ->delete();

            $cpId = $existingCpId;
        } else {
            $headerData['created_at'] = $now;
            $cpId = (int)DB::table(self::TBL_CP_HDR)->insertGetId($headerData);

            // link back to receiving_entry (✅ using cp_no as you recommended)
            DB::table(self::TBL_RECEIVING_ENTRY)
                ->where('company_id', $companyId)
                ->where('id', $receivingEntryId)
                ->update([
                    'cash_purchase_id' => $cpId,
                    'cp_no'            => $cpNo,
                    'cash_purchase_no' => $cpNo, // optional mirror (safe)
                    'updated_at'       => $now,
                ]);
        }

        // insert details
        foreach ($lines as $ln) {
            DB::table(self::TBL_CP_DTL)->insert([
                'transaction_id' => $cpId,
                'acct_code'      => (string)$ln['acct_code'],
                'debit'          => (float)$ln['debit'],
                'credit'         => (float)$ln['credit'],
                'workstation_id' => $workstationId,
                'user_id'        => $approvedByUserId,
                'company_id'     => $companyId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }

        return $cpId;
    });
}

private function generateNextCpNo(int $companyId): string
{
    $last = DB::table(self::TBL_CP_HDR)
        ->where('company_id', $companyId)
        ->orderBy('cp_no', 'desc')
        ->value('cp_no');

    $base = is_numeric($last) ? (int)$last : 200000;
    return (string)($base + 1);
}

// Peso words helpers (same behavior style as your controller)
private function numberToPesoWords(float $amount): string
{
    $amount = round($amount, 2);
    $integerPart = (int) floor($amount);
    $cents = (int) round(($amount - $integerPart) * 100);

    $words = ($integerPart === 0) ? 'zero' : $this->numberToWords($integerPart);
    $words = ucfirst($words) . ' pesos';

    if ($cents > 0) {
        $words .= ' and ' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT) . '/100';
    } else {
        $words .= ' only';
    }

    return $words;
}

private function numberToWords(int $num): string
{
    $ones = ['', 'one','two','three','four','five','six','seven','eight','nine','ten',
        'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    $tens = ['', '', 'twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    $scales = ['', 'thousand', 'million', 'billion'];

    if ($num === 0) return 'zero';

    $words = [];
    $scaleIndex = 0;

    while ($num > 0) {
        $chunk = $num % 1000;
        if ($chunk > 0) {
            $chunkWords = [];

            $hundreds  = intdiv($chunk, 100);
            $remainder = $chunk % 100;

            if ($hundreds > 0) $chunkWords[] = $ones[$hundreds] . ' hundred';

            if ($remainder > 0) {
                if ($remainder < 20) {
                    $chunkWords[] = $ones[$remainder];
                } else {
                    $t = intdiv($remainder, 10);
                    $u = $remainder % 10;
                    $chunkWords[] = $tens[$t] . ($u ? '-' . $ones[$u] : '');
                }
            }

            if ($scales[$scaleIndex] !== '') $chunkWords[] = $scales[$scaleIndex];

            array_unshift($words, implode(' ', $chunkWords));
        }

        $num = intdiv($num, 1000);
        $scaleIndex++;
    }

    return implode(' ', $words);
}


private function firstExistingTable(array $tables): string
{
    foreach ($tables as $t) {
        if (Schema::hasTable($t)) return $t;
    }
    throw new \RuntimeException('No receiving details table found. Tried: ' . implode(', ', $tables));
}

private function firstExistingColumn(string $table, array $cols): string
{
    foreach ($cols as $c) {
        if (Schema::hasColumn($table, $c)) return $c;
    }
    // return empty means “not available”
    return '';
}





}
