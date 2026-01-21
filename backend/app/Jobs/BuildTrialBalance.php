<?php

namespace App\Jobs;

use App\Reports\Pdf\GLPDF; // reuse your GL PDF base
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BuildTrialBalance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 mins

     // --- Retained Earnings rules ---
    private const RE_CODE        = '4031';
    private const RE_THRESHOLD   = 4031;   // compare numeric prefix
    private const BASELINE_ASOF  = '2024-12-31'; // beginning_balance snapshot date
    private const FIRST_FLOW_YEAR = 2025;  // RE flows starting Jan 2025
   



    public function __construct(
        public string $ticket,
        public string $startAccount,
        public string $endAccount,
        public string $startDate,      // yyyy-mm-dd
        public string $endDate,        // yyyy-mm-dd
        public string $orientation,    // portrait|landscape
        public string $format,         // pdf|xls|xlsx
        public int $companyId = 0,
        public string $fs = 'ALL'      // ALL|ACT|BS|IS
    ) {
        // DO NOT do heavy work here.
    }

    public function handle(): void
    {
        try {
            $this->setStatus('running', 1, 'Loading…');

            //$sdate = Carbon::parse($this->startDate)->startOfDay()->toDateString();
            //$edate = Carbon::parse($this->endDate)->endOfDay()->toDateString();
            /*ORIGINAL - NEW*/
            // Use full timestamps so endOfDay is truly inclusive (prevents missing Jan movements in Feb openings)
            $sdate = Carbon::parse($this->startDate)->startOfDay()->toDateTimeString();
            $edate = Carbon::parse($this->endDate)->endOfDay()->toDateTimeString();
            /*ORIGINAL - NEW*/



            // Beginning baseline (new app starts from here)
            $openingAsOf = '2024-12-31';
            //$fyStart     = Carbon::parse($openingAsOf)->addDay()->toDateString(); // 2025-01-01
           /*ORIGNAL - NEW*/
            $fyStart     = Carbon::parse($openingAsOf)->addDay()->startOfDay()->toDateTimeString(); // 2025-01-01 00:00:00
            /*ORIGINAL - NEW*/
            // 1) Accounts in range (respect FS filter)
// 1) Accounts in range (respect FS filter)
// 1) Accounts in range (respect FS filter)
$this->setStatus('running', 5, 'Loading accounts…');

$cid = (int) $this->companyId;

// normalize range so start <= end (string compare is fine for numeric-like codes such as 1001..9999)
$rangeStart = min($this->startAccount, $this->endAccount);
$rangeEnd   = max($this->startAccount, $this->endAccount);

$accounts = DB::table('account_code as ac')
    ->leftJoin('account_main as am', 'am.main_acct_code', '=', 'ac.main_acct_code')
    ->selectRaw("
        ac.acct_code,
        ac.acct_desc,
        ac.acct_number,
        ac.main_acct_code,
        COALESCE(am.main_acct, ac.main_acct) as main_acct,
        ac.fs,
        ac.exclude,
        ac.active_flag,
        CASE
          WHEN ac.acct_code = '4031' THEN 0
          WHEN ac.fs ILIKE 'IS%' OR (
            CASE
              WHEN ac.acct_code ~ '^[0-9]+' THEN (substring(ac.acct_code from '^[0-9]+'))::int
              ELSE NULL
            END
          ) > 4031
          THEN 1 ELSE 0
        END as is_pnl
    ")
    ->where('ac.active_flag', 1)
    ->when($cid > 0, fn($q) => $q->where('ac.company_id', $cid))

    // ✅ RANGE BY acct_code ONLY
    ->whereBetween('ac.acct_code', [$rangeStart, $rangeEnd])

    // ✅ FS filter: ACT must treat NULL exclude as "not excluded"
    ->when($this->fs !== 'ALL', function ($q) {
        if ($this->fs === 'ACT') {
            $q->where(function ($qq) {
                $qq->where('ac.exclude', 0)
                   ->orWhereNull('ac.exclude');
            });
        } elseif ($this->fs === 'BS') {
            $q->where('ac.fs', 'like', 'BS%');
        } elseif ($this->fs === 'IS') {
            $q->where('ac.fs', 'like', 'IS%');
        }
    })

    // sort for display (acct_number is OK for sorting, not filtering)
    ->orderBy('ac.acct_code')
    ->get()
    ->keyBy('acct_code');

if ($accounts->isEmpty()) {
    $this->setStatus('failed', 100, 'No accounts in range.');
    return;
}



            // 2) Beginning balances map (as of 2024-12-31)
            $this->setStatus('running', 12, 'Beginning balances…');

//$openings = DB::table('beginning_balance as bb')
//    ->select('bb.account_code', 'bb.amount')
//    ->whereBetween('bb.account_code', [$this->startAccount, $this->endAccount])
/*ORIGINAL - NEW*/ 
$openings = DB::table('beginning_balance as bb')
    ->select('bb.account_code', 'bb.amount')
    ->whereBetween('bb.account_code', [$rangeStart, $rangeEnd])
/*ORIGINAL - NEW*/

    ->when($this->companyId > 0, fn($q) => $q->where('bb.company_id', $this->companyId))


    ->get()
    ->keyBy('account_code')
    ->map(fn($r) => (float) $r->amount);

// 2b) Retained Earnings constants
$reConstStartYear = null; // used when the report start is in 2025+
$reConstEndYear   = null; // used when the report end is in 2025+ (cross-year reports)

$startYear = (int) Carbon::parse($sdate)->year;
$endYear   = (int) Carbon::parse($edate)->year;

// helper closure to compute RE constant for a given year
$computeReConst = function (int $year): float {
    // Base RE from baseline snapshot: SUM(beginning_balance) for acct >= 4031
    $re = (float) $this->sumBeginningBalanceTotalGreaterOrEqual(self::RE_THRESHOLD);

    // Roll-forward: add full-year net income for prior years (2025..year-1), acct > 4031
    for ($y = self::FIRST_FLOW_YEAR; $y <= ($year - 1); $y++) {
        $re += (float) $this->sumNetIncomeForYear($y);
    }

    return (float) $re;
};

// start-year const (only if start year is 2025+)
if ($startYear >= self::FIRST_FLOW_YEAR) {
    $this->setStatus('running', 14, 'Calculating retained earnings (start year)…');
    $reConstStartYear = $computeReConst($startYear);
    Log::info('RE_CONST_START', ['year' => $startYear, 'reConst' => $reConstStartYear]);
}

// end-year const (only if end year is 2025+)
if ($endYear >= self::FIRST_FLOW_YEAR) {
    $this->setStatus('running', 15, 'Calculating retained earnings (end year)…');
    $reConstEndYear = $computeReConst($endYear);
    Log::info('RE_CONST_END', ['year' => $endYear, 'reConst' => $reConstEndYear]);
}





    
            // 3) Pre-movements from FY start up to day before startDate (B/S only)
            //$preEnd  = Carbon::parse($sdate)->subDay()->toDateString();
            // Pre-end should include the FULL previous day (e.g., for Feb 1 start, include all of Jan 31)
           /*ORIGNAL - NEW*/
            $preEnd  = Carbon::parse($sdate)->subDay()->endOfDay()->toDateTimeString();
            /*ORIGINAL - NEW*/
            
            $needPre = (strtotime($preEnd) >= strtotime($fyStart));

            $this->setStatus('running', 18, 'Calculating YTD pre-movements…');
            $preMovNet = collect();
            if ($needPre) {
                $preMovNet = $this->sumMovementsNetFromDC($fyStart, $preEnd);

            }


// 3b) P&L YTD pre-movements (Jan 1 of report year up to day before startDate)
//$jan1OfYear  = Carbon::parse($sdate)->startOfYear()->toDateString();
/*ORIGINAL - NEW*/
$jan1OfYear  = Carbon::parse($sdate)->startOfYear()->startOfDay()->toDateTimeString();
/*ORIGINAL - NEW*/
$needPnlPre  = (strtotime($preEnd) >= strtotime($jan1OfYear));

$this->setStatus('running', 22, 'Calculating P&L YTD pre-movements…');
$pnlPreMovNet = collect();
if ($needPnlPre) {
    $pnlPreMovNet = $this->sumMovementsNetFromDC($jan1OfYear, $preEnd);

}

// 3c) Baseline backout for P&L accounts when report starts in baseline year (2024)
// We only have the baseline snapshot as-of 2024-12-31 (beginning_balance).
// For a Dec 2024 start, we want YTD as-of startDate-1.
// So: opening = beginning_balance(asOf 2024-12-31) - netMovements(startDate..2024-12-31)
$baselineAsOfObj = Carbon::parse($openingAsOf)->startOfDay();
$useBaselineBackout = Carbon::parse($sdate)->year === $baselineAsOfObj->year
    && Carbon::parse($sdate)->lte($baselineAsOfObj)
    && Carbon::parse($sdate)->month !== 1; // January still forces 0

$baselineBackoutNet = collect();
if ($useBaselineBackout) {
    $this->setStatus('running', 24, 'Calculating baseline backout (P&L)…');
    $baselineBackoutNet = $this->sumMovementsNetFromDC($sdate, $baselineAsOfObj->toDateString());

}


            // 4) Period movements (sum debit/credit) for [S..E]
            $this->setStatus('running', 28, 'Summing period movements…');
            $periodMov = $this->sumMovementsDC($sdate, $edate);

            // 5) Build TB rows
            $this->setStatus('running', 45, 'Assembling rows…');

            $rows = [];      // list for rendering
            $totBeg = 0.0; $totD = 0.0; $totC = 0.0; $totEnd = 0.0;

            $i = 0; $n = max(1, $accounts->count());

// --- natural sort of account codes (e.g., 1001, 1001-01, 1010, A-01) ---
$codes = $accounts->keys()->all();   // $accounts is a collection keyed by acct_code
usort($codes, function (string $a, string $b) {
    $na = (preg_match('/^\d+/', $a, $ma) ? (int)$ma[0] : PHP_INT_MAX);
    $nb = (preg_match('/^\d+/', $b, $mb) ? (int)$mb[0] : PHP_INT_MAX);
    // 1) numeric prefix first
    if ($na !== $nb) return $na <=> $nb;
    // 2) stable tiebreaker on full code
    return strcmp($a, $b);
});

// progress math uses the sorted count
$i = 0;
$n = max(1, count($codes));

$startDateObj = \Carbon\Carbon::parse($sdate)->startOfDay();
$jan1Obj      = $startDateObj->copy()->startOfYear();
$fyStartObj   = \Carbon\Carbon::parse($fyStart)->startOfDay();

foreach ($codes as $code) {
    $acct = $accounts[$code];

// --- Retained Earnings (4031) override (special rules) ---
if (trim((string)$code) === self::RE_CODE) {

    $deb = 0.0;
    $cre = 0.0;

    // baseline RE from beginning_balance table (Dec 2024 snapshot)
    $baselineRe = (float)($openings[self::RE_CODE] ?? 0.0);

    // Opening:
    // - If report starts in 2025+ => use computed const for start year
    // - Else (Dec 2024 or earlier baseline period) => show baseline beginning_balance
    if ($reConstStartYear !== null) {
        $opening = (float) $reConstStartYear;
    } else {
        $opening = (float) $baselineRe;
    }

    // Ending:
    // - If report ends in 2025+ => use computed const for end year
    // - Else (still within 2024 baseline period) => baseline
    if ($reConstEndYear !== null) {
        $ending = (float) $reConstEndYear;
    } else {
        $ending = (float) $baselineRe;
    }

    $rows[] = [
        'acct_code'      => $code,
        'acct_desc'      => (string)$acct->acct_desc,
        'main_acct'      => (string)$acct->main_acct,
        'main_acct_code' => (string)$acct->main_acct_code,
        'beginning'      => $opening,
        'debit'          => $deb,
        'credit'         => $cre,
        'ending'         => $ending,
    ];

    $totBeg += $opening; $totD += $deb; $totC += $cre; $totEnd += $ending;

    $i++;
    $pct = 45 + (int)floor(($i / $n) * 30);
    $this->setStatus('running', $pct, "Assembling {$code}…");

    continue;
}



if ($acct->is_pnl) {

    // P&L rule:
    // - January start => beginning is 0.00
    // - Baseline-year (2024) non-January => derive YTD by backing out from 2024-12-31 snapshot
    // - Otherwise => beginning is YTD net from Jan 1..(start-1)
    if ($startDateObj->month === 1) {
        $opening = 0.0;
    } elseif ($useBaselineBackout) {
        $opening = (float)($openings[$code] ?? 0.0) - (float)($baselineBackoutNet[$code] ?? 0.0);
    } else {
        $opening = (float)($pnlPreMovNet[$code] ?? 0.0);
    }

} else {
    // Balance Sheet unchanged: opening baseline + FY pre-movements
    $opening = (float)($openings[$code] ?? 0.0)
             + (float)($preMovNet[$code] ?? 0.0);
}


    $deb    = (float)($periodMov[$code]['debit']  ?? 0.0);

    $cre    = (float)($periodMov[$code]['credit'] ?? 0.0);
    $ending = $opening + ($deb - $cre);


    $rows[] = [
        'acct_code'      => $code,
        'acct_desc'      => (string)$acct->acct_desc,
        'main_acct'      => (string)$acct->main_acct,
        'main_acct_code' => (string)$acct->main_acct_code,
        'beginning'      => $opening,
        'debit'          => $deb,
        'credit'         => $cre,
        'ending'         => $ending,
    ];

    $totBeg += $opening; $totD += $deb; $totC += $cre; $totEnd += $ending;

    $i++;
    $pct = 45 + (int)floor(($i / $n) * 30); // 45..75
    $this->setStatus('running', $pct, "Assembling {$code}…");
}


            // 6) Render file
            $this->setStatus('running', 82, 'Rendering file…');

            $target = ($this->format === 'xls' || $this->format === 'xlsx')
                ? $this->writeXls($rows, $sdate, $edate)
                : $this->writePdf($rows, $sdate, $edate, $this->orientation);

            // 7) Prune old files
            $this->prune('reports', 2, $target['disk'] ?? 'local');

            // 8) Done
            $this->setStatus(
                status:   'done',
                progress: 100,
                message:  'Done',
                rel:      $target['rel'],
                name:     $target['download_name'] ?? ($target['filename'] ?? null),
                disk:     $target['disk'] ?? 'local',
                url:      $target['url'] ?? null,
                format:   $target['ext'] ?? $this->format,
                abs:      $target['abs'] ?? null
            );
        } catch (\Throwable $e) {
            Log::error('TB job failed', ['ticket' => $this->ticket, 'ex' => $e]);
            $this->setStatus('failed', 100, 'Error: '.$e->getMessage());
            throw $e;
        }
    }

    /* --------------------------- data helpers --------------------------- */

/** Returns acct_code => net(debit-credit) for [from..to]. */
/** Returns acct_code => net(debit-credit) for [from..to]. */
private function sumMovementsNet(string $from, string $to)
{
    $cid = (int) $this->companyId;

    // Always compare DATE-to-DATE (your *_date columns are DATE in DB)
    $fromDate = \Carbon\Carbon::parse($from)->toDateString();
    $toDate   = \Carbon\Carbon::parse($to)->toDateString();

    // Normalize account range (handles reversed inputs)
    $sa = min($this->startAccount, $this->endAccount);
    $ea = max($this->startAccount, $this->endAccount);

    $sql = "
    with src as (
        -- General
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from general_accounting h
        join general_accounting_details d on (d.transaction_id)::bigint = h.id
        where h.gen_acct_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)

        union all
        -- Cash Disbursement
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_disbursement h
        join cash_disbursement_details d on d.transaction_id = h.id
        where h.disburse_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)

        union all
        -- Cash Receipts
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_receipts h
        join cash_receipt_details d on (d.transaction_id)::bigint = h.id
        where h.receipt_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)

        union all
        -- Cash Purchase
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_purchase h
        join cash_purchase_details d on d.transaction_id = h.id
        where h.purchase_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)

        union all
        -- Cash Sales
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_sales h
        join cash_sales_details d on (d.transaction_id)::bigint = h.id
        where h.sales_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)
    )
    select acct_code, coalesce(sum(deb) - sum(cred), 0) as net
    from src
    group by acct_code
    ";

    $b = ['s' => $fromDate, 'e' => $toDate, 'sa' => $sa, 'ea' => $ea];
    if ($cid > 0) $b['cid'] = $cid;

    $rows = DB::select($sql, $b);

    // keyBy on trimmed acct_code (matches what we SELECT)
    return collect($rows)
        ->keyBy('acct_code')
        ->map(fn($r) => (float) ($r->net ?? 0.0));
}


