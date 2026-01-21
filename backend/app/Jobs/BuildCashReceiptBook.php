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
use Carbon\Carbon;

class BuildCashReceiptBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min

    public function __construct(
        public string $ticket,
        public string $startDate,
        public string $endDate,
        public string $format,    // 'pdf' | 'xls'
        public int $companyId,    // required
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
            'status'     => 'running',
            'progress'   => 1,
            'format'     => $this->format,
            'file'       => null,
            'error'      => null,
            'range'      => [$this->startDate, $this->endDate],
            'user_id'    => $this->userId,
            'company_id' => $this->companyId,
        ]);

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
            $total = DB::table('cash_receipts as r')
                ->where('r.company_id', $cid)
                ->whereBetween('r.receipt_date', [$this->startDate, $this->endDate])
                ->count();

            $step = function (int $done) use ($total) {
                $pct = $total
                    ? min(99, 1 + (int) floor(($done / max(1, $total)) * 98))
                    : 50;
                $this->patchState(['progress' => $pct]);
            };

            $dir  = 'reports';
            $disk = Storage::disk('local');
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $path  = ($this->format === 'pdf')
                ? "{$dir}/cash_receipt_book_{$stamp}.pdf"
                : "{$dir}/cash_receipt_book_{$stamp}.xls";

            if ($this->format === 'pdf') $this->buildPdf($path, $step);
            else                         $this->buildExcel($path, $step);

            $this->pruneOldReports($path, sameFormatOnly: true, keep: 1);

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
        $disk = Storage::disk('local');
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

    /**
     * Company-aware header block.
     * company_id=2 => AMEROP
     * default      => SUCDEN
     */
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

        return [
            'name'  => 'SUCDEN PHILIPPINES, INC.',
            'tin'   => 'TIN- 000-105-267-000',
            'addr1' => 'Unit 2202 The Podium West Tower, 12 ADB Ave',
            'addr2' => 'Ortigas Center Mandaluyong City',
        ];
    }

    /** ---------- Writers ---------- */

    private function buildPdf(string $file, callable $progress): void
    {
        $cid = (int) $this->companyId;
        $co  = $this->companyHeader();

        // ✅ show range as mm/dd/yyyy in header
        $from = Carbon::parse($this->startDate)->format('m/d/Y');
        $to   = Carbon::parse($this->endDate)->format('m/d/Y');

        $pdf = new class($co, $from, $to) extends \TCPDF {
            public function __construct(
                public array $co,
                public string $from,
                public string $to
            ) {
                parent::__construct('P', 'mm', 'LETTER', true, 'UTF-8', false);
            }

public function Header()
{
    $name  = (string)($this->co['name']  ?? '');
    $tin   = (string)($this->co['tin']   ?? '');
    $addr1 = (string)($this->co['addr1'] ?? '');
    $addr2 = (string)($this->co['addr2'] ?? '');

    // Top-right company block
    $this->SetY(8);
    $this->SetFont('helvetica', 'B', 11);
    $this->Cell(0, 5, $name, 0, 1, 'R');

    $this->SetFont('helvetica', '', 8);
    $this->Cell(0, 4, $tin, 0, 1, 'R');
    $this->Cell(0, 4, $addr1, 0, 1, 'R');
    $this->Cell(0, 4, $addr2, 0, 1, 'R');

    // Divider line
    $y = $this->GetY() + 3;
    $this->Line(10, $y, 206, $y);

    // Title
    $this->SetY($y + 6);
    $this->SetFont('helvetica', 'B', 16);
    $this->Cell(0, 7, 'CASH RECEIPTS JOURNAL', 0, 1, 'L');

    // ✅ Period + Debit/Credit ON THE SAME LINE
    $this->SetFont('helvetica', 'B', 10);

    // Save Y where the "period" line begins
    $periodY = $this->GetY();

    // Left side: period text
    $this->SetXY(10, $periodY);
    $this->Cell(140, 6, "For the period covering {$this->from} — {$this->to}", 0, 0, 'L');

    // Right side: Debit / Credit labels aligned to the far right
    $this->SetXY(150, $periodY);
    $this->Cell(28, 6, 'Debit', 0, 0, 'R');
    $this->Cell(28, 6, 'Credit', 0, 0, 'R');

    // Move cursor to next line AFTER that combined row
    $this->Ln(8);

    // Body font
    $this->SetFont('helvetica', '', 8);
}

            public function Footer()
            {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);

                $currentDate = date('M d, Y');
                $currentTime = date('h:i:sa');
                $pageText    = $this->getAliasNumPage() . '/' . $this->getAliasNbPages();

                $htmlFooter = ''
                    . '<table border="0" width="100%" cellpadding="0" cellspacing="0">'
                    . '  <tr>'
                    . '    <td width="40%" align="left">'
                    . '      <font size="8">Print Date: ' . $currentDate . '<br/>Print Time: ' . $currentTime . '</font>'
                    . '    </td>'
                    . '    <td width="20%" align="center">'
                    . '      <font size="8">' . $pageText . '</font>'
                    . '    </td>'
                    . '    <td width="40%" align="right"></td>'
                    . '  </tr>'
                    . '</table>';

                $this->writeHTML($htmlFooter, true, false, false, false, '');
            }
        };

        // ✅ enable repeating header
        $pdf->setPrintHeader(true);

        // ✅ margins tuned so body starts below header (prevents overlap, keeps header on every page)
        $pdf->SetHeaderMargin(5);
        $pdf->SetMargins(10, 52, 10);
        $pdf->SetAutoPageBreak(true, 16);

        $pdf->setImageScale(1.25);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->AddPage();

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
                    'acct_desc', COALESCE(a.acct_desc,''),
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

                    $itemDebit = 0; $itemCredit = 0;
                    $rowsHtml  = '<table width="100%" cellspacing="0" cellpadding="1">';

                    foreach ((json_decode($row->lines, true) ?: []) as $ln) {
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
                            $pdf->AddPage(); // ✅ header repeats automatically
                            $lineCount = 0;
                            $rowsHtml  = '<table width="100%" cellspacing="0" cellpadding="1">';
                        }
                    }

                    $rowsHtml .= sprintf(
                        '<tr><td></td><td colspan="4"></td>
                           <td align="right"><b>%s</b></td>
                           <td align="right"><b>%s</b></td>
                         </tr>
                         <tr><td colspan="7"><hr/></td></tr>
                         <tr><td colspan="7"><br/></td></tr>',
                        number_format($itemDebit, 2),
                        number_format($itemCredit, 2)
                    );

                    $rowsHtml .= '</table>';
                    $pdf->writeHTML($rowsHtml, true, false, false, false, '');

                    $done++; $progress($done);
                }
            });

        Storage::disk('local')->put($file, $pdf->Output('cash-receipts.pdf', 'S'));
    }

    private function buildExcel(string $file, callable $progress): void
    {
        $cid = (int) $this->companyId;
        $co  = $this->companyHeader();

        // ✅ show range as mm/dd/yyyy in the header lines
        $from = Carbon::parse($this->startDate)->format('m/d/Y');
        $to   = Carbon::parse($this->endDate)->format('m/d/Y');

        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Cash Receipts Book');

        $r = 1;
        $ws->setCellValue("A{$r}", 'CASH RECEIPTS BOOK'); $r++;
        $ws->setCellValue("A{$r}", $co['name']); $r++;
        $ws->setCellValue("A{$r}", $co['tin']); $r++;
        $ws->setCellValue("A{$r}", $co['addr1']); $r++;
        $ws->setCellValue("A{$r}", $co['addr2']); $r += 2;

        $ws->setCellValue("A{$r}", 'CASH RECEIPTS JOURNAL'); $r++;
        $ws->setCellValue("A{$r}", "For the period covering: {$from} to {$to}"); $r += 2;
        $ws->fromArray(['', '', '', '', '', 'DEBIT', 'CREDIT'], null, "A{$r}"); $r++;

        foreach (range('A','G') as $col) $ws->getColumnDimension($col)->setWidth(18);

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
                    'acct_desc', COALESCE(a.acct_desc,''),
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

                    $ws->setCellValue("A{$r}", $crbId); $r++;
                    $ws->setCellValue("A{$r}", $row->receipt_date); $r++;

                    $ws->setCellValue("A{$r}", "RV - {$row->cr_no}");
                    $ws->setCellValue("B{$r}", "OR#: {$row->collection_receipt}"); $r++;

                    $ws->setCellValue("A{$r}", $row->bank_name);
                    $ws->setCellValue("B{$r}", $row->details); $r++;

                    $itemDebit=0; $itemCredit=0;
                    foreach (json_decode($row->lines,true) as $ln) {
                        $debit  = (float)($ln['debit'] ?? 0);
                        $credit = (float)($ln['credit'] ?? 0);

                        $ws->setCellValue("A{$r}", $ln['acct_code'] ?? '');
                        $ws->setCellValue("B{$r}", $ln['acct_desc'] ?? '');
                        $ws->setCellValue("F{$r}", $debit);
                        $ws->setCellValue("G{$r}", $credit);
                        $ws->getStyle("F{$r}:G{$r}")->getNumberFormat()->setFormatCode('#,##0.00');

                        $itemDebit  += $debit;
                        $itemCredit += $credit;
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
        $writer->save($stream);
        rewind($stream);
        Storage::disk('local')->put($file, stream_get_contents($stream));
        fclose($stream);

        $wb->disconnectWorksheets();
        unset($writer);
    }
}
