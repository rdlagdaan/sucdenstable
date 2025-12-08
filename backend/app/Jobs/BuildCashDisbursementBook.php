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
use Illuminate\Support\Str;
use Throwable;

class BuildCashDisbursementBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min

    public function __construct(
        public string $ticket,
        public string $startDate,
        public string $endDate,
        public string $format,    // 'pdf' | 'xls'
        public ?int $companyId,
        public ?int $userId
    ) {}

    private function key(): string { return "cdb:{$this->ticket}"; }

    private function patchState(array $patch): void
    {
        $cur = Cache::get($this->key(), []);
        Cache::put($this->key(), array_merge($cur, $patch), now()->addHours(2));
    }

    public function handle(): void
    {
        $this->patchState([
            'status'   => 'running',
            'progress' => 1,
            'format'   => $this->format,
            'file'     => null,
            'error'    => null,
            'range'    => [$this->startDate, $this->endDate],
            'user_id'  => $this->userId,
            'company_id' => $this->companyId,
        ]);

        try {
            // Pre-count for progress (same filters as data query)
            $total = DB::table('cash_disbursement as r')
                ->when($this->companyId, fn($q) => $q->where('r.company_id', $this->companyId))
                ->whereBetween('r.disburse_date', [$this->startDate, $this->endDate])
                ->count();

            $step = function (int $done) use ($total) {
                $pct = $total ? min(99, 1 + (int)floor(($done / max(1, $total)) * 98)) : 50;
                $this->patchState(['progress' => $pct]);
            };

            $dir = 'reports';
            $disk = Storage::disk('local');
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $path  = $this->format === 'pdf'
                ? "$dir/cash_disbursement_book_{$stamp}.pdf"
                : "$dir/cash_disbursement_book_{$stamp}.xls";

            if ($this->format === 'pdf') {
                $this->buildPdf($path, $step);
            } else {
                $this->buildExcel($path, $step);
            }

            $this->pruneOldReports($path, sameFormatOnly: true, keep: 1);

            $this->patchState([
                'status'   => 'done',
                'progress' => 100,
                'file'     => $path,
            ]);
        } catch (Throwable $e) {
            $this->patchState(['status' => 'error', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function pruneOldReports(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local');
        $files = collect($disk->files('reports'))
            ->filter(fn($p) => str_starts_with(basename($p), 'cash_disbursement_book_'))
            ->when($sameFormatOnly, function ($c) use ($keepFile) {
                $ext = pathinfo($keepFile, PATHINFO_EXTENSION);
                return $c->filter(fn($p) => pathinfo($p, PATHINFO_EXTENSION) === $ext);
            })
            ->sortByDesc(fn($p) => $disk->lastModified($p))
            ->slice($keep);

        $files->each(fn($p) => $disk->delete($p));
    }

    /** ---------- Writers ---------- */

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

        $hdr = <<<HTML
          <table width="100%" cellspacing="0" cellpadding="0">
            <tr><td align="right"><b>SUCDEN PHILIPPINES, INC.</b><br/>
              <span style="font-size:9px">TIN- 000-105-267-000</span><br/>
              <span style="font-size:9px">Unit 2202 The Podium West Tower, 12 ADB Ave</span><br/>
              <span style="font-size:9px">Ortigas Center Mandaluyong City</span></td></tr>
            <tr><td><hr/></td></tr>
          </table>
          <h2>CASH DISBURSEMENTS JOURNAL</h2>
          <div><b>For the period covering {$this->startDate} — {$this->endDate}</b></div>
          <br/>
          <table width="100%"><tr><td colspan="5"></td><td align="right"><b>Debit</b></td><td align="right"><b>Credit</b></td></tr></table>
        HTML;
        $pdf->writeHTML($hdr, true, false, false, false, '');

        $done = 0;
        $lineCount = 0;

        DB::table('cash_disbursement as r')
            ->selectRaw("
                r.id,
                to_char(r.disburse_date,'MM/DD/YYYY') as disburse_date,
                r.cd_no,
                r.check_ref_no,
                r.explanation,
                COALESCE(b.acct_desc, r.bank_id) as bank_name,
                v.vend_name as vend_name,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', a.acct_desc,
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
            ->leftJoin('account_code as b','b.acct_code','=','r.bank_id')
            // CAST in case d.transaction_id is VARCHAR
            ->join('cash_disbursement_details as d', function ($j) {
                $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
            })
            ->leftJoin('account_code as a','a.acct_code','=','d.acct_code')
            ->leftJoin('vendor_list as v','v.vend_code','=','r.vend_id')
            ->when($this->companyId, fn($q)=>$q->where('r.company_id',$this->companyId))
            ->whereBetween('r.disburse_date', [$this->startDate, $this->endDate])
            ->groupBy('r.id','r.disburse_date','r.cd_no','r.check_ref_no','r.explanation','b.acct_desc','v.vend_name')
            ->orderBy('r.id')
            ->chunk(200, function($chunk) use (&$done, $progress, $pdf, &$lineCount) {
                foreach ($chunk as $row) {
                    $cdbId = 'APMC-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                    $block = <<<HTML
                      <table width="100%" cellspacing="0" cellpadding="1">
                        <tr><td>{$row->disburse_date}</td><td colspan="6">{$cdbId}</td></tr>
                        <tr><td><b>CV# {$row->cd_no}</b></td><td colspan="6">Check#: {$row->check_ref_no}&nbsp;&nbsp;&nbsp;{$row->bank_name}</td></tr>
                        <tr><td colspan="7">{$row->vend_name}&nbsp;&nbsp;&nbsp;{$row->explanation}</td></tr>
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

        Storage::disk('local')->put($file, $pdf->Output('cash-disbursements.pdf', 'S'));
    }

    private function buildExcel(string $file, callable $progress): void
    {
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Cash Disbursement Book');

        $r = 1;
        $ws->setCellValue("A{$r}", 'CASH DISBURSEMENT BOOK'); $r++;
        $ws->setCellValue("A{$r}", 'SUCDEN PHILIPPINES, INC.'); $r++;
        $ws->setCellValue("A{$r}", 'TIN- 000-105-267-000'); $r++;
        $ws->setCellValue("A{$r}", 'Unit 2202 The Podium West Tower, 12 ADB Ave'); $r++;
        $ws->setCellValue("A{$r}", 'Ortigas Center Mandaluyong City'); $r+=2;
        $ws->setCellValue("A{$r}", 'CASH DISBURSEMENTS JOURNAL'); $r++;
        $ws->setCellValue("A{$r}", "For the period covering: {$this->startDate} to {$this->endDate}"); $r+=2;
        $ws->fromArray(['', '', '', '', '', 'DEBIT', 'CREDIT'], null, "A{$r}"); $r++;
        foreach (range('A','G') as $col) $ws->getColumnDimension($col)->setWidth(15);

        $done = 0;
        DB::table('cash_disbursement as r')
            ->selectRaw("
                r.id,
                to_char(r.disburse_date,'MM/DD/YYYY') as disburse_date,
                r.cd_no,
                r.check_ref_no,
                r.explanation,
                COALESCE(b.acct_desc, r.bank_id) as bank_name,
                v.vend_name as vend_name,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', a.acct_desc,
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
            ->leftJoin('account_code as b','b.acct_code','=','r.bank_id')
            ->join('cash_disbursement_details as d', function ($j) {
                $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
            })
            ->leftJoin('account_code as a','a.acct_code','=','d.acct_code')
            ->leftJoin('vendor_list as v','v.vend_code','=','r.vend_id')
            ->when($this->companyId, fn($q)=>$q->where('r.company_id',$this->companyId))
            ->whereBetween('r.disburse_date', [$this->startDate, $this->endDate])
            ->groupBy('r.id','r.disburse_date','r.cd_no','r.check_ref_no','r.explanation','b.acct_desc','v.vend_name')
            ->orderBy('r.id')
            ->chunk(200, function($chunk) use (&$r, $ws, &$done, $progress) {
                foreach ($chunk as $row) {
                    $cdbId = 'APMC-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                    $ws->setCellValue("A{$r}", $row->disburse_date);
                    $ws->setCellValue("B{$r}", $cdbId); $r++;

                    $ws->setCellValue("A{$r}", "CV# {$row->cd_no}");
                    $ws->setCellValue("B{$r}", "Check#: {$row->check_ref_no} --- {$row->bank_name}"); $r++;

                    $ws->setCellValue("A{$r}", $row->vend_name);
                    $ws->setCellValue("B{$r}", $row->explanation); $r++;

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
        $wb->disconnectWorksheets();
        unset($writer);
    }
}