/** Returns acct_code => ['debit'=>x,'credit'=>y] totals for [from..to]. */
/** Returns acct_code => ['debit'=>x,'credit'=>y] totals for [from..to]. */
private function sumMovementsDC(string $from, string $to)
{
    $cid = (int) $this->companyId;

    // Compare DATE-to-DATE
    $fromDate = \Carbon\Carbon::parse($from)->toDateString();
    $toDate   = \Carbon\Carbon::parse($to)->toDateString();

    // Normalize account range
    $sa = min($this->startAccount, $this->endAccount);
    $ea = max($this->startAccount, $this->endAccount);

    $sql = "
    with src as (
        -- General
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from general_accounting h
        join general_accounting_details d on (d.transaction_id)::bigint = h.id
        where h.gen_acct_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)

        union all
        -- Cash Disbursement
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_disbursement h
        join cash_disbursement_details d on d.transaction_id = h.id
        where h.disburse_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)

        union all
        -- Cash Receipts
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_receipts h
        join cash_receipt_details d on (d.transaction_id)::bigint = h.id
        where h.receipt_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)

        union all
        -- Cash Purchase
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_purchase h
        join cash_purchase_details d on d.transaction_id = h.id
        where h.purchase_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)

        union all
        -- Cash Sales
        select trim(d.acct_code) as acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_sales h
        join cash_sales_details d on (d.transaction_id)::bigint = h.id
        where h.sales_date::date between :s and :e
          and h.is_cancel = 'n'
          and trim(d.acct_code) between :sa and :ea
          " . ($cid > 0 ? "and h.company_id = :cid" : "") . "
        group by trim(d.acct_code)
    )
    select acct_code, coalesce(sum(deb),0) as debit, coalesce(sum(cred),0) as credit
    from src
    group by acct_code
    ";

    $b = ['s' => $fromDate, 'e' => $toDate, 'sa' => $sa, 'ea' => $ea];
    if ($cid > 0) $b['cid'] = $cid;

    $rows = DB::select($sql, $b);

    return collect($rows)
        ->keyBy('acct_code')
        ->map(fn($r) => [
            'debit'  => (float) ($r->debit ?? 0.0),
            'credit' => (float) ($r->credit ?? 0.0),
        ]);
}



