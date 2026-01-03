<?php

namespace App\Jobs;

use App\Reports\Pdf\GLPDF;
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

class BuildGeneralLedger implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 minutes

// =================== BEGIN ADD: GL retained earnings rules / baseline ===================
private const RE_CODE         = '4031';
private const RE_THRESHOLD    = 4031;
private const BASELINE_ASOF   = '2024-12-31';
private const FIRST_FLOW_YEAR = 2025;
// =================== END ADD: GL retained earnings rules / baseline ===================

    public function __construct(
        public string $ticket,
        public string $startAccount,
        public string $endAccount,
        public string $startDate,      // yyyy-mm-dd
        public string $endDate,        // yyyy-mm-dd
        public string $orientation,    // portrait|landscape
        public string $format,         // pdf|xls|xlsx
        public int $companyId = 0
    ) {}

    public function handle(): void
    {
        try {
            $this->setStatus('running', 1, 'Loading…');

$cid = (int) $this->companyId;
if ($cid <= 0) {
    $this->setStatus('failed', 100, 'Missing company scope (companyId=0).');
    return;
}



            $sdate = Carbon::parse($this->startDate)->startOfDay()->toDateString();
            $edate = Carbon::parse($this->endDate)->endOfDay()->toDateString();

// =================== BEGIN OVERWRITE: baseline dates ===================
$openingAsOf = self::BASELINE_ASOF;              // 2024-12-31
$fyStart     = Carbon::parse($openingAsOf)->addDay()->toDateString(); // 2025-01-01
// =================== END OVERWRITE: baseline dates ===================

            // 1) Accounts in range
            $accounts = DB::table('account_code as ac')
                ->leftJoin('account_main as am', 'am.main_acct_code', '=', 'ac.main_acct_code')
// =================== BEGIN OVERWRITE: accounts selectRaw (TB-consistent is_pnl) ===================
->selectRaw("
    ac.acct_code,
    ac.acct_desc,
    ac.acct_number,
    ac.main_acct_code,
    COALESCE(am.main_acct, ac.main_acct) as main_acct,
    ac.fs,
    ac.exclude,
    ac.active_flag,
    -- P&L: FS starts with IS OR numeric prefix of acct_code > 4031
    -- BUT 4031 itself is NOT P&L
    CASE
      WHEN ac.acct_code = '".self::RE_CODE."' THEN 0
      WHEN ac.fs ILIKE 'IS%' OR (
        CASE
          WHEN ac.acct_code ~ '^[0-9]+' THEN (substring(ac.acct_code from '^[0-9]+'))::int
          ELSE NULL
        END
      ) > ".self::RE_THRESHOLD."
      THEN 1 ELSE 0
    END as is_pnl
")
// =================== END OVERWRITE: accounts selectRaw (TB-consistent is_pnl) ===================

                ->where('ac.active_flag', 1)
                ->whereBetween('ac.acct_code', [$this->startAccount, $this->endAccount])
->where('ac.company_id', $cid) // ✅ always scoped
                ->orderBy('ac.acct_number')
                ->get()
                ->keyBy('acct_code');

            if ($accounts->isEmpty()) {
                $this->setStatus('failed', 100, 'No accounts in range.');
                return;
            }

            $this->setStatus('running', 8, 'Loading beginning balances…');

            // 2) Beginning balances map (as of 2024-12-31)
$openings = DB::table('beginning_balance as bb')
    ->select('bb.account_code', 'bb.amount')
    ->whereBetween('bb.account_code', [$this->startAccount, $this->endAccount])
->where('bb.company_id', $cid)
    ->get()
    ->keyBy('account_code')
    ->map(fn($r) => (float)$r->amount);


// =================== BEGIN ADD: TB-consistent RE const + P&L/BS opening prep ===================

// --- Retained Earnings constants (same as Trial Balance) ---
$reConstStartYear = null;
$reConstEndYear   = null;

$startYear = (int) Carbon::parse($sdate)->year;
$endYear   = (int) Carbon::parse($edate)->year;

// helper closure to compute RE constant for a given year
$computeReConst = function (int $year) use ($openings): float {
    // Base RE from baseline snapshot: SUM(beginning_balance) for acct >= 4031
    $re = (float) $this->sumBeginningBalanceTotalGreaterOrEqual(self::RE_THRESHOLD);

    // Roll-forward: add full-year net income for prior years (2025..year-1), acct > 4031
    for ($y = self::FIRST_FLOW_YEAR; $y <= ($year - 1); $y++) {
        $re += (float) $this->sumNetIncomeForYear($y);
    }

    return (float) $re;
};

if ($startYear >= self::FIRST_FLOW_YEAR) {
    $this->setStatus('running', 10, 'Calculating retained earnings (start year)…');
    $reConstStartYear = $computeReConst($startYear);
}

if ($endYear >= self::FIRST_FLOW_YEAR) {
    $this->setStatus('running', 11, 'Calculating retained earnings (end year)…');
    $reConstEndYear = $computeReConst($endYear);
}

// --- Pre-movements (Balance Sheet) from FY start up to day before startDate ---
$preEnd  = Carbon::parse($sdate)->subDay()->toDateString();
$needPre = (strtotime($preEnd) >= strtotime($fyStart));

$this->setStatus('running', 12, 'Calculating BS YTD pre-movements…');
$preMovNet = collect();
if ($needPre) {
    $preMovNet = $this->sumMovements($fyStart, $preEnd); // net per acct (debit-credit)
}

// --- P&L pre-movements: Jan 1 of report year up to day before startDate ---
$jan1OfYear = Carbon::parse($sdate)->startOfYear()->toDateString();
$needPnlPre = (strtotime($preEnd) >= strtotime($jan1OfYear));

$this->setStatus('running', 14, 'Calculating P&L YTD pre-movements…');
$pnlPreMovNet = collect();
if ($needPnlPre) {
    $pnlPreMovNet = $this->sumMovements($jan1OfYear, $preEnd);
}

// --- Baseline backout for P&L when report starts in baseline year (2024) and not January ---
// opening = beginning_balance(asOf 2024-12-31) - netMovements(startDate..2024-12-31)
$baselineAsOfObj = Carbon::parse($openingAsOf)->startOfDay();
$useBaselineBackout = Carbon::parse($sdate)->year === $baselineAsOfObj->year
    && Carbon::parse($sdate)->lte($baselineAsOfObj)
    && Carbon::parse($sdate)->month !== 1;

$baselineBackoutNet = collect();
if ($useBaselineBackout) {
    $this->setStatus('running', 16, 'Calculating baseline backout (P&L)…');
    $baselineBackoutNet = $this->sumMovements($sdate, $baselineAsOfObj->toDateString());
}

// =================== END ADD: TB-consistent RE const + P&L/BS opening prep ===================


            // 4) Period movements (detail rows) for [SDate..EDate]
            $this->setStatus('running', 28, 'Starting period SQL…');
            $rows = $this->periodRows($sdate, $edate);
            $this->setStatus('running', 38, 'Period rows loaded: '.count($rows));

            // 5) Assemble report rows per account with running balances
            $this->setStatus('running', 55, 'Assembling report…');

            $grouped = collect($rows)->groupBy('acct_code');
            $report  = []; // acct_code => [rows...]

            $totalAccounts = max(1, $accounts->count());
            $i = 0;

            foreach ($accounts as $acctCode => $acct) {
                $i++;
                $acctRows = $grouped->get($acctCode, collect())->sortBy([
                    ['post_date', 'asc'],
                    ['batch_no', 'asc'],
                    ['reference_no', 'asc'],
                ])->values();

// =================== BEGIN OVERWRITE: opening balance logic (match TB rules) ===================
$startDateObj = Carbon::parse($sdate)->startOfDay();
$opening = 0.0;

// Special: Retained Earnings 4031 uses computed constant, NOT transactional running
if (trim((string)$acctCode) === self::RE_CODE) {
    $baselineRe = (float)($openings[self::RE_CODE] ?? 0.0);
    $opening    = ($reConstStartYear !== null) ? (float)$reConstStartYear : (float)$baselineRe;

    // for GL: we will suppress detail rows for 4031 to keep it constant like TB
    $acctRows = collect(); // override any fetched rows
}
elseif (!empty($acct->is_pnl)) {
    // P&L:
    // - January start => 0.00 baseline for the year
    // - Baseline-year (2024) non-January => backout from 2024-12-31 snapshot
    // - Otherwise => YTD net from Jan 1..(start-1)
    if ($startDateObj->month === 1) {
        $opening = 0.0;
    } elseif ($useBaselineBackout) {
        $opening = (float)($openings[$acctCode] ?? 0.0) - (float)($baselineBackoutNet[$acctCode] ?? 0.0);
    } else {
        $opening = (float)($pnlPreMovNet[$acctCode] ?? 0.0);
    }
} else {
    // Balance Sheet: baseline + FY pre-movements (2025-01-01..start-1)
    $opening = (float)($openings[$acctCode] ?? 0.0)
             + (float)($preMovNet[$acctCode] ?? 0.0);
}
// =================== END OVERWRITE: opening balance logic (match TB rules) ===================

// =================== BEGIN ADD: month-opening rows (Beginning Balance repeats each month) ===================
$running = $opening;
$out = [];

// Opening line (kept)
$out[] = [
    'is_opening'     => true,
    'is_month_open'  => true,   // Jan/opening behaves like month opening too
    'month_label'    => Carbon::parse($sdate)->format('F Y'),
    'tran_date'      => $sdate,
    'post_date'      => $sdate,
    'batch_no'       => null,
    'reference_no'   => null,
    'party'          => null,
    'comment'        => 'Beginning Balance',
    'beginning'      => $opening,
    'debit'          => 0.0,
    'credit'         => 0.0,
    'ending'         => $running,
    'category'       => null,
    'acct_code'      => $acctCode,
    'acct_desc'      => $acct->acct_desc,
    'main_acct'      => $acct->main_acct,
    'main_acct_code' => $acct->main_acct_code,
];

// Detail lines with month-opening insert
$lastMonthKey = Carbon::parse($sdate)->format('Y-m');

foreach ($acctRows as $r) {
    $dt = Carbon::parse($r->post_date);
    $curMonthKey = $dt->format('Y-m');

    // when month changes, insert a month beginning line showing current running balance
    if ($curMonthKey !== $lastMonthKey) {
        $out[] = [
            'is_opening'     => false,
            'is_month_open'  => true,
            'month_label'    => $dt->format('F Y'),
            'tran_date'      => $dt->toDateString(),
            'post_date'      => $dt->toDateString(),
            'batch_no'       => null,
            'reference_no'   => null,
            'party'          => null,
            'comment'        => 'Beginning Balance',
            'beginning'      => $running,
            'debit'          => 0.0,
            'credit'         => 0.0,
            'ending'         => $running,
            'category'       => null,
            'acct_code'      => $acctCode,
            'acct_desc'      => $acct->acct_desc,
            'main_acct'      => $acct->main_acct,
            'main_acct_code' => $acct->main_acct_code,
        ];
        $lastMonthKey = $curMonthKey;
    }

    $debit   = (float)$r->debit;
    $credit  = (float)$r->credit;
    $running += ($debit - $credit);

    $out[] = [
        'is_opening'     => false,
        'is_month_open'  => false,
        'month_label'    => null,
        'tran_date'      => $dt->toDateString(),
        'post_date'      => $dt->toDateString(),
        'batch_no'       => $r->batch_no,
        'reference_no'   => $r->reference_no,
        'party'          => $r->party,
        'comment'        => $r->explanation,
        'beginning'      => null,
        'debit'          => $debit,
        'credit'         => $credit,
        'ending'         => $running,
        'category'       => $r->category,
        'acct_code'      => $acctCode,
        'acct_desc'      => $acct->acct_desc,
        'main_acct'      => $acct->main_acct,
        'main_acct_code' => $acct->main_acct_code,
    ];
}
// =================== END ADD: month-opening rows (Beginning Balance repeats each month) ===================


                $report[$acctCode] = $out;

                // progress
                $pct = 55 + (int)floor(($i / $totalAccounts) * 30); // 55..85
                $this->setStatus('running', $pct, "Assembling {$acctCode}…");
            }

            // 6) Render (PDF / Excel) and get target meta
            $this->setStatus('running', 86, 'Rendering file…');

            $target = ($this->format === 'xls' || $this->format === 'xlsx')
                ? $this->writeXls($report, $sdate, $edate)
                : $this->writePdf($report, $sdate, $edate, $this->orientation);

            // 7) Prune old files on the same disk
            $this->prune('reports', 2, $target['disk'] ?? 'local');

            // 8) Final state (store rel, abs, disk, etc.)
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
            Log::error('GL job failed', ['ticket' => $this->ticket, 'ex' => $e]);
            $this->setStatus('failed', 100, 'Error: '.$e->getMessage());
            throw $e;
        }
    }

    /* --------------------------- data helpers --------------------------- */

/**
 * Returns acct_code => net(debit-credit) for [from..to]
 * ✅ Includes ONLY header rows where is_cancel = 'n'
 */
/**
 * Returns acct_code => net(debit-credit) for [from..to]
 * ✅ Includes ONLY header rows where is_cancel = 'n'
 * ✅ ALWAYS company-scoped (no “companyId=0 means all”)
 */
private function sumMovements(string $from, string $to)
{
    $companyId = (int) $this->companyId;

    // hard safety (should already be guarded in handle(), but keep fail-safe)
    if ($companyId <= 0) {
        return collect();
    }

    $sql = "
    with src as (
        -- General Accounting
        select d.acct_code, sum(d.debit) deb, sum(d.credit) cred
        from general_accounting ga
        join general_accounting_details d
          on (d.transaction_id)::bigint = ga.id
        where ga.gen_acct_date between :s and :e
          and ga.is_cancel = 'n'
          and ga.company_id = :cid
          and d.acct_code between :sa and :ea
        group by d.acct_code

        union all

        -- Cash Disbursement
        select d.acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_disbursement h
        join cash_disbursement_details d on d.transaction_id = h.id
        where h.disburse_date between :s and :e
          and h.is_cancel = 'n'
          and h.company_id = :cid
          and d.acct_code between :sa and :ea
        group by d.acct_code

        union all

        -- Cash Receipts
        select d.acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_receipts h
        join cash_receipt_details d on (d.transaction_id)::bigint = h.id
        where h.receipt_date between :s and :e
          and h.is_cancel = 'n'
          and h.company_id = :cid
          and d.acct_code between :sa and :ea
        group by d.acct_code

        union all

        -- Cash Purchase
        select d.acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_purchase h
        join cash_purchase_details d on d.transaction_id = h.id
        where h.purchase_date between :s and :e
          and h.is_cancel = 'n'
          and h.company_id = :cid
          and d.acct_code between :sa and :ea
        group by d.acct_code

        union all

        -- Cash Sales
        select d.acct_code, sum(d.debit) deb, sum(d.credit) cred
        from cash_sales h
        join cash_sales_details d on (d.transaction_id)::bigint = h.id
        where h.sales_date between :s and :e
          and h.is_cancel = 'n'
          and h.company_id = :cid
          and d.acct_code between :sa and :ea
        group by d.acct_code
    )
    select acct_code, sum(deb) - sum(cred) as net
    from src
    group by acct_code
    ";

    $bindings = [
        's'   => $from,
        'e'   => $to,
        'sa'  => $this->startAccount,
        'ea'  => $this->endAccount,
        'cid' => $companyId, // ✅ ALWAYS bind cid (no conditional)
    ];

    $rows = DB::select($sql, $bindings);

    return collect($rows)
        ->keyBy('acct_code')
        ->map(fn($r) => (float) $r->net);
}

// =================== BEGIN ADD: RE helper methods (copied from TB) ===================

private function sumBeginningBalanceTotalGreaterOrEqual(int $threshold): float
{
    return (float) DB::table('beginning_balance as bb')
->where('bb.company_id', (int)$this->companyId)
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
 * full year [Jan 1..Dec 31], company-scoped.
 * ✅ Includes ONLY header rows where is_cancel = 'n'
 */
private function sumNetIncomeForYear(int $year): float
{
    $from = sprintf('%04d-01-01', $year);
    $to   = sprintf('%04d-12-31', $year);
    $cid  = (int) $this->companyId;

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
// =================== END ADD: RE helper methods (copied from TB) ===================




/**
 * Detail rows for the General Ledger for [from..to]
 * ✅ Includes ONLY header rows where is_cancel = 'n'
 */
/**
 * Detail rows for the General Ledger for [from..to]
 * ✅ Includes ONLY header rows where is_cancel = 'n'
 * ✅ ALWAYS company-scoped (no “companyId=0 means all”)
 */
private function periodRows(string $from, string $to): array
{
    $companyId = (int) $this->companyId;

    // hard safety (should already be guarded in handle(), but keep fail-safe)
    if ($companyId <= 0) {
        return [];
    }

    $sql = "
    select * from (
        -- General Accounting
        select
          'G' as category,
          ga.id as batch_no,
          ga.gen_acct_date as post_date,
          ga.ga_no as reference_no,
          null::text as party,
          ga.explanation,
          d.acct_code,
          sum(d.debit)  as debit,
          sum(d.credit) as credit
        from general_accounting ga
        join general_accounting_details d
          on (d.transaction_id)::bigint = ga.id
        where ga.gen_acct_date between :s and :e
          and ga.is_cancel = 'n'
          and ga.company_id = :cid
          and d.acct_code between :sa and :ea
        group by ga.id, ga.gen_acct_date, ga.ga_no, ga.explanation, d.acct_code

        union all

        -- Cash Disbursement
        select
          'D' as category,
          h.id as batch_no,
          h.disburse_date as post_date,
          h.cd_no as reference_no,
          v.vend_name as party,
          h.explanation,
          d.acct_code,
          sum(d.debit)  as debit,
          sum(d.credit) as credit
        from cash_disbursement h
        join cash_disbursement_details d on d.transaction_id = h.id
        left join vendor_list v
          on v.vend_code = h.vend_id
         and v.company_id::text = h.company_id::text
        where h.disburse_date between :s and :e
          and h.is_cancel = 'n'
          and h.company_id = :cid
          and d.acct_code between :sa and :ea
        group by h.id, h.disburse_date, h.cd_no, v.vend_name, h.explanation, d.acct_code

        union all

        -- Cash Receipts
        select
          'R' as category,
          h.id as batch_no,
          h.receipt_date as post_date,
          h.cr_no as reference_no,
          c.cust_name as party,
          h.details as explanation,
          d.acct_code,
          sum(d.debit)  as debit,
          sum(d.credit) as credit
        from cash_receipts h
        join cash_receipt_details d on (d.transaction_id)::bigint = h.id
        left join customer_list c
          on c.cust_id = h.cust_id
         and c.company_id::text = h.company_id::text
        where h.receipt_date between :s and :e
          and h.is_cancel = 'n'
          and h.company_id = :cid
          and d.acct_code between :sa and :ea
        group by h.id, h.receipt_date, h.cr_no, c.cust_name, h.details, d.acct_code

        union all

        -- Cash Purchase
        select
          'P' as category,
          h.id as batch_no,
          h.purchase_date as post_date,
          h.cp_no as reference_no,
          v.vend_name as party,
          h.explanation,
          d.acct_code,
          sum(d.debit)  as debit,
          sum(d.credit) as credit
        from cash_purchase h
        join cash_purchase_details d on d.transaction_id = h.id
        left join vendor_list v
          on v.vend_code = h.vend_id
         and v.company_id::text = h.company_id::text
        where h.purchase_date between :s and :e
          and h.is_cancel = 'n'
          and h.company_id = :cid
          and d.acct_code between :sa and :ea
        group by h.id, h.purchase_date, h.cp_no, v.vend_name, h.explanation, d.acct_code

        union all

        -- Cash Sales
        select
          'S' as category,
          h.id as batch_no,
          h.sales_date as post_date,
          h.cs_no as reference_no,
          c.cust_name as party,
          h.explanation,
          d.acct_code,
          sum(d.debit)  as debit,
          sum(d.credit) as credit
        from cash_sales h
        join cash_sales_details d on (d.transaction_id)::bigint = h.id
        left join customer_list c
          on c.cust_id = h.cust_id
         and c.company_id::text = h.company_id::text
        where h.sales_date between :s and :e
          and h.is_cancel = 'n'
          and h.company_id = :cid
          and d.acct_code between :sa and :ea
        group by h.id, h.sales_date, h.cs_no, c.cust_name, h.explanation, d.acct_code
    ) as u
    order by u.acct_code, u.post_date, u.batch_no
    ";

    $bindings = [
        's'   => $from,
        'e'   => $to,
        'sa'  => $this->startAccount,
        'ea'  => $this->endAccount,
        'cid' => $companyId, // ✅ ALWAYS bind cid (no conditional)
    ];

    return DB::select($sql, $bindings);
}

    /* --------------------------- writers --------------------------- */

private function writePdf(array $report, string $sdate, string $edate, string $orientation = 'landscape'): array
{
    $downloadName = $this->buildFriendlyDownloadName('pdf');
    $target       = $this->targetLocal('general-ledger', 'pdf', $downloadName);

    $pdf = new GLPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->setCompanyHeader((int)$this->companyId);

    $pdf->SetHeaderData('', 0, '', '');
    $pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN]);
    $pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(7, 35, 7);
    $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->SetFont('helvetica', '', 8);

    $isPortrait = strtolower($orientation) === 'portrait';
    $pageOrient = $isPortrait ? 'P' : 'L';
    $dateRange  = Carbon::parse($sdate)->format('Y-m-d') . ' -- ' . Carbon::parse($edate)->format('Y-m-d');

    // =================== BEGIN ADD: helpers ===================
    $esc = function (?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $money = function ($n): string {
        return number_format((float)$n, 2);
    };

 // Text-only font sizing to reduce wrapping (Cust/Vend, Comment only)
$textFontOpen  = '<font size="7.5">';
$textFontClose = '</font>';
   
    // Build a <colgroup> so TCPDF respects widths more consistently
    $colgroup = function (array $widths) {
        $out = "<colgroup>";
        foreach ($widths as $w) {
            $out .= "<col style=\"width:{$w};\" />";
        }
        $out .= "</colgroup>";
        return $out;
    };

    // =================== BEGIN OVERWRITE: width distribution (MUST total 100%) ===================
// =================== BEGIN PATCH: width distribution (EXACT 100%, prioritize Cust/Vend & Comment) ===================
if ($isPortrait) {
    // Portrait unchanged
    $W = [
        'date' => '10%',
        'ref'  => '14%',
        'comm' => '36%',
        'beg'  => '14%',
        'deb'  => '9%',
        'cred' => '9%',
        'end'  => '8%',
    ];
} else {
    // Landscape — minimal change from your preferred shape
    // Original wish: 5,5,27,26,10,8,8,9 = 98%
    // FIX: move missing 2% to text-heavy columns (Cust/Vend + Comment)

    $W = [
        'date'  => '5%',
        'ref'   => '5%',
        'party' => '28%',  // +1%
        'comm'  => '27%',  // +1%
        'beg'   => '10%',
        'deb'   => '8%',
        'cred'  => '8%',
        'end'   => '9%',
    ];
}
// =================== END PATCH: width distribution ===================

    // =================== END OVERWRITE: width distribution ===================

    foreach ($report as $acctCode => $rows) {
        if (empty($rows)) continue;

        $acctDesc = (string)($rows[0]['acct_desc'] ?? '');
        $mainAcct = (string)($rows[0]['main_acct'] ?? '');

        $pdf->AddPage($pageOrient, 'A4');

        // Header block (account heading)
        $hdr = "
<table border=\"0\" cellspacing=\"0\" cellpadding=\"0\" width=\"100%\">
  <tr>
    <td>
      <font size=\"15\"><b>GENERAL LEDGER - (" . $esc($mainAcct) . ")</b></font><br/>
      <font size=\"10\"><b>For the period covering " . $esc($dateRange) . "</b></font><br/>
      <font size=\"10\"><b>" . $esc((string)$acctCode) . " - " . $esc($acctDesc) . "</b></font>
    </td>
  </tr>
</table>
";
        $pdf->writeHTML($hdr, true, false, false, false, '');

        // =================== BEGIN OVERWRITE: ONE TABLE per account (thead repeats on page breaks) ===================
        $pdf->Ln(1);

        // Start building one big HTML table so headers repeat on page breaks
        $html  = "<table border=\"0\" cellspacing=\"0\" cellpadding=\"1\" width=\"100%\" style=\"table-layout:fixed;\">";
        if ($isPortrait) {
            $html .= $colgroup([$W['date'],$W['ref'],$W['comm'],$W['beg'],$W['deb'],$W['cred'],$W['end']]);
            $html .= "
<thead>
  <tr><td colspan=\"7\"><hr/></td></tr>
  <tr>
    <td align=\"left\"><b>Tran Date</b></td>
    <td align=\"left\"><b>Reference #</b></td>
    <td align=\"left\"><b>Posting Comment</b></td>
    <td align=\"right\"><b>Beginning</b></td>
    <td align=\"right\"><b>Debit</b></td>
    <td align=\"right\"><b>Credit</b></td>
    <td align=\"right\"><b>Ending</b></td>
  </tr>
  <tr><td colspan=\"7\"><hr/></td></tr>
</thead>
<tbody>
";
        } else {
            $html .= $colgroup([$W['date'],$W['ref'],$W['party'],$W['comm'],$W['beg'],$W['deb'],$W['cred'],$W['end']]);
            $html .= "
<thead>
  <tr><td colspan=\"8\"><hr/></td></tr>
  <tr>
    <td align=\"left\"><b>Tran Date</b></td>
    <td align=\"left\"><b>Reference #</b></td>
    <td align=\"left\"><b>Cust/Vend</b></td>
    <td align=\"left\"><b>Posting Comment</b></td>
    <td align=\"right\"><b>Beginning</b></td>
    <td align=\"right\"><b>Debit</b></td>
    <td align=\"right\"><b>Credit</b></td>
    <td align=\"right\"><b>Ending</b></td>
  </tr>
  <tr><td colspan=\"8\"><hr/></td></tr>
</thead>
<tbody>
";
        }

        $monthKey = null;
        $monthD = 0.0; $monthC = 0.0;
        $acctD  = 0.0; $acctC  = 0.0;

        $emitMonthSubtotal = function() use (&$html, $isPortrait, $money, $monthD, $monthC) {
            if ($isPortrait) {
                $html .= "<tr>
<td></td><td></td><td></td><td></td>
<td align=\"right\">____________</td>
<td align=\"right\">____________</td>
<td></td>
</tr>";
                $html .= "<tr>
<td></td><td></td><td></td><td></td>
<td align=\"right\"><b>{$money($monthD)}</b></td>
<td align=\"right\"><b>{$money($monthC)}</b></td>
<td></td>
</tr>";
            } else {
                $html .= "<tr>
<td></td><td></td><td></td><td></td><td></td>
<td align=\"right\">____________</td>
<td align=\"right\">____________</td>
<td></td>
</tr>";
                $html .= "<tr>
<td></td><td></td><td></td><td></td><td></td>
<td align=\"right\"><b>{$money($monthD)}</b></td>
<td align=\"right\"><b>{$money($monthC)}</b></td>
<td></td>
</tr>";
            }
        };

        foreach ($rows as $r) {
            $dt = Carbon::parse($r['post_date'] ?? $r['tran_date'] ?? $sdate);
            $m  = $dt->format('Y-m');

            if ($monthKey !== null && $monthKey !== $m) {
                $emitMonthSubtotal();
                $monthD = $monthC = 0.0;
            }
            $monthKey = $m;

            $isMonthOpen = !empty($r['is_month_open']);

            $ref   = (string)($r['reference_no'] ?? '');
            $party = (string)($r['party'] ?? '');
            $comm  = (string)($r['comment'] ?? '');

            $debit  = (float)($r['debit'] ?? 0);
            $credit = (float)($r['credit'] ?? 0);
            $ending = (float)($r['ending'] ?? 0);

$begDisp = $isMonthOpen ? $money((float)($r['beginning'] ?? 0.0)) : '';
$debDisp = $debit  == 0.0 ? '' : $money($debit);
$creDisp = $credit == 0.0 ? '' : $money($credit);

// ✅ blank Ending display on Beginning Balance rows
$endDisp = $isMonthOpen ? '' : $money($ending);

$commCell = $isMonthOpen ? "<b>".$esc($comm)."</b>" : $esc($comm);
$begCell  = $isMonthOpen ? "<b>{$begDisp}</b>" : $begDisp;

// ✅ since endDisp is blank on month-open, this will also be blank
$endCell  = $isMonthOpen ? '' : $endDisp;


            if ($isPortrait) {
                $html .= "<tr>
<td align=\"left\" valign=\"top\">".$esc($dt->format('m/d/Y'))."</td>
<td align=\"left\" valign=\"top\">".$esc($ref)."</td>
<td align=\"left\" valign=\"top\">{$commCell}</td>
<td align=\"right\" valign=\"top\">{$begCell}</td>
<td align=\"right\" valign=\"top\">{$debDisp}</td>
<td align=\"right\" valign=\"top\">{$creDisp}</td>
<td align=\"right\" valign=\"top\">{$endCell}</td>
</tr>";
            } else {
$html .= "<tr>
<td align=\"left\" valign=\"top\">".$esc($dt->format('m/d/Y'))."</td>
<td align=\"left\" valign=\"top\">".$esc($ref)."</td>
<td align=\"left\" valign=\"top\"><nobr>".$esc($party)."</nobr></td>
<td align=\"left\" valign=\"top\"><nobr>{$commCell}</nobr></td>
<td align=\"right\" valign=\"top\">{$begCell}</td>
<td align=\"right\" valign=\"top\">{$debDisp}</td>
<td align=\"right\" valign=\"top\">{$creDisp}</td>
<td align=\"right\" valign=\"top\">{$endCell}</td>
</tr>";

            }

            $monthD += $debit; $monthC += $credit;
            $acctD  += $debit; $acctC  += $credit;
        }

        // last month subtotal
        if ($monthKey !== null) {
            $emitMonthSubtotal();
        }

        // account totals
        $finalEnd = (float)end($rows)['ending'] ?? 0.0;
        $finalEndDisp = $money($finalEnd);

        if ($isPortrait) {
            $html .= "<tr>
<td></td><td></td><td></td><td></td>
<td align=\"right\">____________</td>
<td align=\"right\">____________</td>
<td align=\"right\">____________</td>
</tr>";
            $html .= "<tr>
<td></td><td></td><td></td><td></td>
<td align=\"right\"><b>".$money($acctD)."</b></td>
<td align=\"right\"><b>".$money($acctC)."</b></td>
<td align=\"right\"><b>{$finalEndDisp}</b></td>
</tr>";
        } else {
            $html .= "<tr>
<td></td><td></td><td></td><td></td><td></td>
<td align=\"right\">____________</td>
<td align=\"right\">____________</td>
<td align=\"right\">____________</td>
</tr>";
            $html .= "<tr>
<td></td><td></td><td></td><td></td><td></td>
<td align=\"right\"><b>".$money($acctD)."</b></td>
<td align=\"right\"><b>".$money($acctC)."</b></td>
<td align=\"right\"><b>{$finalEndDisp}</b></td>
</tr>";
        }

        $html .= "</tbody></table>";

        // IMPORTANT: write the whole table once so thead repeats on page breaks
        $pdf->writeHTML($html, true, false, false, false, '');
        // =================== END OVERWRITE: ONE TABLE per account ===================
    }

    $pdf->Output($target['abs'], 'F');
    return $target;
}



private function writeXls(array $report, string $sdate, string $edate): array
{
    $ext          = ($this->format === 'xls') ? 'xls' : 'xlsx';
    $downloadName = $this->buildFriendlyDownloadName($ext);
    $target       = $this->targetLocal('general-ledger', $ext, $downloadName);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $row = 1;
    $dateRangeTxt = \Carbon\Carbon::parse($sdate)->format('m/d/Y').' --- '.\Carbon\Carbon::parse($edate)->format('m/d/Y');

    // =================== BEGIN OVERWRITE: XLS column sizing + formats (A..H only) ===================
    foreach (range('A','H') as $col) {
        $sheet->getColumnDimension($col)->setWidth(18);
    }
    // Numeric columns: Beginning, Debit, Credit, Ending = E..H
    $sheet->getStyle('E:H')->getNumberFormat()->setFormatCode('#,##0.00');
    // =================== END OVERWRITE: XLS column sizing + formats (A..H only) ===================

    $bold = function($coord) use ($sheet) { $sheet->getStyle($coord)->getFont()->setBold(true); };

    foreach ($report as $acctCode => $rows) {
        if (empty($rows)) continue;

        $acctDesc = (string)($rows[0]['acct_desc'] ?? '');
        $mainAcct = (string)($rows[0]['main_acct'] ?? '');

        // ✅ Company-aware header (company_id aware)
        $cid = (int)($this->companyId ?? 0);

        $companyHdr = [
            'name'  => 'SUCDEN PHILIPPINES, INC.',
            'tin'   => 'TIN-000-105-2567-000',
            'addr1' => 'Unit 2202 The Podium West Tower',
            'addr2' => 'Ortigas Center, Mandaluyong City',
        ];

        if ($cid === 2) {
            $companyHdr = [
                'name'  => 'AMEROP PHILIPPINES, INC.',
                'tin'   => 'TIN- 762-592-927-000',
                'addr1' => 'Com. Unit 301-B Sitari Bldg., Lacson St. cor. C.I Montelibano Ave.,',
                'addr2' => 'Brgy. Mandalagan, Bacolod City',
            ];
        }

        // Company block
        $sheet->setCellValue("A{$row}", $companyHdr['name']);
        $sheet->mergeCells("A{$row}:H{$row}");
        $bold("A{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setSize(15);
        $row++;

        $sheet->setCellValue("A{$row}", $companyHdr['tin']);
        $sheet->mergeCells("A{$row}:H{$row}");
        $bold("A{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setSize(12);
        $row++;

        $sheet->setCellValue("A{$row}", $companyHdr['addr1']);
        $sheet->mergeCells("A{$row}:H{$row}");
        $bold("A{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", $companyHdr['addr2']);
        $sheet->mergeCells("A{$row}:H{$row}");
        $bold("A{$row}");
        $row++;

        $row++;
        $sheet->setCellValue("A{$row}", "GENERAL LEDGER - ({$mainAcct})");
        $sheet->mergeCells("A{$row}:H{$row}");
        $bold("A{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", "For the period covering: {$dateRangeTxt}");
        $sheet->mergeCells("A{$row}:H{$row}");
        $bold("A{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", "{$acctCode} - {$acctDesc}");
        $sheet->mergeCells("A{$row}:H{$row}");
        $bold("A{$row}");
        $row++;

        // Header row
        $row++;

        // =================== BEGIN OVERWRITE: XLS headers (remove Post Date + Batch #) ===================
        $headers = [
            'Tran Date',
            'Reference #',
            'Cust/Vend',
            'Posting Comment',
            'Beginning Balance',
            'Debit',
            'Credit',
            'Ending Balance'
        ];
        // =================== END OVERWRITE: XLS headers (remove Post Date + Batch #) ===================

        $col='A';
        foreach ($headers as $h) {
            $sheet->setCellValue("{$col}{$row}", $h);
            $sheet->getStyle("{$col}{$row}")->getFont()->setSize(12);
            $col++;
        }

        if ($row === 9) { // freeze only once the first time
            $sheet->freezePane("A".($row+1));
        }
        $row++;

        // =================== BEGIN OVERWRITE: XLS row rendering (supports month-opening rows) ===================
        $monthDebit = 0.0; $monthCredit = 0.0;
        $acctTotDebit = 0.0; $acctTotCredit = 0.0;
        $lastMonthKey = null;

        foreach ($rows as $r) {
            $dt = \Carbon\Carbon::parse($r['post_date'] ?? $r['tran_date'] ?? $sdate);
            $monthKey = $dt->format('Y-m');

            if ($lastMonthKey !== null && $lastMonthKey !== $monthKey) {
                // month subtotal lines (Debit=F, Credit=G)
                $row++;  $sheet->setCellValue("F{$row}", '-----------------'); $sheet->setCellValue("G{$row}", '-----------------');
                $row++;  $sheet->setCellValue("F{$row}", $monthDebit);        $sheet->setCellValue("G{$row}", $monthCredit);
                $row++;  /* spacing row */
                $monthDebit = 0.0; $monthCredit = 0.0;
            }
            $lastMonthKey = $monthKey;

            $isMonthOpen = !empty($r['is_month_open']);

            $debit  = (float)($r['debit']  ?? 0);
            $credit = (float)($r['credit'] ?? 0);
            $ending = (float)($r['ending'] ?? 0);

            $ref    = (string)($r['reference_no'] ?? '');
            $party  = (string)($r['party'] ?? '');
            $comm   = (string)($r['comment'] ?? '');

            // columns: A Date, B Ref, C Party, D Comment, E Beginning, F Debit, G Credit, H Ending
            $sheet->setCellValue("A{$row}", $dt->format('m/d/Y'));
            $sheet->setCellValue("B{$row}", $ref);
            $sheet->setCellValue("C{$row}", $party);
            $sheet->setCellValue("D{$row}", $comm);

            if ($isMonthOpen) {
                $sheet->setCellValue("E{$row}", (float)($r['beginning'] ?? 0.0));
                // make month opening label bold for visibility
                $sheet->getStyle("D{$row}")->getFont()->setBold(true);
                $sheet->getStyle("E{$row}")->getFont()->setBold(true);
                $sheet->getStyle("H{$row}")->getFont()->setBold(true);
            }

            if ($debit  != 0.0) $sheet->setCellValue("F{$row}", $debit);
            if ($credit != 0.0) $sheet->setCellValue("G{$row}", $credit);

            // ✅ blank Ending display on Beginning Balance rows
            if (!$isMonthOpen) {
                $sheet->setCellValue("H{$row}", $ending);
            } else {
                $sheet->setCellValue("H{$row}", ''); // show blank like the PDF
            }

            $monthDebit += $debit;   $monthCredit += $credit;
            $acctTotDebit += $debit; $acctTotCredit += $credit;

            $row++;
        }

        // Account totals (Debit=F, Credit=G, Ending=H)
        $row++; $sheet->setCellValue("F{$row}", '-----------------'); $sheet->setCellValue("G{$row}", '-----------------'); $sheet->setCellValue("H{$row}", '-----------------');
        $row++; $sheet->setCellValue("F{$row}", $acctTotDebit);      $sheet->setCellValue("G{$row}", $acctTotCredit);

        $finalEnding = (float)end($rows)['ending'] ?? 0.0;
        $sheet->setCellValue("H{$row}", $finalEnding);

        $row++; $sheet->setCellValue("F{$row}", '-----------------'); $sheet->setCellValue("G{$row}", '-----------------'); $sheet->setCellValue("H{$row}", '-----------------');
        $row += 3;
        // =================== END OVERWRITE: XLS row rendering (supports month-opening rows) ===================
    }

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




    /* --------------------------- file targets & utils --------------------------- */

    /** Build a human-friendly download name. */
    private function buildFriendlyDownloadName(string $ext): string
    {
        $acc = "{$this->startAccount}-{$this->endAccount}";
        $s   = Carbon::parse($this->startDate)->format('Y-m-d');
        $e   = Carbon::parse($this->endDate)->format('Y-m-d');
        return "GeneralLedger_{$acc}_{$s}_to_{$e}.{$ext}";
    }

    /** Create a local target under storage/app/reports and return meta. */
    private function targetLocal(string $base, string $ext, string $downloadName): array
    {
        $disk = Storage::disk('local');     // storage/app
        $dir  = 'reports';
        $disk->makeDirectory($dir);         // idempotent

        $internal = sprintf('%s_%s_%s.%s', $base, now()->format('YmdHis'), Str::uuid(), $ext);
        $rel = "{$dir}/{$internal}";
        $abs = storage_path("app/{$rel}");

        return [
            'disk'          => 'local',       // where controllers will read from
            'rel'           => $rel,          // e.g. reports/general-ledger_...
            'abs'           => $abs,          // absolute path for writers
            'url'           => null,          // reserved if you later switch to 'public'
            'ext'           => $ext,          // 'pdf' | 'xls' | 'xlsx'
            'download_name' => $downloadName, // friendly name for browser
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
        return "gl:{$ticket}";
    }

    /* --- small helpers for PDF HTML blocks --- */

    private function monthSubtotalHtmlLandscape(string $totD, string $totC): string
    {
        return <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr>
    <td width="64%"></td>
    <td width="9%" align="right"><font size="8">____________</font></td>
    <td width="9%" align="right"><font size="8">____________</font></td>
    <td width="9%" align="right"><font size="8"></font></td>
  </tr>
  <tr>
    <td width="64%"></td>
    <td width="9%" align="right"><font size="8"><b>{$totD}</b></font></td>
    <td width="9%" align="right"><font size="8"><b>{$totC}</b></font></td>
    <td width="9%" align="right"><font size="8"><b></b></font></td>
  </tr>
  <tr><td colspan="10"><br/></td></tr>
</table>
HTML;
    }

    private function monthSubtotalHtmlPortrait(string $totD, string $totC): string
    {
        return <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr>
    <td width="55%"></td>
    <td width="11%" align="right"><font size="8">____________</font></td>
    <td width="11%" align="right"><font size="8">____________</font></td>
    <td width="11%" align="right"><font size="8"></font></td>
  </tr>
  <tr>
    <td width="55%"></td>
    <td width="11%" align="right"><font size="8"><b>{$totD}</b></font></td>
    <td width="11%" align="right"><font size="8"><b>{$totC}</b></font></td>
    <td width="11%" align="right"><font size="8"><b></b></font></td>
  </tr>
  <tr><td colspan="8"><br/></td></tr>
</table>
HTML;
    }

    private function accountTotalsHtmlLandscape(string $totD, string $totC, string $end): string
    {
        return <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr>
    <td width="64%"></td>
    <td width="9%" align="right"><font size="8">____________</font></td>
    <td width="9%" align="right"><font size="8">____________</font></td>
    <td width="9%" align="right"><font size="8">____________</font></td>
  </tr>
  <tr>
    <td width="64%"></td>
    <td width="9%" align="right"><font size="8"><b>{$totD}</b></font></td>
    <td width="9%" align="right"><font size="8"><b>{$totC}</b></font></td>
    <td width="9%" align="right"><font size="8"><b>{$end}</b></font></td>
  </tr>
  <tr>
    <td width="64%"></td>
    <td width="9%" align="right"><font size="8">--------------------</font></td>
    <td width="9%" align="right"><font size="8">--------------------</font></td>
    <td width="9%" align="right"><font size="8">--------------------</font></td>
  </tr>
</table>
HTML;
    }

    private function accountTotalsHtmlPortrait(string $totD, string $totC, string $end): string
    {
        return <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr>
    <td width="55%"></td>
    <td width="11%" align="right"><font size="8">____________</font></td>
    <td width="11%" align="right"><font size="8">____________</font></td>
    <td width="11%" align="right"><font size="8">____________</font></td>
  </tr>
  <tr>
    <td width="55%"></td>
    <td width="11%" align="right"><font size="8"><b>{$totD}</b></font></td>
    <td width="11%" align="right"><font size="8"><b>{$totC}</b></font></td>
    <td width="11%" align="right"><font size="8"><b>{$end}</b></font></td>
  </tr>
  <tr>
    <td width="55%"></td>
    <td width="11%" align="right"><font size="8">--------------------</font></td>
    <td width="11%" align="right"><font size="8">--------------------</font></td>
    <td width="11%" align="right"><font size="8">--------------------</font></td>
  </tr>
</table>
HTML;
    }

    private function escape(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
