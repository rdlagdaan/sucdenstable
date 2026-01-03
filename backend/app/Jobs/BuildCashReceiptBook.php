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

class BuildCashReceiptBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min

public function __construct(
    public string $ticket,
    public string $startDate,
    public string $endDate,
    public string $format,    // 'pdf' | 'xls'
    public int $companyId,    // ✅ required
    public ?int $userId,
) {}


    private function key(): string { return "crb:{$this->ticket}"; }

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
    ]);

    // Company scope must exist (Option A)
    $cid = (int) $this->companyId;
    if ($cid <= 0) {
        $this->patchState([
            'status'   => 'error',
            'progress' => 100,
            'error'    => 'Missing company scope (companyId=0).',
        ]);
        return;
    }

    try {
        // Count rows for progress (scoped)
        $total = DB::table('cash_receipts as r')
            ->where('r.company_id', $cid)
            ->whereBetween('r.receipt_date', [$this->startDate, $this->endDate])
            ->count();

        // Progress callback used by buildPdf/buildExcel
        $step = function (int $done) use ($total) {
            // Keep 1..99 while building, set to 100 only when done
            $pct = $total
                ? min(99, 1 + (int) floor(($done / max(1, $total)) * 98))
                : 50;

            $this->patchState(['progress' => $pct]);
        };

        // Ensure reports dir exists
        $dir  = 'reports';
        $disk = Storage::disk('local');
        if (!$disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        // Output file path
        $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
        $path  = ($this->format === 'pdf')
            ? "{$dir}/cash_receipt_book_{$stamp}.pdf"
            : "{$dir}/cash_receipt_book_{$stamp}.xls";

        // Build report
        if ($this->format === 'pdf') {
            $this->buildPdf($path, $step);
        } else {
            $this->buildExcel($path, $step);
        }

        // Keep only newest report for same format
        $this->pruneOldReports($path, sameFormatOnly: true, keep: 1);

        // Done
        $this->patchState([
            'status'   => 'done',
            'progress' => 100,
            'file'     => $path,
            'error'    => null,
        ]);
    } catch (\Throwable $e) {
        $this->patchState([
            'status'   => 'error',
            'progress' => 100,
            'error'    => $e->getMessage(),
        ]);
        throw $e;
    }
}


    private function pruneOldReports(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local'); // storage/app
        $files = collect($disk->files('reports'))
            ->filter(fn($p) => str_starts_with(basename($p), 'cash_receipt_book_'))
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
        $cid = (int) $this->companyId;
        
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
          <h2>CASH RECEIPTS JOURNAL</h2>
          <div><b>For the period covering {$this->startDate} — {$this->endDate}</b></div>
          <br/>
          <table width="100%"><tr><td colspan="5"></td><td align="right"><b>Debit</b></td><td align="right"><b>Credit</b></td></tr></table>
        HTML;
        $pdf->writeHTML($hdr, true, false, false, false, '');

        $done = 0;
        $lineCount = 0;

        DB::table('cash_receipts as r')
            ->selectRaw("
                r.id,
                to_char(r.receipt_date,'MM/DD/YYYY') as receipt_date,
                r.cr_no,
                r.collection_receipt,
                r.details,
                bk.bank_name as bank_name,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', a.acct_desc,
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
->leftJoin('bank as bk', function ($j) use ($cid) {
    $j->on('bk.bank_id', '=', 'r.bank_id')
      ->where('bk.company_id', '=', $cid);
})
            ->join('cash_receipt_details as d', function ($j) {
                // d.transaction_id is VARCHAR in your schema — cast it to BIGINT to match r.id
                $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
            })
->leftJoin('account_code as a', function ($j) use ($cid) {
    $j->on('a.acct_code', '=', 'd.acct_code')
      ->where('a.company_id', '=', $cid);
})
->where('r.company_id', $cid)
            ->whereBetween('r.receipt_date', [$this->startDate, $this->endDate])
            ->groupBy('r.id','r.receipt_date','r.cr_no','r.collection_receipt','r.details','bk.bank_name')
            ->orderBy('r.id')
            ->chunk(200, function($chunk) use (&$done, $progress, $pdf, &$lineCount) {
                foreach ($chunk as $row) {
                    $crbId = 'ARCR-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                    $block = <<<HTML
                      <table width="100%" cellspacing="0" cellpadding="1">
                        <tr><td>{$crbId}</td><td colspan="6"></td></tr>
                        <tr><td>{$row->receipt_date}</td><td colspan="6"></td></tr>
                        <tr><td><b>RV - {$row->cr_no}</b></td><td colspan="6">OR#: {$row->collection_receipt}</td></tr>
                        <tr><td>{$row->bank_name}</td><td colspan="6">&nbsp;&nbsp;&nbsp;{$row->details}</td></tr>
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

        // write to disk
        Storage::disk('local')->put($file, $pdf->Output('cash-receipts.pdf', 'S'));
    }

    private function buildExcel(string $file, callable $progress): void
    {
        $cid = (int) $this->companyId; 
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Cash Receipts Book');

        $r = 1;
        $ws->setCellValue("A{$r}", 'CASH RECEIPTS BOOK'); $r++;
        $ws->setCellValue("A{$r}", 'SUCDEN PHILIPPINES, INC.'); $r++;
        $ws->setCellValue("A{$r}", 'TIN- 000-105-267-000'); $r++;
        $ws->setCellValue("A{$r}", 'Unit 2202 The Podium West Tower, 12 ADB Ave'); $r++;
        $ws->setCellValue("A{$r}", 'Ortigas Center Mandaluyong City'); $r += 2;
        $ws->setCellValue("A{$r}", 'CASH RECEIPTS JOURNAL'); $r++;
        $ws->setCellValue("A{$r}", "For the period covering: {$this->startDate} to {$this->endDate}"); $r += 2;
        $ws->fromArray(['', '', '', '', '', 'DEBIT', 'CREDIT'], null, "A{$r}"); $r++;

        $done = 0;

        DB::table('cash_receipts as r')
            ->selectRaw("
                r.id,
                to_char(r.receipt_date,'MM/DD/YYYY') as receipt_date,
                r.cr_no,
                r.collection_receipt,
                r.details,
                bk.bank_name as bank_name,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', a.acct_desc,
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
->leftJoin('bank as bk', function ($j) use ($cid) {
    $j->on('bk.bank_id', '=', 'r.bank_id')
      ->where('bk.company_id', '=', $cid);
})
            ->join('cash_receipt_details as d', function ($j) {
                $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
            })
->leftJoin('account_code as a', function ($j) use ($cid) {
    $j->on('a.acct_code', '=', 'd.acct_code')
      ->where('a.company_id', '=', $cid);
})
->where('r.company_id', $cid)
            ->whereBetween('r.receipt_date', [$this->startDate, $this->endDate])
            ->groupBy('r.id','r.receipt_date','r.cr_no','r.collection_receipt','r.details','bk.bank_name')
            ->orderBy('r.id')
            ->chunk(200, function($chunk) use (&$r, $ws, &$done, $progress) {
                foreach ($chunk as $row) {
                    $crbId = 'ARCR-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                    $ws->setCellValue("A{$r}", $row->receipt_date);
                    $ws->setCellValue("B{$r}", $crbId); $r++;

                    $ws->setCellValue("A{$r}", "RV - {$row->cr_no}");
                    $ws->setCellValue("B{$r}", "OR#: {$row->collection_receipt}"); $r++;

                    $ws->setCellValue("A{$r}", $row->bank_name);
                    $ws->setCellValue("B{$r}", $row->details); $r++;

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
