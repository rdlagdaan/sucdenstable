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

class BuildAccountsPayableJournal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 minutes

    public function __construct(
        public string $ticket,
        public string $startDate,
        public string $endDate,
        public string $format,     // 'pdf' | 'xls'
        public int $companyId,
        public ?int $userId,
        public ?string $query = null
    ) {}

    private function key(): string { return "apj:{$this->ticket}"; }

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
            'query'      => $this->query,
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
            $total = DB::table('cash_purchase as r')
                ->where('r.company_id', $cid)
                ->whereBetween('r.purchase_date', [$this->startDate, $this->endDate])
                ->when($this->query, function ($q) {
                    $q->where(function ($x) {
                        $like = '%'.$this->query.'%';
                        $x->where('r.cp_no', 'ILIKE', $like)
                          ->orWhere('r.booking_no', 'ILIKE', $like)
                          ->orWhere('r.explanation', 'ILIKE', $like)
                          ->orWhere('r.rr_no', 'ILIKE', $like)
                          ->orWhere('r.bank_id', 'ILIKE', $like)
                          ->orWhere('r.vend_id', 'ILIKE', $like)
                          ->orWhere('r.mill_id', 'ILIKE', $like);
                    });
                })
                ->count();

            $step = function (int $done) use ($total) {
                $pct = $total ? min(99, 1 + (int)floor(($done / max(1, $total)) * 98)) : 50;
                $this->patchState(['progress' => $pct]);
            };

            $dir  = 'reports';
            $disk = Storage::disk('local');
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $path  = $this->format === 'pdf'
                ? "{$dir}/accounts_payable_journal_c{$cid}_{$stamp}.pdf"
                : "{$dir}/accounts_payable_journal_c{$cid}_{$stamp}.xls";

            if ($this->format === 'pdf') $this->buildPdf($path, $step);
            else                         $this->buildExcel($path, $step);

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

    private function pruneOldReports(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local');

        $cid = (int) ($this->companyId ?? 0);
        $prefix = "accounts_payable_journal_c{$cid}_";

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
     * ✅ Company-aware header block
     * - company_id=2 => AMEROP
     * - default => SUCDEN
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

private function buildPdf(string $file, callable $progress): void
{
    $cid = (int) $this->companyId;
    $co  = $this->companyHeader();

    // vendor_list may or may not have company_id
    $vendHasCompany = Schema::hasColumn('vendor_list', 'company_id');

    // ✅ mm/dd/yyyy in header range
    $from = \Carbon\Carbon::parse($this->startDate)->format('m/d/Y');
    $to   = \Carbon\Carbon::parse($this->endDate)->format('m/d/Y');

    // ✅ Repeat FULL header on every page
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

            $this->SetY(8);
            $this->SetFont('helvetica', 'B', 11);
            $this->Cell(0, 5, $name, 0, 1, 'R');

            $this->SetFont('helvetica', '', 8);
            $this->Cell(0, 4, $tin, 0, 1, 'R');
            $this->Cell(0, 4, $addr1, 0, 1, 'R');
            $this->Cell(0, 4, $addr2, 0, 1, 'R');

            $y = $this->GetY() + 3;
            $this->Line(10, $y, 206, $y);

            $this->SetY($y + 6);
            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 7, 'PURCHASE JOURNAL', 0, 1, 'L');

            $this->SetFont('helvetica', 'B', 11);
            $this->Cell(0, 6, "For the period covering {$this->from} -- {$this->to}", 0, 1, 'L');

            $y2 = $this->GetY() + 2;
            $this->Line(10, $y2, 206, $y2);

            $this->SetY($y2 + 4);
            $this->SetFont('helvetica', 'B', 10);

            // Debit/Credit labels (right aligned)
            $this->SetX(150);
            $this->Cell(28, 5, 'Debit', 0, 0, 'R');
            $this->Cell(28, 5, 'Credit', 0, 1, 'R');

            $this->SetFont('helvetica', '', 7);
            $this->Ln(2);
        }

        public function Footer()
        {
            $this->SetY(-18);
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

    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);

    $pdf->SetHeaderMargin(5);
    $pdf->SetMargins(10, 52, 10);
    $pdf->SetAutoPageBreak(true, 20);

    $pdf->SetFont('helvetica', '', 7);
    $pdf->AddPage('P', 'LETTER');

    $done = 0;

    DB::table('cash_purchase as r')
        ->selectRaw("
            r.id,
            to_char(r.purchase_date,'MM/DD/YYYY') as purchase_date,
            r.cp_no,
            r.rr_no,
            r.explanation,

            (
                SELECT m2.mill_name
                FROM mill_list m2
                WHERE m2.company_id = r.company_id
                  AND m2.mill_id = r.mill_id
                ORDER BY m2.id DESC
                LIMIT 1
            ) as mill_name,

            COALESCE(b.acct_desc, r.bank_id) as bank_name,

            -- ✅ ALWAYS produce something displayable:
            COALESCE(v.vend_name, r.vend_id) as vend_name,

            json_agg(json_build_object(
                'acct_code', d.acct_code,
                'acct_desc', COALESCE(a.acct_desc, ''),
                'debit', d.debit,
                'credit', d.credit
            ) ORDER BY d.id) as lines
        ")
        ->join('cash_purchase_details as d', function ($j) {
            $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
        })
        ->leftJoin('account_code as a', function ($j) use ($cid) {
            $j->on('a.acct_code', '=', 'd.acct_code')
              ->where('a.company_id', '=', $cid);
        })
        ->leftJoin('account_code as b', function ($j) use ($cid) {
            $j->on('b.acct_code', '=', 'r.bank_id')
              ->where('b.company_id', '=', $cid);
        })
        // ✅ Vendor join MUST be its own join (NOT inside another join closure)
        ->leftJoin('vendor_list as v', function ($j) use ($cid, $vendHasCompany) {
            // match like your working SQL: upper(trim(v.vend_code)) = upper(trim(r.vend_id))
            $j->on(DB::raw('upper(trim(v.vend_code))'), '=', DB::raw('upper(trim(r.vend_id))'));
            if ($vendHasCompany) {
                $j->where('v.company_id', '=', $cid);
            }
        })
        ->where('r.company_id', $cid)
        ->whereBetween('r.purchase_date', [$this->startDate, $this->endDate])
        ->when($this->query, function ($q) {
            $q->where(function ($x) {
                $like = '%'.$this->query.'%';
                $x->where('r.cp_no', 'ILIKE', $like)
                  ->orWhere('r.booking_no', 'ILIKE', $like)
                  ->orWhere('r.explanation', 'ILIKE', $like)
                  ->orWhere('r.rr_no', 'ILIKE', $like)
                  ->orWhere('r.bank_id', 'ILIKE', $like)
                  ->orWhere('r.vend_id', 'ILIKE', $like)
                  ->orWhere('r.mill_id', 'ILIKE', $like);
            });
        })
        ->groupBy(
            'r.id',
            'r.purchase_date',
            'r.cp_no',
            'r.rr_no',
            'r.explanation',
            'r.bank_id',
            'b.acct_desc',
            'v.vend_name',
            'r.vend_id'
        )
        ->orderBy('r.id')
        ->chunk(200, function ($chunk) use (&$done, $progress, $pdf) {
            foreach ($chunk as $row) {
                $done++;
                $progress($done);

                if ($pdf->GetY() > 260) {
                    $pdf->AddPage();
                }

                $lines = json_decode($row->lines, true) ?: [];

                // ✅ Vendor name now comes from COALESCE(v.vend_name, r.vend_id)
                $descLine = trim(implode(' - ', array_filter([
                    $row->rr_no,
                    $row->vend_name,
                    $row->mill_name,
                    $row->bank_name,
                    $row->explanation,
                ])));

                $blockTop = '
<table width="100%" cellspacing="0" cellpadding="1">
  <tr>
    <td width="15%"><b>'.e($row->purchase_date).'</b></td>
    <td width="10%"><b>'.e((string)$row->id).'</b></td>
    <td width="75%"></td>
  </tr>
  <tr>
    <td colspan="7"><b>'.e($descLine).'</b> &nbsp;<u>'.e((string)$row->cp_no).'</u></td>
  </tr>
</table>';

                $pdf->writeHTML($blockTop, true, false, false, false, '');

                $td = 0.0;
                $tc = 0.0;

                $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';
                foreach ($lines as $ln) {
                    $debit  = (float)($ln['debit'] ?? 0);
                    $credit = (float)($ln['credit'] ?? 0);

                    $td += $debit;
                    $tc += $credit;

                    $rowsHtml .= sprintf(
                        '<tr>
                           <td width="15%%">%s</td>
                           <td width="55%%">%s</td>
                           <td width="15%%" align="right">%s</td>
                           <td width="15%%" align="right">%s</td>
                         </tr>',
                        e($ln['acct_code'] ?? ''),
                        e($ln['acct_desc'] ?? ''),
                        number_format($debit, 2),
                        number_format($credit, 2)
                    );

                    if ($pdf->GetY() > 255) {
                        $rowsHtml .= '</table>';
                        $pdf->writeHTML($rowsHtml, true, false, false, false, '');
                        $pdf->AddPage();
                        $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';
                    }
                }

                $rowsHtml .= sprintf(
                    '<tr>
                       <td width="70%%"></td>
                       <td width="15%%" align="right"><b>%s</b></td>
                       <td width="15%%" align="right"><b>%s</b></td>
                     </tr>
                     <tr><td colspan="4"><hr/></td></tr>
                     <tr><td colspan="4"><br/></td></tr>',
                    number_format($td, 2),
                    number_format($tc, 2)
                );

                $rowsHtml .= '</table>';

                $pdf->writeHTML($rowsHtml, true, false, false, false, '');
            }
        });

    Storage::disk('local')->put($file, $pdf->Output('accounts-payable.pdf', 'S'));
}

