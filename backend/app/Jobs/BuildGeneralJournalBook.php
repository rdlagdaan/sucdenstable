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
use Illuminate\Support\Facades\Schema;
use Throwable;

class BuildGeneralJournalBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min

    public function __construct(
        public string $ticket,
        public string $startDate,
        public string $endDate,
        public string $format,          // 'pdf' | 'xls'
        public ?int $companyId,
        public ?string $query = null
    ) {}

    private function key(): string { return "gjb:{$this->ticket}"; }

    private function patchState(array $patch): void
    {
        $cur = Cache::get($this->key(), []);
        Cache::put($this->key(), array_merge($cur, $patch), now()->addHours(2));
    }

    // Option A: tenant-safe filename prefix
    private function filePrefix(): string
    {
        $cid = (int) ($this->companyId ?? 0);
        return "general_journal_c{$cid}_";
    }

    private function pruneOldReports(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local');
        $prefix = $this->filePrefix();

        $files = collect($disk->files('reports'))
            ->filter(fn($p) => str_starts_with(basename($p), $prefix))
            ->when($sameFormatOnly, function ($c) use ($keepFile) {
                $ext = pathinfo($keepFile, PATHINFO_EXTENSION);
                return $c->filter(fn($p) => pathinfo($p, PATHINFO_EXTENSION) === $ext);
            })
            ->sortByDesc(fn($p) => $disk->lastModified($p))
            ->slice($keep);

        $files->each(fn($p) => $disk->delete($p));
    }

    /**
     * Base query for headers + aggregated detail lines (tenant-safe).
     * Important: enforce company scope in BOTH header and detail join (if possible).
     */
    private function baseQuery(int $cid)
    {
        $detailsHasCompany = Schema::hasColumn('general_accounting_details', 'company_id');
        $acctHasCompany    = Schema::hasColumn('account_code', 'company_id');

        $q = DB::table('general_accounting as r')
            ->selectRaw("
                r.id,
                to_char(r.gen_acct_date,'MM/DD/YYYY') as gen_date,
                r.ga_no,
                r.explanation,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', COALESCE(a.acct_desc,''),
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
            // join details (transaction_id is text in your schema)
            ->join('general_accounting_details as d', function ($j) use ($detailsHasCompany) {
                $j->on(DB::raw('d.transaction_id'), '=', DB::raw('r.id::text'));
                if ($detailsHasCompany) {
                    $j->on('d.company_id', '=', 'r.company_id');
                }
            })
            // join account_code (company scoped if possible)
            ->leftJoin('account_code as a', function ($j) use ($acctHasCompany, $cid) {
                $j->on('a.acct_code', '=', 'd.acct_code');
                if ($acctHasCompany) {
                    $j->where('a.company_id', '=', $cid);
                }
            })
            // hard scope header by company
            ->where('r.company_id', $cid);

        return $q;
    }

    private function applyFilters($q, int $cid)
    {
        $q->whereBetween('r.gen_acct_date', [$this->startDate, $this->endDate]);

        if ($this->query) {
            $needle = '%'.$this->query.'%';
            $q->where(function($qq) use ($needle) {
                $qq->where('r.ga_no','ILIKE',$needle)
                   ->orWhere('r.explanation','ILIKE',$needle)
                   ->orWhere('d.acct_code','ILIKE',$needle)
                   ->orWhere('a.acct_desc','ILIKE',$needle);
            });
        }

        return $q;
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
            'query'      => $this->query,
            'company_id' => $this->companyId,
        ]);

        try {
            // Option A: company_id is mandatory
            $cid = (int) ($this->companyId ?? 0);
            if ($cid <= 0) {
                throw new \RuntimeException('Missing company_id. Refusing to generate unscoped report.');
            }

            // Pre-count headers for progress (same scoping)
            $countQ = DB::table('general_accounting as r')
                ->where('r.company_id', $cid)
                ->whereBetween('r.gen_acct_date', [$this->startDate, $this->endDate]);

            if ($this->query) {
                $needle = '%'.$this->query.'%';
                $countQ->where(function($q) use ($needle, $cid) {
                    $q->where('r.ga_no', 'ILIKE', $needle)
                      ->orWhere('r.explanation', 'ILIKE', $needle)
                      ->orWhereExists(function($sq) use ($needle, $cid) {
                          $sq->from('general_accounting_details as d')
                             ->leftJoin('account_code as a', function ($j) use ($cid) {
                                 // company-scope account_code if it has company_id
                                 if (Schema::hasColumn('account_code','company_id')) {
                                     $j->on('a.acct_code', '=', 'd.acct_code')
                                       ->where('a.company_id', '=', $cid);
                                 } else {
                                     $j->on('a.acct_code', '=', 'd.acct_code');
                                 }
                             })
                             ->whereRaw('d.transaction_id::bigint = r.id')
                             ->where(function($qq) use ($needle) {
                                 $qq->where('d.acct_code','ILIKE',$needle)
                                    ->orWhere('a.acct_desc','ILIKE',$needle);
                             });
                      });
                });
            }

            $total = (clone $countQ)->count();
            $step = function (int $done) use ($total) {
                $pct = $total ? min(99, 1 + (int)floor(($done / max(1,$total)) * 98)) : 50;
                $this->patchState(['progress' => $pct]);
            };

            // Ensure reports dir
            $dir  = 'reports';
            $disk = Storage::disk('local');
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            // Tenant-safe filename
            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $ext   = ($this->format === 'pdf') ? 'pdf' : 'xls';
            $path  = "{$dir}/{$this->filePrefix()}{$stamp}.{$ext}";

            if ($this->format === 'pdf') $this->buildPdf($path, $step, $cid);
            else                         $this->buildXls($path, $step, $cid);

            $this->pruneOldReports($path, sameFormatOnly: true, keep: 1);

            $this->patchState(['status'=>'done','progress'=>100,'file'=>$path]);
        } catch (Throwable $e) {
            $this->patchState(['status'=>'error','progress'=>100,'error'=>$e->getMessage()]);
            throw $e;
        }
    }

    /**
     * PDF: fix the "disarray" by using FIXED column widths.
     */
    private function buildPdf(string $path, callable $progress, int $cid): void
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