/**
 * Net movements derived from DC movements:
 * returns acct_code => (debit - credit)
 * Uses the exact same source as the period DC calculation.
 */
private function sumMovementsNetFromDC(string $from, string $to)
{
    $dc = $this->sumMovementsDC($from, $to);

    return $dc->map(function ($r) {
        $d = (float)($r['debit'] ?? 0.0);
        $c = (float)($r['credit'] ?? 0.0);
        return $d - $c;
    });
}



    /**
     * SUM(beginning_balance.amount) for all accounts with numeric prefix >= $threshold
     * Company-scoped.
     */
    private function sumBeginningBalanceTotalGreaterOrEqual(int $threshold): float
    {
        return (float) DB::table('beginning_balance as bb')
            ->when($this->companyId > 0, fn($q) => $q->where('bb.company_id', $this->companyId))
            ->whereRaw("
                (
                  CASE
                    WHEN bb.account_code ~ '^[0-9]+'
                      THEN (substring(bb.account_code from '^[0-9]+'))::int
                    ELSE NULL
                  END
                ) >= ?
            ", [$threshold])
            ->sum('bb.amount');
    }

    /**
     * Net income for a year = SUM(net) for accounts with numeric prefix > 4031,
     * for the full year [Jan 1..Dec 31], company-scoped.
     */
    private function sumNetIncomeForYear(int $year): float
    {
        $from = sprintf('%04d-01-01', $year);
        $to   = sprintf('%04d-12-31', $year);

        // reuse your existing movement net aggregator, but across acct_code > 4031
        $cid = (int) $this->companyId;

        $sql = "
        with src as (
            select coalesce(sum(d.debit),0) deb, coalesce(sum(d.credit),0) cred
            from general_accounting h
            join general_accounting_details d on (d.transaction_id)::bigint = h.id
            where h.gen_acct_date between :s and :e
            and h.is_cancel = 'n'
              and (
                case when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int else null end
              ) > :thr
              ".($cid > 0 ? "and h.company_id = :cid" : "")."

            union all
            select coalesce(sum(d.debit),0), coalesce(sum(d.credit),0)
            from cash_disbursement h
            join cash_disbursement_details d on d.transaction_id = h.id
            where h.disburse_date between :s and :e
            and h.is_cancel = 'n'
              and (
                case when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int else null end
              ) > :thr
              ".($cid > 0 ? "and h.company_id = :cid" : "")."

            union all
            select coalesce(sum(d.debit),0), coalesce(sum(d.credit),0)
            from cash_receipts h
            join cash_receipt_details d on (d.transaction_id)::bigint = h.id
            where h.receipt_date between :s and :e
            and h.is_cancel = 'n'
              and (
                case when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int else null end
              ) > :thr
              ".($cid > 0 ? "and h.company_id = :cid" : "")."

            union all
            select coalesce(sum(d.debit),0), coalesce(sum(d.credit),0)
            from cash_purchase h
            join cash_purchase_details d on d.transaction_id = h.id
            where h.purchase_date between :s and :e
            and h.is_cancel = 'n'
              and (
                case when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int else null end
              ) > :thr
              ".($cid > 0 ? "and h.company_id = :cid" : "")."

            union all
            select coalesce(sum(d.debit),0), coalesce(sum(d.credit),0)
            from cash_sales h
            join cash_sales_details d on (d.transaction_id)::bigint = h.id
            where h.sales_date between :s and :e
            and h.is_cancel = 'n'
              and (
                case when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int else null end
              ) > :thr
              ".($cid > 0 ? "and h.company_id = :cid" : "")."
        )
        select coalesce(sum(deb) - sum(cred), 0) as net
        from src
        ";

        $b = ['s' => $from, 'e' => $to, 'thr' => self::RE_THRESHOLD];
        if ($cid > 0) $b['cid'] = $cid;

        $row = DB::selectOne($sql, $b);
        return (float) ($row->net ?? 0.0);
    }




    /**
     * Base retained earnings derivation:
     * SUM(beginning_balance.amount) for all accounts with numeric prefix > $threshold.
     * (Baseline snapshot is Dec 2024 in your system.)
     */
    private function sumBeginningBalanceTotalGreaterThan(int $threshold): float
    {
        return (float) DB::table('beginning_balance as bb')
            ->when($this->companyId > 0, fn($q) => $q->where('bb.company_id', $this->companyId))
            ->whereRaw("
                (
                  CASE
                    WHEN bb.account_code ~ '^[0-9]+'
                      THEN (substring(bb.account_code from '^[0-9]+'))::int
                    ELSE NULL
                  END
                ) > ?
            ", [$threshold])
            ->sum('bb.amount');
    }

    /**
     * Net income helper:
     * Total net (debit - credit) across ALL accounts with numeric prefix > $threshold for [from..to].
     * This does NOT depend on startAccount/endAccount range.
     */
    private function sumMovementsNetTotalGreaterThan(int $threshold, string $from, string $to): float
    {
        $cid = (int) $this->companyId;

    $sql = "
with src as (
    select coalesce(sum(d.debit),0) deb, coalesce(sum(d.credit),0) cred
    from general_accounting h
    join general_accounting_details d on (d.transaction_id)::bigint = h.id
    where h.gen_acct_date between :s and :e
      and h.is_cancel = 'n'
      and (
        case
          when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int
          else null
        end
      ) > :thr
      ".($cid > 0 ? "and h.company_id = :cid" : "")."

    union all
    select coalesce(sum(d.debit),0), coalesce(sum(d.credit),0)
    from cash_disbursement h
    join cash_disbursement_details d on d.transaction_id = h.id
    where h.disburse_date between :s and :e
      and h.is_cancel = 'n'
      and (
        case
          when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int
          else null
        end
      ) > :thr
      ".($cid > 0 ? "and h.company_id = :cid" : "")."

    union all
    select coalesce(sum(d.debit),0), coalesce(sum(d.credit),0)
    from cash_receipts h
    join cash_receipt_details d on (d.transaction_id)::bigint = h.id
    where h.receipt_date between :s and :e
      and h.is_cancel = 'n'
      and (
        case
          when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int
          else null
        end
      ) > :thr
      ".($cid > 0 ? "and h.company_id = :cid" : "")."

    union all
    select coalesce(sum(d.debit),0), coalesce(sum(d.credit),0)
    from cash_purchase h
    join cash_purchase_details d on d.transaction_id = h.id
    where h.purchase_date between :s and :e
      and h.is_cancel = 'n'
      and (
        case
          when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int
          else null
        end
      ) > :thr
      ".($cid > 0 ? "and h.company_id = :cid" : "")."

    union all
    select coalesce(sum(d.debit),0), coalesce(sum(d.credit),0)
    from cash_sales h
    join cash_sales_details d on (d.transaction_id)::bigint = h.id
    where h.sales_date between :s and :e
      and h.is_cancel = 'n'
      and (
        case
          when d.acct_code ~ '^[0-9]+' then (substring(d.acct_code from '^[0-9]+'))::int
          else null
        end
      ) > :thr
      ".($cid > 0 ? "and h.company_id = :cid" : "")."
)
select coalesce(sum(deb) - sum(cred), 0) as net
from src
";

        $b = ['s' => $from, 'e' => $to, 'thr' => $threshold];
        if ($cid > 0) $b['cid'] = $cid;

        $row = DB::selectOne($sql, $b);
        return (float) ($row->net ?? 0.0);
    }






    /* --------------------------- writers --------------------------- */
/* --------------------------- writers (PDF) --------------------------- */

private function writePdf(array $report, string $sdate, string $edate, string $orientation = 'landscape'): array
{
    // Nice download name (keeps your friendly naming)
    $downloadName = $this->buildFriendlyDownloadName('pdf');
    $target       = $this->targetLocal('trial-balance', 'pdf', $downloadName);

    // TCPDF setup
    $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('SUCden');
    $pdf->SetAuthor('SUCden');
    $pdf->SetTitle('Trial Balance');
    $pdf->SetMargins(12, 20, 12);           // L,T,R
    $pdf->SetHeaderMargin(0);
    $pdf->SetFooterMargin(12);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->SetFont('helvetica', '', 9);

    $isPortrait = strtolower($orientation) === 'portrait';
    $pageOrient = $isPortrait ? 'P' : 'L';
    //$asOf       = \Carbon\Carbon::parse($edate)->format('Y-m-d'); // matches legacy “Date Requested”
$periodStart = \Carbon\Carbon::parse($sdate)->format('Y-m-d');
$periodEnd   = \Carbon\Carbon::parse($edate)->format('Y-m-d');

    // Column widths (percent)
    //  Code 10% | Desc 40% | 4 numeric columns 12.5% each = 100%
    $wCode = '10%'; $wDesc = '40%'; $wAmt = '12.5%';

$addHeader = function() use ($pdf, $periodStart, $periodEnd, $wCode, $wDesc, $wAmt) {
    $this->renderTbHeader($pdf, $periodStart, $periodEnd, $wCode, $wDesc, $wAmt);
};


    $pdf->AddPage($pageOrient, 'A4');
    $addHeader();

    $rowNo = 0;
    foreach ($report as $r) {
        // Accept either our TB keys or fallbacks
        $code = (string)($r['acct_code'] ?? $r['code'] ?? '');
        $desc = (string)($r['acct_desc'] ?? $r['description'] ?? '');
        $beg  = (float)($r['beg']       ?? $r['beginning'] ?? 0.0);
        $deb  = (float)($r['debit']     ?? $r['debits']    ?? 0.0);
        $cre  = (float)($r['credit']    ?? $r['credits']   ?? 0.0);
        $end  = (float)($r['end']       ?? $r['ending']    ?? ($beg + $deb - $cre));

        // Page break control: add header on new page
        if ($pdf->GetY() > ($pdf->getPageHeight() - 28)) {
            $pdf->AddPage($pageOrient, 'A4');
            $addHeader();
        }

        $pdf->writeHTML(
            <<<HTML
<table border="0" cellspacing="0" cellpadding="2" width="100%">
  <tr>
    <td width="{$wCode}">{$this->escape($code)}</td>
    <td width="{$wDesc}">{$this->escape($desc)}</td>
    <td width="{$wAmt}" align="right">{$this->n2($beg)}</td>
    <td width="{$wAmt}" align="right">{$this->n2($deb)}</td>
    <td width="{$wAmt}" align="right">{$this->n2($cre)}</td>
    <td width="{$wAmt}" align="right"><b>{$this->n2($end)}</b></td>
  </tr>
</table>
HTML,
            false, false, false, false, ''
        );

        $rowNo++;
    }

    $pdf->Output($target['abs'], 'F');
    return $target;
}


private function companyHeader(): array
{
    $cid = (int) $this->companyId;

    if ($cid === 2) {
        return [
            'name'  => 'AMEROP PHILIPPINES, INC.',
            'tin'   => 'TIN- 762-592-927-000',
            'addr1' => 'Com. Unit 301-B Sitari Bldg., Lacson St. cor. C.I Montelibano Ave.,',
            'addr2' => 'Brgy. Mandalagan, Bacolod City',
        ];
    }

    // default
    return [
        'name'  => 'SUCDEN PHILIPPINES, INC.',
        'tin'   => 'TIN-000-105-2567-000',
        'addr1' => 'Unit 2202 The Podium West Tower',
        'addr2' => 'Ortigas Center, Mandaluyong City',
    ];
}


/**
 * Renders the centered company header + TB columns exactly like the legacy sample.
 */
private function renderTbHeader(
    \TCPDF $pdf,
    string $periodStart,
    string $periodEnd,
    string $wCode,
    string $wDesc,
    string $wAmt
): void {
    $co = $this->companyHeader();

    // Company name
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->writeHTML(
        '<div style="text-align:center;">'.$this->escape($co['name']).'</div>',
        false, false, false, false, ''
    );

    // Report title
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->writeHTML(
        '<div style="text-align:center;">Trial Balance</div>',
        false, false, false, false, ''
    );

    // Period
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML(
        '<div style="text-align:center;">Period: '
        .$this->escape($periodStart).' — '.$this->escape($periodEnd)
        .'</div>',
        false, false, false, false, ''
    );

    // Rule
    $pdf->Ln(2);
    $pdf->writeHTML('<hr/>', false, false, false, false, '');
    $pdf->Ln(1);

    // Column headers
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->writeHTML(
        <<<HTML
<table border="0" cellspacing="0" cellpadding="3" width="100%">
  <tr>
    <td width="{$wCode}">Account Code</td>
    <td width="{$wDesc}">Description</td>
    <td width="{$wAmt}" align="right">Beg. Balance</td>
    <td width="{$wAmt}" align="right">Debits</td>
    <td width="{$wAmt}" align="right">Credits</td>
    <td width="{$wAmt}" align="right">End Balance</td>
  </tr>
</table>
HTML,
        false, false, false, false, ''
    );

    $pdf->writeHTML('<hr/>', false, false, false, false, '');
    $pdf->SetFont('helvetica', '', 9);
}


/** Format helper: 2-decimals, thousands, show 0.00 (legacy look) */
private function n2(float $v): string
{
    return number_format($v, 2, '.', ',');
}


private function writeXls(array $rows, string $sdate, string $edate): array
{
    $ext          = ($this->format === 'xls') ? 'xls' : 'xlsx';
    $downloadName = $this->buildFriendlyDownloadName($ext);
    $target       = $this->targetLocal('trial-balance', $ext, $downloadName);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    foreach (range('A','F') as $col) {
        $sheet->getColumnDimension($col)->setWidth(18);
    }
    $sheet->getStyle('C:F')->getNumberFormat()->setFormatCode('#,##0.00');

    $co  = $this->companyHeader();
    $row = 1;

    /* ================= Company Header ================= */

    $sheet->setCellValue("A{$row}", $co['name']);
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(15);
    $row++;

    $sheet->setCellValue("A{$row}", $co['tin']);
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
    $row++;

    $sheet->setCellValue("A{$row}", $co['addr1']);
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
    $row++;

    $sheet->setCellValue("A{$row}", $co['addr2']);
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
    $row += 2;

    /* ================= Report Header ================= */

    $sheet->setCellValue("A{$row}", "TRIAL BALANCE");
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
    $row++;

    $sheet->setCellValue(
        "A{$row}",
        "Period: ".Carbon::parse($sdate)->format('m/d/Y')." — ".Carbon::parse($edate)->format('m/d/Y')
    );
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
    $row += 2;

    /* ================= Column Headers ================= */

    $headers = ['Account Code','Description','Beginning','Debit','Credit','Ending'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue("{$col}{$row}", $h);
        $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
        $col++;
    }

    $sheet->freezePane("A".($row + 1));
    $row++;

    /* ================= Data Rows ================= */

    $totBeg = 0.0;
    $totD   = 0.0;
    $totC   = 0.0;
    $totEnd = 0.0;

    foreach ($rows as $r) {
        $sheet->setCellValue("A{$row}", $r['acct_code']);
        $sheet->setCellValue("B{$row}", $r['acct_desc']);

        if ($r['beginning'] != 0.0) $sheet->setCellValue("C{$row}", (float)$r['beginning']);
        if ($r['debit']     != 0.0) $sheet->setCellValue("D{$row}", (float)$r['debit']);
        if ($r['credit']    != 0.0) $sheet->setCellValue("E{$row}", (float)$r['credit']);

        $sheet->setCellValue("F{$row}", (float)$r['ending']);

        $totBeg += (float)$r['beginning'];
        $totD   += (float)$r['debit'];
        $totC   += (float)$r['credit'];
        $totEnd += (float)$r['ending'];

        $row++;
    }

    /* ================= Totals ================= */

    $row++;
    $sheet->setCellValue("B{$row}", "Totals");
    $sheet->getStyle("B{$row}")->getFont()->setBold(true);

    $sheet->setCellValue("C{$row}", $totBeg);
    $sheet->setCellValue("D{$row}", $totD);
    $sheet->setCellValue("E{$row}", $totC);
    $sheet->setCellValue("F{$row}", $totEnd);

    /* ================= Save ================= */

    $writer = ($ext === 'xls')
        ? new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet)
        : new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

    if (method_exists($writer, 'setPreCalculateFormulas')) {
        $writer->setPreCalculateFormulas(false);
    }

    $writer->save($target['abs']);
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet, $writer);

    return $target;
}

    /* --------------------------- targets & utils --------------------------- */

    private function buildFriendlyDownloadName(string $ext): string
    {
        $acc = "{$this->startAccount}-{$this->endAccount}";
        $s   = Carbon::parse($this->startDate)->format('Y-m-d');
        $e   = Carbon::parse($this->endDate)->format('Y-m-d');
        return "TrialBalance_{$acc}_{$s}_to_{$e}.{$ext}";
    }

