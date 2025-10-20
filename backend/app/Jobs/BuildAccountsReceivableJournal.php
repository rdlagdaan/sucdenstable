<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BuildAccountsReceivableJournal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $ticket,
        public string $startDate,
        public string $endDate,
        public string $format,       // pdf|excel
        public ?int $companyId,
        public ?int $userId,
        public ?string $query = null // optional filter
    ) {}

    // ---- helpers ------------------------------------------------------------

    private function patchState(array $patch): void
    {
        $key = "arj:{$this->ticket}";
        $current = Cache::get($key);
        if (!is_array($current)) $current = [];
        Cache::put($key, array_merge($current, $patch), now()->addHours(2));
    }

    /** Delete older ARJ files, keeping the newest N of the same format. */
    private function pruneOldReports(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local'); // root is storage/app/private

        $files = collect($disk->files('reports'))
            ->filter(fn($p) => str_starts_with(basename($p), 'accounts_receivable_journal_'))
            ->when($sameFormatOnly, function ($c) use ($keepFile) {
                $ext = pathinfo($keepFile, PATHINFO_EXTENSION);
                return $c->filter(fn($p) => pathinfo($p, PATHINFO_EXTENSION) === $ext);
            })
            ->sortByDesc(fn($p) => $disk->lastModified($p))
            ->slice($keep);

        $files->each(fn($p) => $disk->delete($p));
    }

    // ---- job entry ----------------------------------------------------------

    public function handle(): void
    {
        $this->patchState([
            'status'     => 'running',
            'progress'   => 1,
            'format'     => $this->format,
            'file'       => null,
            'error'      => null,
            'range'      => [$this->startDate, $this->endDate],
            'query'      => $this->query,
            'user_id'    => $this->userId,
            'company_id' => $this->companyId,
        ]);

        try {
            // Count rows for progress
            $count = DB::table('cash_sales as r')
                ->when($this->companyId, fn($q) => $q->where('r.company_id', $this->companyId))
                ->when($this->query, function ($q) {
                    $s = "%{$this->query}%";
                    $q->where(function ($w) use ($s) {
                        $w->where('r.cs_no',        'ilike', $s)
                          ->orWhere('r.booking_no',  'ilike', $s)
                          ->orWhere('r.explanation', 'ilike', $s)
                          ->orWhere('r.cust_id',     'ilike', $s)
                          ->orWhere('r.bank_id',     'ilike', $s)
                          ->orWhere('r.si_no',       'ilike', $s);
                    });
                })
                ->whereBetween('r.sales_date', [$this->startDate, $this->endDate])
                ->count();

            $progress = function (int $done) use ($count) {
                $pct = $count ? min(99, (int) floor(($done / max(1, $count)) * 98) + 1) : 50;
                $this->patchState(['progress' => $pct]);
            };

            $dir = 'reports';
            if (!Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->makeDirectory($dir);
            }

            $stamp = now()->format('Ymd_His');
            $path  = $this->format === 'pdf'
                   ? "$dir/accounts_receivable_journal_{$stamp}.pdf"
                   : "$dir/accounts_receivable_journal_{$stamp}.xls";

            if ($this->format === 'pdf') {
                $this->buildPdf($path, $progress);
            } else {
                $this->buildExcel($path, $progress);
            }

            $this->pruneOldReports($path, sameFormatOnly: true, keep: 1);

            $this->patchState([
                'status'   => 'done',
                'progress' => 100,
                'file'     => $path,
            ]);

        } catch (Throwable $e) {
            $this->patchState([
                'status' => 'error',
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ---- builders -----------------------------------------------------------

    /** PDF via TCPDF; chunked DB reads; writes to disk. */
    private function buildPdf(string $file, callable $progress): void
    {
        $pdf = new \TCPDF('P','mm','LETTER', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->setImageScale(1.25);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->AddPage();

        $headerHtml = <<<HTML
          <table width="100%" cellspacing="0" cellpadding="0">
            <tr><td align="right"><b>SUCDEN PHILIPPINES, INC.</b><br/>
              <span style="font-size:9px">TIN- 000-105-267-000</span><br/>
              <span style="font-size:9px">Unit 2202 The Podium West Tower, 12 ADB Ave</span><br/>
              <span style="font-size:9px">Ortigas Center Mandaluyong City</span></td></tr>
            <tr><td><hr/></td></tr>
          </table>
          <h2>SALES JOURNAL</h2>
          <div><b>For the period covering {$this->startDate} -- {$this->endDate}</b></div>
          <br/>
          <table width="100%"><tr><td colspan="5"></td><td align="right"><b>Debit</b></td><td align="right"><b>Credit</b></td></tr></table>
        HTML;
        $pdf->writeHTML($headerHtml, true, false, false, false, '');

        $done = 0;
        $lineCount = 0;

        DB::table('cash_sales as r')
            ->selectRaw("
                r.id,
                to_char(r.sales_date,'MM/DD/YYYY') as sales_date,
                r.cs_no,
                r.si_no,
                r.cust_id,
                r.booking_no,
                r.explanation,
                b.acct_desc as bank_name,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', a.acct_desc,
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
            ->leftJoin('account_code as b','b.acct_code','=','r.bank_id')
            ->join('cash_sales_details as d', DB::raw("d.transaction_id"), '=', DB::raw("r.id::text"))
            ->leftJoin('account_code as a','a.acct_code','=','d.acct_code')
            ->when($this->companyId, fn($q)=>$q->where('r.company_id',$this->companyId))
            ->when($this->query, function ($q) {
                $s = "%{$this->query}%";
                $q->where(function ($w) use ($s) {
                    $w->where('r.cs_no',        'ilike', $s)
                      ->orWhere('r.booking_no',  'ilike', $s)
                      ->orWhere('r.explanation', 'ilike', $s)
                      ->orWhere('r.cust_id',     'ilike', $s)
                      ->orWhere('r.bank_id',     'ilike', $s)
                      ->orWhere('r.si_no',       'ilike', $s);
                });
            })
            ->whereBetween('r.sales_date', [$this->startDate, $this->endDate])
            ->groupBy('r.id','r.sales_date','r.cs_no','r.si_no','r.cust_id','r.booking_no','r.explanation','b.acct_desc')
            ->orderBy('r.id')
            ->chunk(200, function($chunk) use (&$done, $progress, $pdf, &$lineCount) {
                foreach ($chunk as $row) {
                    $arId = 'ARCR-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                    $block = <<<HTML
                      <table width="100%" cellspacing="0" cellpadding="1">
                        <tr><td>{$row->sales_date}</td><td colspan="6">{$arId}</td></tr>
                        <tr>
                          <td colspan="6"><b>SV - {$row->cs_no} - {$row->cust_id} - {$row->explanation}</b>
                          &nbsp;&nbsp;<span style="font-size:11px"><u>{$row->si_no}</u></span></td>
                          <td align="right">{$row->bank_name}</td>
                        </tr>
                        <tr><td colspan="7">&nbsp;&nbsp;&nbsp;OR#: {$row->booking_no}</td></tr>
                      </table>
                    HTML;
                    $pdf->writeHTML($block, true, false, false, false, '');

                    $itemDebit=0; $itemCredit=0;
                    $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';

                    foreach (json_decode($row->lines, true) as $ln) {
                        $itemDebit  += (float)($ln['debit'] ?? 0);
                        $itemCredit += (float)($ln['credit'] ?? 0);

                        $rowsHtml .= sprintf(
                            '<tr>
                               <td>&nbsp;&nbsp;&nbsp;%s</td>
                               <td colspan="4">%s</td>
                               <td align="right">%s</td>
                               <td align="right">%s</td>
                             </tr>',
                            e($ln['acct_code'] ?? ''),
                            e($ln['acct_desc'] ?? ''),
                            number_format((float)($ln['debit'] ?? 0), 2),
                            number_format((float)($ln['credit'] ?? 0), 2)
                        );

                        $lineCount++;
                        if ($lineCount >= 25) {
                            $rowsHtml .= '</table>';
                            $pdf->writeHTML($rowsHtml, true, false, false, false, '');
                            $pdf->AddPage();
                            $lineCount = 0;
                            $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';
                        }
                    }

                    $rowsHtml .= sprintf(
                        '<tr><td></td><td colspan="4"></td>
                           <td align="right"><b>%s</b></td>
                           <td align="right"><b>%s</b></td>
                         </tr>
                         <tr><td colspan="7"><hr/></td></tr>
                         <tr><td colspan="7"><br/></td></tr>',
                        number_format($itemDebit,2),
                        number_format($itemCredit,2)
                    );
                    $rowsHtml .= '</table>';
                    $pdf->writeHTML($rowsHtml, true, false, false, false, '');

                    $done++; $progress($done);
                }
            });

        // simple footer
        $pdf->SetY(-18);
        $pdf->writeHTML(
          '<table width="100%"><tr>
             <td>Print Date: '.now()->format('M d, Y').'</td>
             <td>Print Time: '.now()->format('h:i:s a').'</td>
             <td align="right">'.$pdf->getAliasNumPage().'/'.$pdf->getAliasNbPages().'</td>
           </tr></table>',
           true,false,false,false,''
        );

        Storage::disk('local')->put($file, $pdf->Output('sales-journal.pdf', 'S'));
    }

    /** Excel via PhpSpreadsheet; chunked DB reads; writes to disk. */
    private function buildExcel(string $file, callable $progress): void
    {
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Sales Journal');

        $r = 1;
        $ws->setCellValue("A{$r}", 'SALES JOURNAL'); $r++;
        $ws->setCellValue("A{$r}", 'SUCDEN PHILIPPINES, INC.'); $r++;
        $ws->setCellValue("A{$r}", 'TIN- 000-105-267-000'); $r++;
        $ws->setCellValue("A{$r}", 'Unit 2202 The Podium West Tower, 12 ADB Ave'); $r++;
        $ws->setCellValue("A{$r}", 'Ortigas Center Mandaluyong City'); $r+=2;
        $ws->setCellValue("A{$r}", "For the period covering: {$this->startDate} of {$this->endDate}"); $r+=2;
        $ws->fromArray(['', '', '', '', '', 'DEBIT', 'CREDIT'], null, "A{$r}"); $r++;

        $done = 0;
        DB::table('cash_sales as r')
            ->selectRaw("
                r.id,
                to_char(r.sales_date,'MM/DD/YYYY') as sales_date,
                r.cs_no,
                r.si_no,
                r.cust_id,
                r.booking_no,
                r.explanation,
                b.acct_desc as bank_name,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', a.acct_desc,
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
            ->leftJoin('account_code as b','b.acct_code','=','r.bank_id')
            ->join('cash_sales_details as d', DB::raw("d.transaction_id"), '=', DB::raw("r.id::text"))
            ->leftJoin('account_code as a','a.acct_code','=','d.acct_code')
            ->when($this->companyId, fn($q)=>$q->where('r.company_id',$this->companyId))
            ->when($this->query, function ($q) {
                $s = "%{$this->query}%";
                $q->where(function ($w) use ($s) {
                    $w->where('r.cs_no',        'ilike', $s)
                      ->orWhere('r.booking_no',  'ilike', $s)
                      ->orWhere('r.explanation', 'ilike', $s)
                      ->orWhere('r.cust_id',     'ilike', $s)
                      ->orWhere('r.bank_id',     'ilike', $s)
                      ->orWhere('r.si_no',       'ilike', $s);
                });
            })
            ->whereBetween('r.sales_date', [$this->startDate, $this->endDate])
            ->groupBy('r.id','r.sales_date','r.cs_no','r.si_no','r.cust_id','r.booking_no','r.explanation','b.acct_desc')
            ->orderBy('r.id')
            ->chunk(200, function($chunk) use (&$r, $ws, &$done, $progress) {
                foreach ($chunk as $row) {
                    $arId = 'ARCR-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                    $ws->setCellValue("A{$r}", $row->sales_date);
                    $ws->setCellValue("B{$r}", $arId); $r++;

                    $ws->setCellValue("A{$r}", "SV - {$row->cs_no} - {$row->cust_id} - {$row->explanation}");
                    $ws->setCellValue("B{$r}", "S.I.#: {$row->si_no}"); $r++;

                    $ws->setCellValue("A{$r}", "OR#: {$row->booking_no}");
                    $ws->setCellValue("B{$r}", $row->bank_name); $r++;

                    $itemDebit=0; $itemCredit=0;
                    foreach (json_decode($row->lines,true) as $ln) {
                        $ws->setCellValue("A{$r}", $ln['acct_code'] ?? '');
                        $ws->setCellValue("B{$r}", $ln['acct_desc'] ?? '');
                        $ws->setCellValue("F{$r}", (float)($ln['debit'] ?? 0));
                        $ws->setCellValue("G{$r}", (float)($ln['credit'] ?? 0));
                        $ws->getStyle("F{$r}:G{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                        $itemDebit  += (float)($ln['debit'] ?? 0);
                        $itemCredit += (float)($ln['credit'] ?? 0);
                        $r++;
                    }
                    $ws->setCellValue("E{$r}", 'TOTAL');
                    $ws->setCellValue("F{$r}", $itemDebit);
                    $ws->setCellValue("G{$r}", $itemCredit);
                    $ws->getStyle("F{$r}:G{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $r += 2;

                    $done++; $progress($done);
                }
            });

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($wb);
        $stream = fopen('php://temp', 'r+');
        $writer->save($stream); rewind($stream);
        Storage::disk('local')->put($file, stream_get_contents($stream));
        fclose($stream);
    }
}