<h2>GENERAL JOURNAL</h2>
<div><b>For the period covering {$this->startDate} — {$this->endDate}</b></div>
<br/>

<table width="100%" cellspacing="0" cellpadding="1">
  <tr>
    <td width="15%"></td>
    <td width="55%"></td>
    <td width="15%" align="right"><b>Debit</b></td>
    <td width="15%" align="right"><b>Credit</b></td>
  </tr>
</table>
HTML;

        $pdf->writeHTML($hdr, true, false, false, false, '');

        $done = 0;
        $lineCount = 0;

        $q = $this->applyFilters($this->baseQuery($cid), $cid)
            ->groupBy('r.id','r.gen_acct_date','r.ga_no','r.explanation')
            ->orderBy('r.id');

        $q->chunk(200, function($chunk) use (&$done, $progress, $pdf, &$lineCount) {
            foreach ($chunk as $row) {
                $gjId = 'GLGJ-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                $block = <<<HTML
<table width="100%" cellspacing="0" cellpadding="1">
  <tr>
    <td width="25%"><b>{$gjId}</b></td>
    <td width="75%"><b>JE - {$row->ga_no}</b></td>
  </tr>
  <tr>
    <td width="25%">{$row->gen_date}</td>
    <td width="75%">{$row->explanation}</td>
  </tr>
</table>
HTML;
                $pdf->writeHTML($block, true, false, false, false, '');

                $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';
                $itemDebit = 0; $itemCredit = 0;

                foreach (json_decode($row->lines, true) as $ln) {
                    $debit  = (float)($ln['debit'] ?? 0);
                    $credit = (float)($ln['credit'] ?? 0);
                    $itemDebit  += $debit;
                    $itemCredit += $credit;

                    $rowsHtml .= sprintf(
                        '<tr>
                           <td width="15%%">&nbsp;&nbsp;&nbsp;%s</td>
                           <td width="55%%">%s</td>
                           <td width="15%%" align="right">%s</td>
                           <td width="15%%" align="right">%s</td>
                         </tr>',
                        e($ln['acct_code'] ?? ''),
                        e($ln['acct_desc'] ?? ''),
                        number_format($debit, 2),
                        number_format($credit, 2)
                    );

                    $lineCount++;
                    if ($lineCount >= 28) { // slightly more stable per page with fixed widths
                        $rowsHtml .= '</table>';
                        $pdf->writeHTML($rowsHtml, true, false, false, false, '');
                        $pdf->AddPage();
                        $lineCount = 0;
                        $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';
                    }
                }

                $rowsHtml .= sprintf(
                    '<tr>
                       <td width="15%%"></td>
                       <td width="55%%" align="right"><b>TOTAL</b></td>
                       <td width="15%%" align="right"><b>%s</b></td>
                       <td width="15%%" align="right"><b>%s</b></td>
                     </tr>
                     <tr><td colspan="4"><hr/></td></tr>
                     <tr><td colspan="4"><br/></td></tr>',
                    number_format($itemDebit, 2),
                    number_format($itemCredit, 2)
                );
                $rowsHtml .= '</table>';

                $pdf->writeHTML($rowsHtml, true, false, false, false, '');

                $done++; $progress($done);
            }
        });

        Storage::disk('local')->put($path, $pdf->Output('general-journal.pdf', 'S'));
    }

    private function buildXls(string $path, callable $progress, int $cid): void
    {
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('General Journal');

        // Column widths similar to your screenshots
        $ws->getColumnDimension('A')->setWidth(18); // acct_code or gj id
        $ws->getColumnDimension('B')->setWidth(55); // desc/explanation
        $ws->getColumnDimension('C')->setWidth(18);
        $ws->getColumnDimension('D')->setWidth(18);
        $ws->getColumnDimension('E')->setWidth(12); // TOTAL label
        $ws->getColumnDimension('F')->setWidth(18); // DEBIT
        $ws->getColumnDimension('G')->setWidth(18); // CREDIT

        $r = 1;
        $ws->setCellValue("A{$r}", 'GENERAL JOURNAL'); $r++;
        $ws->setCellValue("A{$r}", 'SUCDEN PHILIPPINES, INC.'); $r++;
        $ws->setCellValue("A{$r}", 'TIN- 000-105-267-000'); $r++;
        $ws->setCellValue("A{$r}", 'Unit 2202 The Podium West Tower, 12 ADB Ave'); $r++;
        $ws->setCellValue("A{$r}", 'Ortigas Center Mandaluyong City'); $r += 2;
        $ws->setCellValue("A{$r}", "For the period covering: {$this->startDate} to {$this->endDate}"); $r += 2;

        $ws->setCellValue("F{$r}", 'DEBIT');
        $ws->setCellValue("G{$r}", 'CREDIT');
        $r++;

        $done = 0;

        $q = $this->applyFilters($this->baseQuery($cid), $cid)
            ->groupBy('r.id','r.gen_acct_date','r.ga_no','r.explanation')
            ->orderBy('r.id');

        $q->chunk(200, function($chunk) use (&$r, $ws, &$done, $progress) {
            foreach ($chunk as $row) {
                $gjId = 'GLGJ-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                $ws->setCellValue("A{$r}", $gjId);
                $ws->setCellValue("B{$r}", "JE - {$row->ga_no}");
                $r++;

                $ws->setCellValue("A{$r}", $row->gen_date);
                $ws->setCellValue("B{$r}", $row->explanation);
                $r++;

                $itemDebit = 0; $itemCredit = 0;

                foreach (json_decode($row->lines, true) as $ln) {
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

        Storage::disk('local')->put($path, stream_get_contents($stream));

        fclose($stream);
        $wb->disconnectWorksheets();
        unset($writer);
    }
}