private function buildExcel(string $file, callable $progress): void
{
    $cid = (int) $this->companyId;
    $co  = $this->companyHeader();

    $vendHasCompany = Schema::hasColumn('vendor_list', 'company_id');

    $from = \Carbon\Carbon::parse($this->startDate)->format('m/d/Y');
    $to   = \Carbon\Carbon::parse($this->endDate)->format('m/d/Y');

    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Purchase Journal');

    $r = 1;
    $ws->setCellValue("A{$r}", 'PURCHASE JOURNAL'); $r++;
    $ws->setCellValue("A{$r}", $co['name']);  $r++;
    $ws->setCellValue("A{$r}", $co['tin']);   $r++;
    $ws->setCellValue("A{$r}", $co['addr1']); $r++;
    $ws->setCellValue("A{$r}", $co['addr2']); $r += 2;

    $ws->setCellValue("A{$r}", "For the period covering: {$from} to {$to}");
    $r += 2;

    $ws->fromArray(['', '', '', '', '', 'DEBIT', 'CREDIT'], null, "A{$r}");
    $r++;

    foreach (range('A', 'G') as $col) {
        $ws->getColumnDimension($col)->setWidth(18);
    }

    $done = 0;

    DB::table('cash_purchase as r')
        ->selectRaw("
            r.id,
            to_char(r.purchase_date,'MM/DD/YYYY') as purchase_date,
            r.cp_no,
            r.rr_no,
            r.explanation,

            (
                SELECT m2.mill_name
                FROM mill_list m2
                WHERE m2.company_id = r.company_id
                  AND m2.mill_id = r.mill_id
                ORDER BY m2.id DESC
                LIMIT 1
            ) as mill_name,

            COALESCE(b.acct_desc, r.bank_id) as bank_name,

            -- ✅ ALWAYS produce something displayable:
            COALESCE(v.vend_name, r.vend_id) as vend_name,

            json_agg(json_build_object(
                'acct_code', d.acct_code,
                'acct_desc', COALESCE(a.acct_desc, ''),
                'debit', d.debit,
                'credit', d.credit
            ) ORDER BY d.id) as lines
        ")
        ->join('cash_purchase_details as d', function ($j) {
            $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
        })
        ->leftJoin('account_code as a', function ($j) use ($cid) {
            $j->on('a.acct_code', '=', 'd.acct_code')
              ->where('a.company_id', '=', $cid);
        })
        ->leftJoin('account_code as b', function ($j) use ($cid) {
            $j->on('b.acct_code', '=', 'r.bank_id')
              ->where('b.company_id', '=', $cid);
        })
        ->leftJoin('vendor_list as v', function ($j) use ($cid, $vendHasCompany) {
            $j->on(DB::raw('upper(trim(v.vend_code))'), '=', DB::raw('upper(trim(r.vend_id))'));
            if ($vendHasCompany) {
                $j->where('v.company_id', '=', $cid);
            }
        })
        ->where('r.company_id', $cid)
        ->whereBetween('r.purchase_date', [$this->startDate, $this->endDate])
        ->when($this->query, function ($q) {
            $q->where(function ($x) {
                $like = '%'.$this->query.'%';
                $x->where('r.cp_no', 'ILIKE', $like)
                  ->orWhere('r.booking_no', 'ILIKE', $like)
                  ->orWhere('r.explanation', 'ILIKE', $like)
                  ->orWhere('r.rr_no', 'ILIKE', $like)
                  ->orWhere('r.bank_id', 'ILIKE', $like)
                  ->orWhere('r.vend_id', 'ILIKE', $like)
                  ->orWhere('r.mill_id', 'ILIKE', $like);
            });
        })
        ->groupBy(
            'r.id',
            'r.purchase_date',
            'r.cp_no',
            'r.rr_no',
            'r.explanation',
            'r.bank_id',
            'b.acct_desc',
            'v.vend_name',
            'r.vend_id'
        )
        ->orderBy('r.id')
        ->chunk(200, function ($chunk) use (&$r, $ws, &$done, $progress) {
            foreach ($chunk as $row) {
                $done++;
                $progress($done);

                $lines = json_decode($row->lines, true) ?: [];

                $descLine = trim(implode(' - ', array_filter([
                    $row->rr_no,
                    $row->vend_name,
                    $row->mill_name,
                    $row->bank_name,
                    $row->explanation
                ])));

                $ws->setCellValue("A{$r}", $row->purchase_date);
                $ws->setCellValue("B{$r}", $row->id);
                $r++;

                $ws->setCellValue("A{$r}", $descLine);
                $ws->setCellValue("B{$r}", $row->cp_no);
                $r++;

                $itemTotalDebit  = 0.0;
                $itemTotalCredit = 0.0;

                foreach ($lines as $ln) {
                    $debit  = (float) ($ln['debit'] ?? 0);
                    $credit = (float) ($ln['credit'] ?? 0);

                    $itemTotalDebit  += $debit;
                    $itemTotalCredit += $credit;

                    $ws->setCellValue("A{$r}", $ln['acct_code'] ?? '');
                    $ws->setCellValue("B{$r}", $ln['acct_desc'] ?? '');
                    $ws->setCellValue("F{$r}", $debit);
                    $ws->setCellValue("G{$r}", $credit);
                    $ws->getStyle("F{$r}:G{$r}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                    $r++;
                }

                $ws->setCellValue("E{$r}", "TOTAL");
                $ws->setCellValue("F{$r}", $itemTotalDebit);
                $ws->setCellValue("G{$r}", $itemTotalCredit);
                $ws->getStyle("F{$r}:G{$r}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                $r += 2;
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
