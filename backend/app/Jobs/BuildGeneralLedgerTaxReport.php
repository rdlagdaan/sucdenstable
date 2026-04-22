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

class BuildGeneralLedgerTaxReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public string $ticket,
        public string $startAccount,
        public string $endAccount,
        public string $startDate,
        public string $endDate,
        public string $format,       // pdf|xls|xlsx
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

            $sdate = Carbon::parse($this->startDate)->startOfDay()->toDateTimeString();
            $edate = Carbon::parse($this->endDate)->endOfDay()->toDateTimeString();

            $rangeStart = min($this->startAccount, $this->endAccount);
            $rangeEnd   = max($this->startAccount, $this->endAccount);

            $this->setStatus('running', 8, 'Loading account list…');

            $accounts = DB::table('account_code as ac')
                ->selectRaw("
                    trim(ac.acct_code) as acct_code,
                    ac.acct_desc,
                    ac.acct_number
                ")
                ->where('ac.active_flag', 1)
                ->where('ac.company_id', $cid)
                ->whereRaw("trim(ac.acct_code) between ? and ?", [$rangeStart, $rangeEnd])
                ->orderBy('ac.acct_number')
                ->get()
                ->keyBy('acct_code');

            if ($accounts->isEmpty()) {
                $this->setStatus('failed', 100, 'No accounts in range.');
                return;
            }

            $this->setStatus('running', 15, 'Loading tax configuration…');
            [$ewtCode, $inputTaxCode] = $this->loadTaxCodes();

            $this->setStatus('running', 25, 'Loading report rows…');
            $rows = $this->periodRows($sdate, $edate, $ewtCode, $inputTaxCode);

            $this->setStatus('running', 50, 'Assembling report…');

            $grouped = collect($rows)->groupBy('acct_code');
            $report  = [];

            $totalAccounts = max(1, $accounts->count());
            $i = 0;

            foreach ($accounts as $acctCode => $acct) {
                $i++;

                $acctRows = $grouped->get($acctCode, collect())
                    ->sortBy([
                        ['post_date', 'asc'],
                        ['batch_no', 'asc'],
                        ['reference_no', 'asc'],
                    ])
                    ->values()
                    ->all();

                if (!empty($acctRows)) {
                    foreach ($acctRows as $r) {
                        $r->acct_desc = $acct->acct_desc;
                    }

                    $report[$acctCode] = $acctRows;
                }

                $pct = 50 + (int) floor(($i / $totalAccounts) * 25);
                $this->setStatus('running', $pct, "Assembling {$acctCode}…");
            }

            if (empty($report)) {
                $this->setStatus('failed', 100, 'No tax report data found for selected range.');
                return;
            }

            $this->setStatus('running', 80, 'Rendering file…');

            $target = ($this->format === 'xls' || $this->format === 'xlsx')
                ? $this->writeXls($report, $sdate, $edate)
                : $this->writePdf($report, $sdate, $edate);

            $this->prune('reports', 2, $target['disk'] ?? 'local');

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
            Log::error('GL tax report job failed', [
                'ticket'  => $this->ticket,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            $this->setStatus('failed', 100, 'Error: '.$e->getMessage());
            throw $e;
        }
    }

    private function loadTaxCodes(): array
    {
        $raw = (string) DB::table('application_settings')
            ->where('apset_code', 'TaxListReport')
            ->value('value');

        $parts = array_values(array_filter(array_map('trim', explode(';', $raw))));

        // Based on your setting example: 3074;1501
        // first = EWT, second = Input Tax
        $ewtCode      = $parts[0] ?? '3074';
        $inputTaxCode = $parts[1] ?? '1501';

        return [$ewtCode, $inputTaxCode];
    }

    private function periodRows(string $from, string $to, string $ewtCode, string $inputTaxCode): array
    {
        $companyId = (int) $this->companyId;
        if ($companyId <= 0) {
            return [];
        }

        $fromDate = Carbon::parse($from)->toDateString();
        $toDate   = Carbon::parse($to)->toDateString();

        $sa = min($this->startAccount, $this->endAccount);
        $ea = max($this->startAccount, $this->endAccount);

        $sql = "
        select * from (
            -- Cash Disbursement source
            select
                'D' as category,
                h.id as batch_no,
                h.disburse_date::date as post_date,
                h.cd_no as reference_no,
                trim(ed.acct_code) as acct_code,
                v.vend_name as party,
                v.vendor_address as address,
                h.explanation,
                coalesce(sum(ed.debit),0) as debit,
                coalesce(sum(ed.credit),0) as credit,

                abs(coalesce((
                    select sum(coalesce(td.debit,0) - coalesce(td.credit,0))
                    from cash_disbursement_details td
                    where td.transaction_id = h.id
                      and trim(td.acct_code) = :input_tax_code
                ),0)) as input_tax,

                abs(coalesce((
                    select sum(coalesce(td.credit,0) - coalesce(td.debit,0))
                    from cash_disbursement_details td
                    where td.transaction_id = h.id
                      and trim(td.acct_code) = :ewt_code
                ),0)) as ewt

            from cash_disbursement h
            join cash_disbursement_details ed
              on ed.transaction_id = h.id
            left join vendor_list v
              on v.vend_code = h.vend_id
             and v.company_id::text = h.company_id::text
            where h.disburse_date::date between :s and :e
              and h.is_cancel = 'n'
              and h.company_id = :cid
              and trim(ed.acct_code) between :sa and :ea
            group by
                h.id, h.disburse_date, h.cd_no,
                trim(ed.acct_code),
                v.vend_name, v.vendor_address,
                h.explanation

            union all

            -- General Accounting source
            select
                'G' as category,
                ga.id as batch_no,
                ga.gen_acct_date::date as post_date,
                ga.ga_no as reference_no,
                trim(ed.acct_code) as acct_code,
                ''::text as party,
                ''::text as address,
                ga.explanation,
                coalesce(sum(ed.debit),0) as debit,
                coalesce(sum(ed.credit),0) as credit,

                abs(coalesce((
                    select sum(coalesce(td.debit,0) - coalesce(td.credit,0))
                    from general_accounting_details td
                    where (td.transaction_id)::bigint = ga.id
                      and trim(td.acct_code) = :input_tax_code
                ),0)) as input_tax,

                abs(coalesce((
                    select sum(coalesce(td.credit,0) - coalesce(td.debit,0))
                    from general_accounting_details td
                    where (td.transaction_id)::bigint = ga.id
                      and trim(td.acct_code) = :ewt_code
                ),0)) as ewt

            from general_accounting ga
            join general_accounting_details ed
              on (ed.transaction_id)::bigint = ga.id
            where ga.gen_acct_date::date between :s and :e
              and ga.is_cancel = 'n'
              and ga.company_id = :cid
              and trim(ed.acct_code) between :sa and :ea
            group by
                ga.id, ga.gen_acct_date, ga.ga_no,
                trim(ed.acct_code),
                ga.explanation
        ) as u
        order by u.acct_code, u.post_date, u.batch_no
        ";

        return DB::select($sql, [
            's'              => $fromDate,
            'e'              => $toDate,
            'sa'             => $sa,
            'ea'             => $ea,
            'cid'            => $companyId,
            'ewt_code'       => $ewtCode,
            'input_tax_code' => $inputTaxCode,
        ]);
    }

    private function writePdf(array $report, string $sdate, string $edate): array
    {
        $downloadName = $this->buildFriendlyDownloadName('pdf');
        $target       = $this->targetLocal('general-ledger-tax-report', 'pdf', $downloadName);

        $pdf = new GLPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->setCompanyHeader((int)$this->companyId);

        $pdf->SetHeaderData('', 0, '', '');
        $pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN]);
        $pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $pdf->SetMargins(7, 35, 7);
        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $pdf->SetFont('helvetica', '', 7.5);

        $dateRange = Carbon::parse($sdate)->format('Y-m-d') . ' -- ' . Carbon::parse($edate)->format('Y-m-d');

        $esc = function (?string $s): string {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $money = function ($n): string {
            $n = (float) $n;
            return abs($n) < 0.00001 ? '' : number_format($n, 2);
        };

        // Rebalanced widths so text columns and numeric columns align more cleanly
        // Total = 100%
        // Rebalanced widths to give more room to Input Tax and EWT
        $W = [
            'date'    => '9%',
            'ref'     => '9%',
            'party'   => '14%',
            'addr'    => '15%',
            'gap'     => '2%',
            'comm'    => '19%',
            'deb'     => '9%',
            'cred'    => '7%',
            'input'   => '8%',
            'ewt'     => '8%',
        ];

        foreach ($report as $acctCode => $rows) {
            if (empty($rows)) continue;

            $acctDesc = (string)($rows[0]->acct_desc ?? '');

            $pdf->AddPage('L', 'A4');

            $hdr = "
<table border=\"0\" cellspacing=\"0\" cellpadding=\"0\" width=\"100%\">
  <tr>
    <td>
      <font size=\"15\"><b>INPUT TAXES AND EWT</b></font><br/>
      <font size=\"10\"><b>For the period covering: {$esc(Carbon::parse($sdate)->format('m/d/Y'))} --- {$esc(Carbon::parse($edate)->format('m/d/Y'))}</b></font><br/>
      <font size=\"10\"><b>{$esc((string)$acctCode)} - {$esc($acctDesc)}</b></font>
    </td>
  </tr>
</table>
";
            $pdf->writeHTML($hdr, true, false, false, false, '');
            $pdf->Ln(1);

            $html = "
<table border=\"0\" cellspacing=\"0\" cellpadding=\"2\" width=\"100%\" style=\"table-layout:fixed;\">
<thead>
  <tr><td colspan=\"10\"><hr/></td></tr>
<tr>
  <td width=\"{$W['date']}\" align=\"left\"><b>Tran Date</b></td>
  <td width=\"{$W['ref']}\" align=\"left\"><b>Reference #</b></td>
  <td width=\"{$W['party']}\" align=\"left\"><b>Cust/Vend</b></td>
  <td width=\"{$W['addr']}\" align=\"left\"><b>Address</b></td>
  <td width=\"{$W['gap']}\" align=\"left\"></td>
  <td width=\"{$W['comm']}\" align=\"left\"><b>Posting Comment</b></td>
  <td width=\"{$W['deb']}\" align=\"right\"><b>Debit</b></td>
  <td width=\"{$W['cred']}\" align=\"right\"><b>Credit</b></td>
  <td width=\"{$W['input']}\" align=\"right\"><b>Input Tax</b></td>
  <td width=\"{$W['ewt']}\" align=\"right\"><b>EWT</b></td>
</tr>
  <tr><td colspan=\"10\"><hr/></td></tr>
</thead>
<tbody>
";

            $monthKey = null;
            $monthDebit = 0.0;
            $monthCredit = 0.0;
            $monthInput = 0.0;
            $monthEwt = 0.0;

            $acctDebit = 0.0;
            $acctCredit = 0.0;
            $acctInput = 0.0;
            $acctEwt = 0.0;

            $emitMonthSubtotal = function () {
                // Monthly subtotal removed intentionally
            };

            foreach ($rows as $r) {
                $dt = Carbon::parse($r->post_date);
                $m  = $dt->format('Y-m');

                if ($monthKey !== null && $monthKey !== $m) {
                    $monthDebit = $monthCredit = $monthInput = $monthEwt = 0.0;
                }
                $monthKey = $m;

                $prefixMap = [
                    'D' => 'CV-',
                    'G' => 'JE-',
                ];

                $refRaw = (string)($r->reference_no ?? '');
                $ref = $refRaw;
                if ($refRaw !== '' && isset($prefixMap[$r->category])) {
                    $ref = $prefixMap[$r->category] . $refRaw;
                }

                $debit    = (float)($r->debit ?? 0);
                $credit   = (float)($r->credit ?? 0);
                $inputTax = (float)($r->input_tax ?? 0);
                $ewt      = (float)($r->ewt ?? 0);

                $html .= "
<tr>
  <td width=\"{$W['date']}\" align=\"left\" valign=\"top\">".$esc($dt->format('m/d/Y'))."</td>
  <td width=\"{$W['ref']}\" align=\"left\" valign=\"top\">".$esc($ref)."</td>
  <td width=\"{$W['party']}\" align=\"left\" valign=\"top\">".$esc((string)($r->party ?? ''))."</td>
  <td width=\"{$W['addr']}\" align=\"left\" valign=\"top\">".$esc((string)($r->address ?? ''))."</td>
  <td width=\"{$W['gap']}\" align=\"left\" valign=\"top\"></td>
  <td width=\"{$W['comm']}\" align=\"left\" valign=\"top\">".$esc((string)($r->explanation ?? ''))."</td>
  <td width=\"{$W['deb']}\" align=\"right\" valign=\"top\">".$money($debit)."</td>
  <td width=\"{$W['cred']}\" align=\"right\" valign=\"top\">".$money($credit)."</td>
  <td width=\"{$W['input']}\" align=\"right\" valign=\"top\">".$money($inputTax)."</td>
  <td width=\"{$W['ewt']}\" align=\"right\" valign=\"top\">".$money($ewt)."</td>
</tr>
";

                $monthDebit += $debit;
                $monthCredit += $credit;
                $monthInput += $inputTax;
                $monthEwt += $ewt;

                $acctDebit += $debit;
                $acctCredit += $credit;
                $acctInput += $inputTax;
                $acctEwt += $ewt;
            }

            if ($monthKey !== null) {
                // Monthly subtotal removed intentionally
            }

            $html .= "
<tr>
  <td colspan=\"6\"></td>
  <td align=\"right\">____________</td>
  <td align=\"right\">____________</td>
  <td align=\"right\">____________</td>
  <td align=\"right\">____________</td>
</tr>
<tr>
  <td colspan=\"6\"></td>
  <td align=\"right\"><b>".$money($acctDebit)."</b></td>
  <td align=\"right\"><b>".$money($acctCredit)."</b></td>
  <td align=\"right\"><b>".$money($acctInput)."</b></td>
  <td align=\"right\"><b>".$money($acctEwt)."</b></td>
</tr>
</tbody>
</table>
";

            $pdf->writeHTML($html, true, false, false, false, '');
        }

        $pdf->Output($target['abs'], 'F');
        return $target;
    }

    private function writeXls(array $report, string $sdate, string $edate): array
    {
        $ext          = ($this->format === 'xls') ? 'xls' : 'xlsx';
        $downloadName = $this->buildFriendlyDownloadName($ext);
        $target       = $this->targetLocal('general-ledger-tax-report', $ext, $downloadName);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $row = 1;

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setWidth(20);
        }
        $sheet->getStyle('F:I')->getNumberFormat()->setFormatCode('#,##0.00');

        $bold = function($coord) use ($sheet) {
            $sheet->getStyle($coord)->getFont()->setBold(true);
        };

        $cid = (int)($this->companyId ?? 0);

        $companyHdr = [
            'name'  => 'SUCDEN PHILIPPINES, INC.',
            'tin'   => 'TIN-000-105-267-000',
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

        $sheet->setCellValue("A{$row}", $companyHdr['name']);
        $sheet->mergeCells("A{$row}:I{$row}");
        $bold("A{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", 'INPUT TAXES AND EWT');
        $sheet->mergeCells("A{$row}:I{$row}");
        $bold("A{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", 'For the period covering: '.Carbon::parse($sdate)->format('m/d/Y').' --- '.Carbon::parse($edate)->format('m/d/Y'));
        $sheet->mergeCells("A{$row}:I{$row}");
        $bold("A{$row}");
        $row += 2;

        foreach ($report as $acctCode => $rows) {
            if (empty($rows)) continue;

            $acctDesc = (string)($rows[0]->acct_desc ?? '');

            $sheet->setCellValue("A{$row}", "{$acctCode} - {$acctDesc}");
            $sheet->mergeCells("A{$row}:I{$row}");
            $bold("A{$row}");
            $row += 2;

            $headers = ['Tran Date','Reference #','Cust/Vend','Address','Posting Comment','Debit','Credit','Input Tax','EWT'];
            $col = 'A';
            foreach ($headers as $h) {
                $sheet->setCellValue("{$col}{$row}", $h);
                $bold("{$col}{$row}");
                $col++;
            }
            $row++;

            $monthKey = null;
            $monthDebit = 0.0;
            $monthCredit = 0.0;
            $monthInput = 0.0;
            $monthEwt = 0.0;

            $acctDebit = 0.0;
            $acctCredit = 0.0;
            $acctInput = 0.0;
            $acctEwt = 0.0;

            foreach ($rows as $r) {
                $dt = Carbon::parse($r->post_date);
                $m  = $dt->format('Y-m');

                if ($monthKey !== null && $monthKey !== $m) {
                    $monthDebit = $monthCredit = $monthInput = $monthEwt = 0.0;
                }
                $monthKey = $m;

                $prefixMap = [
                    'D' => 'CV-',
                    'G' => 'JE-',
                ];

                $refRaw = (string)($r->reference_no ?? '');
                $ref = $refRaw;
                if ($refRaw !== '' && isset($prefixMap[$r->category])) {
                    $ref = $prefixMap[$r->category] . $refRaw;
                }

                $debit    = (float)($r->debit ?? 0);
                $credit   = (float)($r->credit ?? 0);
                $inputTax = (float)($r->input_tax ?? 0);
                $ewt      = (float)($r->ewt ?? 0);

                $sheet->setCellValue("A{$row}", $dt->format('m/d/Y'));
                $sheet->setCellValue("B{$row}", $ref);
                $sheet->setCellValue("C{$row}", (string)($r->party ?? ''));
                $sheet->setCellValue("D{$row}", (string)($r->address ?? ''));
                $sheet->setCellValue("E{$row}", (string)($r->explanation ?? ''));
                if ($debit != 0.0)    $sheet->setCellValue("F{$row}", $debit);
                if ($credit != 0.0)   $sheet->setCellValue("G{$row}", $credit);
                if ($inputTax != 0.0) $sheet->setCellValue("H{$row}", $inputTax);
                if ($ewt != 0.0)      $sheet->setCellValue("I{$row}", $ewt);

                $monthDebit += $debit;
                $monthCredit += $credit;
                $monthInput += $inputTax;
                $monthEwt += $ewt;

                $acctDebit += $debit;
                $acctCredit += $credit;
                $acctInput += $inputTax;
                $acctEwt += $ewt;

                $row++;
            }

            if ($monthKey !== null) {
                // Monthly subtotal removed intentionally
            }

            $sheet->setCellValue("F{$row}", $acctDebit);
            $sheet->setCellValue("G{$row}", $acctCredit);
            $sheet->setCellValue("H{$row}", $acctInput);
            $sheet->setCellValue("I{$row}", $acctEwt);
            $bold("F{$row}:I{$row}");

            $row += 4;
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

    private function buildFriendlyDownloadName(string $ext): string
    {
        $acc = "{$this->startAccount}-{$this->endAccount}";
        $s   = Carbon::parse($this->startDate)->format('Y-m-d');
        $e   = Carbon::parse($this->endDate)->format('Y-m-d');
        return "TaxListReport_{$acc}_{$s}_to_{$e}.{$ext}";
    }

    private function targetLocal(string $base, string $ext, string $downloadName): array
    {
        $disk = Storage::disk('local');
        $dir  = 'reports';
        $disk->makeDirectory($dir);

        $internal = sprintf('%s_%s_%s.%s', $base, now()->format('YmdHis'), Str::uuid(), $ext);
        $rel = "{$dir}/{$internal}";
        $abs = storage_path("app/{$rel}");

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
        return "gltax:{$ticket}";
    }
}