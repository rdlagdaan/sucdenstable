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

            $sdate = Carbon::parse($this->startDate)->startOfDay()->toDateString();
            $edate = Carbon::parse($this->endDate)->endOfDay()->toDateString();

            // Opening baseline date: 2024-12-31 (from beginning_balance)
            $openingAsOf = '2024-12-31';
            $fyStart     = Carbon::parse($openingAsOf)->addDay()->toDateString(); // 2025-01-01

            // 1) Accounts in range
            $accounts = DB::table('account_code as ac')
                ->leftJoin('account_main as am', 'am.main_acct_code', '=', 'ac.main_acct_code')
                ->selectRaw("
                    ac.acct_code,
                    ac.acct_desc,
                    ac.acct_number,
                    ac.main_acct_code,
                    COALESCE(am.main_acct, ac.main_acct) as main_acct,
                    CASE
                      WHEN ac.fs ILIKE 'P&L%' OR ac.acct_type ILIKE 'P&L%' THEN true
                      WHEN ac.fs ILIKE 'Profit%' THEN true
                      WHEN ac.acct_number > 4031 THEN true
                      ELSE false
                    END as is_pnl
                ")
                ->where('ac.active_flag', 1)
                ->whereBetween('ac.acct_code', [$this->startAccount, $this->endAccount])
                ->when($this->companyId, fn($q) => $q->where('ac.company_id', $this->companyId))
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
    ->when($this->companyId > 0, fn($q) => $q->where('bb.company_id', $this->companyId))
    ->get()
    ->keyBy('account_code')
    ->map(fn($r) => (float)$r->amount);


            // 3) Pre-calc YTD movements (B/S only) from 2025-01-01 to SDate-1
            $preEnd  = Carbon::parse($sdate)->subDay()->toDateString();
            $needPre = (strtotime($preEnd) >= strtotime($fyStart));

            $this->setStatus('running', 12, 'Calculating YTD pre-movements…');
            $preMov = collect();
            if ($needPre) {
                $preMov = $this->sumMovements($fyStart, $preEnd);
            }

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

                // Opening balance
                $opening = 0.0;
                if (!$acct->is_pnl) {
                    $opening = (float)($openings[$acctCode] ?? 0.0)
                             + (float)($preMov[$acctCode] ?? 0.0);
                }

                $running = $opening;
                $out = [];

                // Opening line
                $out[] = [
                    'is_opening'     => true,
                    'tran_date'      => $sdate,
                    'post_date'      => $sdate,
                    'batch_no'       => null,
                    'reference_no'   => null,
                    'party'          => null,
                    'comment'        => 'Beginning Balances ('.$acct->acct_desc.')',
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

                // Detail lines
                foreach ($acctRows as $r) {
                    $debit   = (float)$r->debit;
                    $credit  = (float)$r->credit;
                    $running += ($debit - $credit);
                    $out[] = [
                        'is_opening'     => false,
                        'tran_date'      => $r->post_date,
                        'post_date'      => $r->post_date,
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

    private function sumMovements(string $from, string $to)
    {
        $companyId = $this->companyId;

        $sql = "
        with src as (
            -- General
            select d.acct_code, sum(d.debit) deb, sum(d.credit) cred
            from general_accounting ga
            join general_accounting_details d
              on (d.transaction_id)::bigint = ga.id
            where ga.gen_acct_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and ga.company_id = :cid" : "") . "
            group by d.acct_code

            union all
            select d.acct_code, sum(d.debit), sum(d.credit)
            from cash_disbursement h
            join cash_disbursement_details d on d.transaction_id = h.id
            where h.disburse_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and h.company_id = :cid" : "") . "
            group by d.acct_code

            union all
            select d.acct_code, sum(d.debit), sum(d.credit)
            from cash_receipts h
            join cash_receipt_details d on (d.transaction_id)::bigint = h.id
            where h.receipt_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and h.company_id = :cid" : "") . "
            group by d.acct_code

            union all
            select d.acct_code, sum(d.debit), sum(d.credit)
            from cash_purchase h
            join cash_purchase_details d on d.transaction_id = h.id
            where h.purchase_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and h.company_id = :cid" : "") . "
            group by d.acct_code

            union all
            select d.acct_code, sum(d.debit), sum(d.credit)
            from cash_sales h
            join cash_sales_details d on (d.transaction_id)::bigint = h.id
            where h.sales_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and h.company_id = :cid" : "") . "
            group by d.acct_code
        )
        select acct_code, sum(deb) - sum(cred) as net
        from src
        group by acct_code
        ";

        $bindings = [
            's'  => $from,
            'e'  => $to,
            'sa' => $this->startAccount,
            'ea' => $this->endAccount,
        ];
        if ($companyId) $bindings['cid'] = $companyId;

        $rows = DB::select($sql, $bindings);
        return collect($rows)->keyBy('acct_code')->map(fn($r) => (float)$r->net);
    }

    private function periodRows(string $from, string $to): array
    {
        $companyId = $this->companyId;

        $sql = "
        select * from (
            -- General
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
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and ga.company_id = :cid" : "") . "
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
              sum(d.debit), sum(d.credit)
            from cash_disbursement h
            join cash_disbursement_details d on d.transaction_id = h.id
            left join vendor_list v on v.vend_code = h.vend_id
            where h.disburse_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and h.company_id = :cid" : "") . "
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
              sum(d.debit), sum(d.credit)
            from cash_receipts h
            join cash_receipt_details d on (d.transaction_id)::bigint = h.id
            left join customer_list c on c.cust_id = h.cust_id
            where h.receipt_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and h.company_id = :cid" : "") . "
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
              sum(d.debit), sum(d.credit)
            from cash_purchase h
            join cash_purchase_details d on d.transaction_id = h.id
            left join vendor_list v on v.vend_code = h.vend_id
            where h.purchase_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and h.company_id = :cid" : "") . "
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
              sum(d.debit), sum(d.credit)
            from cash_sales h
            join cash_sales_details d on (d.transaction_id)::bigint = h.id
            left join customer_list c on c.cust_id = h.cust_id
            where h.sales_date between :s and :e
              and d.acct_code between :sa and :ea
              " . ($companyId ? "and h.company_id = :cid" : "") . "
            group by h.id, h.sales_date, h.cs_no, c.cust_name, h.explanation, d.acct_code
        ) as u
        order by u.acct_code, u.post_date, u.batch_no
        ";

        $bindings = [
            's'  => $from,
            'e'  => $to,
            'sa' => $this->startAccount,
            'ea' => $this->endAccount,
        ];
        if ($companyId) $bindings['cid'] = $companyId;

        return DB::select($sql, $bindings);
    }

    /* --------------------------- writers --------------------------- */

    private function writePdf(array $report, string $sdate, string $edate, string $orientation = 'landscape'): array
    {
        $downloadName = $this->buildFriendlyDownloadName('pdf');
        $target       = $this->targetLocal('general-ledger', 'pdf', $downloadName);

        $pdf = new GLPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetHeaderData('', 0, '', '');
        $pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN]);
        $pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $pdf->SetMargins(7, 35, 7);
        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $pdf->SetFont('helvetica', '', 7);

        $isPortrait = strtolower($orientation) === 'portrait';
        $pageOrient = $isPortrait ? 'P' : 'L';
        $dateRange  = Carbon::parse($sdate)->format('Y-m-d').' -- '.Carbon::parse($edate)->format('Y-m-d');

        $first = true;
        foreach ($report as $acctCode => $rows) {
            if (empty($rows)) continue;

            $acctDesc = (string)($rows[0]['acct_desc'] ?? '');
            $mainAcct = (string)($rows[0]['main_acct'] ?? '');

            $pdf->AddPage($pageOrient, 'A4');
            if ($first) $first = false;

            $hdr = <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr>
    <td>
      <font size="15"><b>GENERAL LEDGER - ({$mainAcct})</b></font><br/>
      <font size="10"><b>For the period covering {$dateRange}</b></font><br/>
      <font size="10"><b>{$acctCode} - {$acctDesc}</b></font>
    </td>
  </tr>
</table>
HTML;
            $pdf->writeHTML($hdr, true, false, false, false, '');

            // Columns
            $cols = $isPortrait
                ? <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr><td colspan="8"><br/><hr/></td></tr>
  <tr>
    <td width="8%"><font size="10">Date</font></td>
    <td width="8%"><font size="10">Batch #</font></td>
    <td width="9%"><font size="10">Reference #</font></td>
    <td width="30%"><font size="10">Posting Comment</font></td>
    <td width="11%" align="center"><font size="10">Beginning Balance</font></td>
    <td width="11%" align="center"><font size="10">Debit</font></td>
    <td width="11%" align="center"><font size="10">Credit</font></td>
    <td width="11%" align="center"><font size="10">Ending Balance</font></td>
  </tr>
  <tr><td colspan="8"><hr/></td></tr>
</table>
HTML
                : <<<HTML
<table border="0" cellspacing="0" cellpadding="0" width="100%">
  <tr><td colspan="10"><br/><hr/></td></tr>
  <tr>
    <td width="6%"><font size="10">Tran Date</font></td>
    <td width="6%"><font size="10">Post Date</font></td>
    <td width="7%"><font size="10">Batch #</font></td>
    <td width="7%"><font size="10">Reference #</font></td>
    <td width="15%"><font size="10">Cust/Vend</font></td>
    <td width="21%"><font size="10">Posting Comment</font></td>
    <td width="9%" align="center"><font size="10">Beginning Balance</font></td>
    <td width="9%" align="center"><font size="10">Debit</font></td>
    <td width="9%" align="center"><font size="10">Credit</font></td>
    <td width="9%" align="center"><font size="10">Ending Balance</font></td>
  </tr>
  <tr><td colspan="10"><hr/></td></tr>
</table>
HTML;
            $pdf->writeHTML($cols, true, false, false, false, '');

            // Beginning row
            $opening     = (float)($rows[0]['beginning'] ?? 0.0);
            $openingDisp = number_format($opening, 2);
            $begLine = $isPortrait
                ? "<table border='0' cellspacing='0' cellpadding='0' width='100%'><tr><td width='55%'><font size='10'><b>Beginning Balances ({$acctDesc})</b></font></td><td width='11%' align='right'><font size='10'><b>{$openingDisp}</b></font></td><td width='11%'></td><td width='11%'></td><td width='11%'></td></tr></table>"
                : "<table border='0' cellspacing='0' cellpadding='0' width='100%'><tr><td width='64%'><font size='10'><b>Beginning Balances ({$acctDesc})</b></font></td><td width='9%' align='right'><font size='10'><b>{$openingDisp}</b></font></td><td width='9%'></td><td width='9%'></td><td width='9%'></td></tr></table>";
            $pdf->writeHTML($begLine, true, false, false, false, '');

            // Details + subtotals
            $monthKey = null; $monthD = 0.0; $monthC = 0.0; $acctD = 0.0; $acctC = 0.0;

            foreach ($rows as $r) {
                if (!empty($r['is_opening'])) continue;

                $dt = Carbon::parse($r['post_date']);
                $m  = $dt->format('Y-m');
                if ($monthKey !== null && $monthKey !== $m) {
                    $pdf->writeHTML(
                        $isPortrait
                            ? $this->monthSubtotalHtmlPortrait(number_format($monthD,2), number_format($monthC,2))
                            : $this->monthSubtotalHtmlLandscape(number_format($monthD,2), number_format($monthC,2)),
                        true, false, false, false, ''
                    );
                    $monthD = $monthC = 0.0;
                }
                $monthKey = $m;

                $debit  = (float)($r['debit']  ?? 0);
                $credit = (float)($r['credit'] ?? 0);
                $ending = (float)($r['ending'] ?? 0);

                $debDisp = $debit  == 0.0 ? '' : number_format($debit, 2);
                $creDisp = $credit == 0.0 ? '' : number_format($credit, 2);
                $endDisp = number_format($ending, 2);

                $rowHtml = $isPortrait
                    ? "
<table border='0' cellspacing='0' cellpadding='0' width='100%'>
  <tr>
    <td width='8%'><font size='8'>{$dt->format('m/d/Y')}</font></td>
    <td width='8%'><font size='8'>".$this->escape((string)($r['batch_no'] ?? ''))."</font></td>
    <td width='9%'><font size='8'>".$this->escape((string)($r['reference_no'] ?? ''))."</font></td>
    <td width='30%'><font size='8'>".$this->escape((string)($r['comment'] ?? ''))."</font></td>
    <td width='11%'></td>
    <td width='11%' align='right'><font size='8'>{$debDisp}</font></td>
    <td width='11%' align='right'><font size='8'>{$creDisp}</font></td>
    <td width='11%' align='right'><font size='8'>{$endDisp}</font></td>
  </tr>
</table>"
                    : "
<table border='0' cellspacing='0' cellpadding='0' width='100%'>
  <tr>
    <td width='6%'><font size='8'>{$dt->format('m/d/Y')}</font></td>
    <td width='6%'><font size='8'>{$dt->format('m/d/Y')}</font></td>
    <td width='7%'><font size='8'>".$this->escape((string)($r['batch_no'] ?? ''))."</font></td>
    <td width='7%'><font size='8'>".$this->escape((string)($r['reference_no'] ?? ''))."</font></td>
    <td width='15%'><font size='8'>".$this->escape((string)($r['party'] ?? ''))."</font></td>
    <td width='21%'><font size='8'>".$this->escape((string)($r['comment'] ?? ''))."</font></td>
    <td width='9%'></td>
    <td width='9%' align='right'><font size='8'>{$debDisp}</font></td>
    <td width='9%' align='right'><font size='8'>{$creDisp}</font></td>
    <td width='9%' align='right'><font size='8'>{$endDisp}</font></td>
  </tr>
</table>";
                $pdf->writeHTML($rowHtml, false, false, false, false, '');
                $monthD += $debit; $monthC += $credit; $acctD += $debit; $acctC += $credit;
            }

            if ($monthKey !== null) {
                $pdf->writeHTML(
                    $isPortrait
                        ? $this->monthSubtotalHtmlPortrait(number_format($monthD,2), number_format($monthC,2))
                        : $this->monthSubtotalHtmlLandscape(number_format($monthD,2), number_format($monthC,2)),
                    true, false, false, false, ''
                );
            }

            $finalEnd = (float)end($rows)['ending'] ?? 0.0;
            $pdf->writeHTML(
                $isPortrait
                    ? $this->accountTotalsHtmlPortrait(number_format($acctD,2), number_format($acctC,2), number_format($finalEnd,2))
                    : $this->accountTotalsHtmlLandscape(number_format($acctD,2), number_format($acctC,2), number_format($finalEnd,2)),
                true, false, false, false, ''
            );
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

    // ---- set once (avoids style bloat) ----
    foreach (range('A','J') as $col) {
        $sheet->getColumnDimension($col)->setWidth(15);
    }
    // Number format for numeric columns (applies to all rows)
    $sheet->getStyle('G:J')->getNumberFormat()->setFormatCode('#,##0.00');
    // --------------------------------------

    $bold = function($coord) use ($sheet) { $sheet->getStyle($coord)->getFont()->setBold(true); };

    foreach ($report as $acctCode => $rows) {
        if (empty($rows)) continue;

        $acctDesc = (string)($rows[0]['acct_desc'] ?? '');
        $mainAcct = (string)($rows[0]['main_acct'] ?? '');

        // Company block
        $sheet->setCellValue("A{$row}", 'SUCDEN PHILIPPINES, INC.');
        $sheet->mergeCells("A{$row}:G{$row}");
        $bold("A{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setSize(15);
        $row++;

        $sheet->setCellValue("A{$row}", 'TIN-000-105-2567-000');
        $sheet->mergeCells("A{$row}:G{$row}");
        $bold("A{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setSize(12);
        $row++;

        $sheet->setCellValue("A{$row}", 'Unit 2202 The Podium West Tower');
        $sheet->mergeCells("A{$row}:G{$row}");
        $bold("A{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", 'Ortigas Center, Mandaluyong City');
        $sheet->mergeCells("A{$row}:G{$row}");
        $bold("A{$row}");
        $row++;

        $row++;
        $sheet->setCellValue("A{$row}", "GENERAL LEDGER - ({$mainAcct})");
        $sheet->mergeCells("A{$row}:G{$row}");
        $bold("A{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", "For the period covering: {$dateRangeTxt}");
        $sheet->mergeCells("A{$row}:G{$row}");
        $bold("A{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", "{$acctCode} - {$acctDesc}");
        $sheet->mergeCells("A{$row}:G{$row}");
        $bold("A{$row}");
        $row++;

        // Header row
        $row++;
        $headers = ['Tran Date','Post Date','Batch #','Reference #','Cust/Vend','Posting Comment','Beginning Balance','Debit','Credit','Ending Balance'];
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

        // Beginning
        $opening = (float)($rows[0]['beginning'] ?? 0.0);
        $sheet->setCellValue("A{$row}", "Beginning Balances ( {$acctDesc} )");
        $sheet->mergeCells("A{$row}:F{$row}");
        $bold("A{$row}");
        $sheet->setCellValue("G{$row}", $opening); 
        $row++;

        // Details
        $monthDebit = 0.0; $monthCredit = 0.0;
        $acctTotDebit = 0.0; $acctTotCredit = 0.0;
        $lastMonthKey = null;

        foreach ($rows as $r) {
            if (!empty($r['is_opening'])) continue;

            $dt = \Carbon\Carbon::parse($r['post_date']); // using post_date for both columns
            $monthKey = $dt->format('Y-m');

            if ($lastMonthKey !== null && $lastMonthKey !== $monthKey) {
                // month subtotal lines
                $row++;  $sheet->setCellValue("H{$row}", '-----------------'); $sheet->setCellValue("I{$row}", '-----------------');
                $row++;  $sheet->setCellValue("H{$row}", $monthDebit);        $sheet->setCellValue("I{$row}", $monthCredit);
                $row++;  /* spacing row */                                   
                $monthDebit = 0.0; $monthCredit = 0.0;
            }
            $lastMonthKey = $monthKey;

            $debit  = (float)($r['debit']  ?? 0);
            $credit = (float)($r['credit'] ?? 0);
            $ending = (float)($r['ending'] ?? 0);

            $sheet->setCellValue("A{$row}", $dt->format('m/d/Y'));
            $sheet->setCellValue("B{$row}", $dt->format('m/d/Y'));
            $sheet->setCellValue("C{$row}", (string)($r['batch_no'] ?? ''));
            $sheet->setCellValue("D{$row}", (string)($r['reference_no'] ?? ''));
            $sheet->setCellValue("E{$row}", (string)($r['party'] ?? ''));
            $sheet->setCellValue("F{$row}", (string)($r['comment'] ?? ''));
            if ($debit  != 0.0) $sheet->setCellValue("H{$row}", $debit);
            if ($credit != 0.0) $sheet->setCellValue("I{$row}", $credit);
            $sheet->setCellValue("J{$row}", $ending);

            $monthDebit += $debit;   $monthCredit += $credit;
            $acctTotDebit += $debit; $acctTotCredit += $credit;
            $row++;
        }

        // Account totals
        $row++; $sheet->setCellValue("H{$row}", '-----------------'); $sheet->setCellValue("I{$row}", '-----------------'); $sheet->setCellValue("J{$row}", '-----------------');
        $row++; $sheet->setCellValue("H{$row}", $acctTotDebit); $sheet->setCellValue("I{$row}", $acctTotCredit);
        $finalEnding = (float)end($rows)['ending'] ?? 0.0;
        $sheet->setCellValue("J{$row}", $finalEnding);
        $row++; $sheet->setCellValue("H{$row}", '-----------------'); $sheet->setCellValue("I{$row}", '-----------------'); $sheet->setCellValue("J{$row}", '-----------------');
        $row += 3;
    }

    $writer = ($ext === 'xls')
        ? new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet)
        : new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

    if (method_exists($writer, 'setPreCalculateFormulas')) {
        $writer->setPreCalculateFormulas(false);
    }

    $writer->save($target['abs']);
    // optional tidy-up (memory), not required for opening performance
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