private function targetLocal(string $base, string $ext, string $downloadName): array
{
    $baseDir = storage_path('app');             // /var/www/html/storage/app
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }

    $dir = 'reports';
    $fullDir = $baseDir . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($fullDir)) {
        @mkdir($fullDir, 0775, true);
    }

    // Optional: permission guard
    @chmod($fullDir, 0775);

    $internal = sprintf('%s_%s_%s.%s', $base, now()->format('YmdHis'), \Illuminate\Support\Str::uuid(), $ext);
    $rel = "{$dir}/{$internal}";
    $abs = $fullDir . DIRECTORY_SEPARATOR . basename($internal);

    return [
        'disk'          => 'local',
        'rel'           => $rel,
        'abs'           => $abs,
        'url'           => null,
        'ext'           => $ext,
        'download_name' => $downloadName,
        'filename'      => basename($rel),
    ];
}


    private function prune(string $dir, int $days = 2, string $disk = 'local'): void
    {
        $cut = Carbon::now()->subDays($days);
        foreach (Storage::disk($disk)->files($dir) as $f) {
            $ts = Storage::disk($disk)->lastModified($f);
            if ($ts && Carbon::createFromTimestamp($ts)->lt($cut)) {
                Storage::disk($disk)->delete($f);
            }
        }
    }

    private function setStatus(
        string $status,
        int $progress,
        string $message,
        ?string $rel    = null,
        ?string $name   = null,
        ?string $disk   = null,
        ?string $url    = null,
        ?string $format = null,
        ?string $abs    = null
    ): void {
        $key   = $this->cacheKey($this->ticket);
        $state = array_merge(Cache::get($key, []), [
            'status'   => $status,
            'progress' => $progress,
            'message'  => $message,
        ]);
        if ($rel    !== null) $state['file_rel']      = $rel;
        if ($name   !== null) $state['download_name'] = $name;
        if ($disk   !== null) $state['file_disk']     = $disk;
        if ($url    !== null) $state['file_url']      = $url;
        if ($format !== null) $state['format']        = $format;
        if ($abs    !== null) $state['file_abs']      = $abs;

        Cache::put($key, $state, now()->addHours(6));
    }

    private function cacheKey(string $ticket): string
    {
        return "tb:{$ticket}";
    }

    private function escape(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}  