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
 * ✅ Company-aware header block (uses $cid passed into buildPdf/buildXls)
 * - company_id=2 => AMEROP
 * - default => SUCDEN
 */
private function companyHeader(int $cid): array
{
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


    /**
     * PDF: fix the "disarray" by using FIXED column widths.
     */
private function buildPdf(string $path, callable $progress, int $cid): void
{
    @ini_set('memory_limit', '512M');

    $co = $this->companyHeader($cid);

    // ✅ force mm/dd/yyyy display in header period
    $from = \Carbon\Carbon::parse($this->startDate)->format('m/d/Y');
    $to   = \Carbon\Carbon::parse($this->endDate)->format('m/d/Y');

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
            $this->Cell(0, 7, 'GENERAL JOURNAL', 0, 1, 'L');

            // ✅ Period + Debit/Credit on SAME LINE
            $this->SetFont('helvetica', 'B', 10);
            $periodY = $this->GetY();

            $this->SetXY(10, $periodY);
            $this->Cell(140, 6, "For the period covering {$this->from} — {$this->to}", 0, 0, 'L');

            $this->SetXY(150, $periodY);
            $this->Cell(28, 6, 'Debit', 0, 0, 'R');
            $this->Cell(28, 6, 'Credit', 0, 0, 'R');

            // underline under the header row
            $y2 = $periodY + 9;
            $this->Line(10, $y2, 206, $y2);

            $this->Ln(12);
            $this->SetFont('helvetica', '', 8);
        }

        public function Footer()
        {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);

            $currentDate = date('M d, Y');
            $currentTime = date('h:i:sa');
            $pageText = $this->getAliasNumPage() . '/' . $this->getAliasNbPages();

            $this->Cell(70, 5, "Print Date: {$currentDate}", 0, 0, 'L');
            $this->Cell(60, 5, $pageText, 0, 0, 'C');
            $this->Cell(70, 5, "Print Time: {$currentTime}", 0, 1, 'L');
        }
    };

    // ✅ repeat Header() every page (no HTML header tables => no $cellspacingx error)
    $pdf->setPrintHeader(true);

    // Top margin big enough so body never overlaps header
    $pdf->SetHeaderMargin(5);
    $pdf->SetMargins(10, 52, 10);
    $pdf->SetAutoPageBreak(true, 16);

    $pdf->SetFont('helvetica', '', 8);
    $pdf->AddPage();

    $done = 0;

    $q = $this->applyFilters($this->baseQuery($cid), $cid)
        ->groupBy('r.id','r.gen_acct_date','r.ga_no','r.explanation')
        ->orderBy('r.id');

    $q->chunk(200, function ($chunk) use (&$done, $progress, $pdf) {

        foreach ($chunk as $row) {

            $gjId = 'GLGJ-' . str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

            // ✅ lines already have MM/DD/YYYY alias gen_date from baseQuery
            $pdf->writeHTML(
                '<table width="100%" cellspacing="0" cellpadding="1">'
              . '<tr><td width="25%"><b>'.e($gjId).'</b></td><td width="75%"><b>JE - '.e($row->ga_no).'</b></td></tr>'
              . '<tr><td width="25%">'.e($row->gen_date).'</td><td width="75%">'.e($row->explanation).'</td></tr>'
              . '</table>',
                true, false, false, false, ''
            );

            $itemDebit  = 0.0;
            $itemCredit = 0.0;

            $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';
            foreach ((json_decode($row->lines, true) ?: []) as $ln) {
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
                    $debit  == 0.0 ? '' : number_format($debit, 2),
                    $credit == 0.0 ? '' : number_format($credit, 2)
                );
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

            $done++;
            $progress($done);
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
    $ws->getColumnDimension('A')->setWidth(18);
    $ws->getColumnDimension('B')->setWidth(55);
    $ws->getColumnDimension('C')->setWidth(18);
    $ws->getColumnDimension('D')->setWidth(18);
    $ws->getColumnDimension('E')->setWidth(12);
    $ws->getColumnDimension('F')->setWidth(18);
    $ws->getColumnDimension('G')->setWidth(18);

    $co = $this->companyHeader($cid);

    $r = 1;
    $ws->setCellValue("A{$r}", 'GENERAL JOURNAL'); $r++;
    $ws->setCellValue("A{$r}", $co['name']);  $r++;
    $ws->setCellValue("A{$r}", $co['tin']);   $r++;
    $ws->setCellValue("A{$r}", $co['addr1']); $r++;
    $ws->setCellValue("A{$r}", $co['addr2']); $r += 2;

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
