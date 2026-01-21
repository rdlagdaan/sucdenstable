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
use Carbon\Carbon;
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

    /**
     * Tenant-safe pruning: only within the same company+user prefix
     */
    private function pruneOldReports(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local');

        $cid = (int) ($this->companyId ?? 0);
        $uid = (int) ($this->userId ?? 0);

        $prefix = "cash_disbursement_book_c{$cid}_u{$uid}_";

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

        $cid = (int) ($this->companyId ?? 0);
        if ($cid <= 0) {
            $this->patchState([
                'status'   => 'error',
                'progress' => 100,
                'error'    => 'Missing company scope (companyId=0).',
            ]);
            return;
        }

        try {
            $total = DB::table('cash_disbursement as r')
                ->where('r.company_id', $cid)
                ->whereBetween('r.disburse_date', [$this->startDate, $this->endDate])
                ->count();

            $step = function (int $done) use ($total) {
                $pct = $total ? min(99, 1 + (int)floor(($done / max(1, $total)) * 98)) : 50;
                $this->patchState(['progress' => $pct]);
            };

            $dir  = 'reports';
            $disk = Storage::disk('local');
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $ext   = $this->format === 'pdf' ? 'pdf' : 'xls';

            $uid  = (int) ($this->userId ?? 0);
            $path = "{$dir}/cash_disbursement_book_c{$cid}_u{$uid}_{$stamp}.{$ext}";

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
            $this->patchState([
                'status'   => 'error',
                'progress' => 100,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * ✅ Company-aware header block
     * - company_id=2 => AMEROP
     * - default => SUCDEN
     */
    private function companyHeader(): array
    {
        $cid = (int) ($this->companyId ?? 0);

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
        $cid = (int) ($this->companyId ?? 0);

        // detect optional company_id columns for safe joins
        $acctHasCompany = Schema::hasColumn('account_code', 'company_id');
        $vendHasCompany = Schema::hasColumn('vendor_list', 'company_id');

        $co = $this->companyHeader();

        // ✅ Period format mm/dd/yyyy
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

                // Divider
                $y = $this->GetY() + 3;
                $this->Line(10, $y, 206, $y);

                // Title
                $this->SetY($y + 6);
                $this->SetFont('helvetica', 'B', 16);
                $this->Cell(0, 7, 'CASH DISBURSEMENTS JOURNAL', 0, 1, 'L');

                // Period (mm/dd/yyyy)
// Period + Debit/Credit on the SAME baseline
$this->SetFont('helvetica', 'B', 10);

// Remember Y position
$yPeriod = $this->GetY();

// Left: period text
$this->SetXY(10, $yPeriod);
$this->Cell(0, 6, "For the period covering {$this->from} — {$this->to}", 0, 0, 'L');

// Right: Debit / Credit aligned with amount columns
$this->SetXY(150, $yPeriod);
$this->Cell(28, 6, 'Debit', 0, 0, 'R');
$this->Cell(28, 6, 'Credit', 0, 1, 'R');

// Space before body
$this->Ln(2);
$this->SetFont('helvetica', '', 8);

            }

            public function Footer()
            {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);

                $currentDate = date('M d, Y');
                $currentTime = date('h:i:sa');
                $pageText = $this->getAliasNumPage() . '/' . $this->getAliasNbPages();

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

        // ✅ Repeat header on every page
        $pdf->setPrintHeader(true);

        // ✅ Big enough top margin so body never overlaps header
        $pdf->SetHeaderMargin(5);
        $pdf->SetMargins(10, 52, 10);

        // ✅ Let TCPDF paginate naturally (removes big white spaces from manual AddPage logic)
        $pdf->SetAutoPageBreak(true, 16);

        $pdf->setImageScale(1.25);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->AddPage('P', 'LETTER');

        $done = 0;

        DB::table('cash_disbursement as r')
            ->selectRaw("
                r.id,
                to_char(r.disburse_date,'MM/DD/YYYY') as disburse_date,
                r.cd_no,
                r.check_ref_no,
                r.explanation,
                COALESCE(b.acct_desc, r.bank_id) as bank_name,
                COALESCE(v.vend_name, r.vend_id) as vend_name,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', COALESCE(a.acct_desc,''),
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
            ->leftJoin('account_code as b', function ($j) use ($cid, $acctHasCompany) {
                $j->on('b.acct_code', '=', 'r.bank_id');
                if ($acctHasCompany) $j->where('b.company_id', '=', $cid);
            })
            ->join('cash_disbursement_details as d', function ($j) {
                $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
            })
            ->leftJoin('account_code as a', function ($j) use ($cid, $acctHasCompany) {
                $j->on('a.acct_code', '=', 'd.acct_code');
                if ($acctHasCompany) $j->where('a.company_id', '=', $cid);
            })
            ->leftJoin('vendor_list as v', function ($j) use ($cid, $vendHasCompany) {
                $j->on('v.vend_code', '=', 'r.vend_id');
                if ($vendHasCompany) $j->where('v.company_id', '=', $cid);
            })
            ->where('r.company_id', $cid)
            ->whereBetween('r.disburse_date', [$this->startDate, $this->endDate])
            ->groupBy('r.id','r.disburse_date','r.cd_no','r.check_ref_no','r.explanation','b.acct_desc','v.vend_name','r.vend_id','r.bank_id')
            ->orderBy('r.id')
            ->chunk(200, function($chunk) use (&$done, $progress, $pdf) {
                foreach ($chunk as $row) {
                    $done++;
                    $progress($done);

                    $cdbId = 'APMC-' . str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                    $disbDate   = htmlspecialchars((string)$row->disburse_date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $bankName   = htmlspecialchars((string)$row->bank_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $vendName   = htmlspecialchars((string)$row->vend_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $explain    = htmlspecialchars((string)$row->explanation, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $cdNo       = htmlspecialchars((string)$row->cd_no, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $checkRef   = htmlspecialchars((string)$row->check_ref_no, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $cdbIdSafe  = htmlspecialchars((string)$cdbId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    // Voucher header block (nobr so it doesn't split awkwardly)
                    $block = <<<HTML
<table width="100%" cellspacing="0" cellpadding="1" nobr="true">
  <tr>
    <td width="18%">{$disbDate}</td>
    <td width="82%" colspan="6">{$cdbIdSafe}</td>
  </tr>
  <tr>
    <td width="18%"><b>CV# {$cdNo}</b></td>
    <td width="82%" colspan="6">Check#: {$checkRef}&nbsp;&nbsp;&nbsp;&nbsp;{$bankName}</td>
  </tr>
  <tr>
    <td width="100%" colspan="7">{$vendName}&nbsp;&nbsp;&nbsp;&nbsp;{$explain}</td>
  </tr>
</table>
HTML;

                    $pdf->writeHTML($block, true, false, false, false, '');

                    $itemDebit = 0.0;
                    $itemCredit = 0.0;

                    $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';

                    foreach ((json_decode($row->lines, true) ?: []) as $ln) {
                        $acctCode = htmlspecialchars((string)($ln['acct_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $acctDesc = htmlspecialchars((string)($ln['acct_desc'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $debit    = (float)($ln['debit'] ?? 0);
                        $credit   = (float)($ln['credit'] ?? 0);

                        $itemDebit  += $debit;
                        $itemCredit += $credit;

                        $rowsHtml .= sprintf(
                            '<tr>
                               <td width="18%%">&nbsp;&nbsp;&nbsp;%s</td>
                               <td width="52%%" colspan="4">%s</td>
                               <td width="15%%" align="right">%s</td>
                               <td width="15%%" align="right">%s</td>
                             </tr>',
                            $acctCode,
                            $acctDesc,
                            number_format($debit, 2),
                            number_format($credit, 2)
                        );
                    }

                    // Totals + divider (keeps it tight, no forced page breaks)
                    $rowsHtml .= sprintf(
                        '<tr>
                           <td></td><td colspan="4"></td>
                           <td align="right"><b>%s</b></td>
                           <td align="right"><b>%s</b></td>
                         </tr>
                         <tr><td colspan="7"><hr/></td></tr>
                         <tr><td colspan="7" style="line-height:6px;">&nbsp;</td></tr>',
                        number_format($itemDebit, 2),
                        number_format($itemCredit, 2)
                    );

                    $rowsHtml .= '</table>';

                    $pdf->writeHTML($rowsHtml, true, false, false, false, '');
                }
            });

        Storage::disk('local')->put($file, $pdf->Output('cash-disbursements.pdf', 'S'));
    }

    private function buildExcel(string $file, callable $progress): void
    {
        $cid = (int) ($this->companyId ?? 0);

        $acctHasCompany = Schema::hasColumn('account_code', 'company_id');
        $vendHasCompany = Schema::hasColumn('vendor_list', 'company_id');

        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Cash Disbursements Journal');

        $co = $this->companyHeader();

        // ✅ Period format mm/dd/yyyy
        $from = Carbon::parse($this->startDate)->format('m/d/Y');
        $to   = Carbon::parse($this->endDate)->format('m/d/Y');

        $r = 1;
        $ws->setCellValue("A{$r}", 'CASH DISBURSEMENTS JOURNAL'); $r++;
        $ws->setCellValue("A{$r}", $co['name']);  $r++;
        $ws->setCellValue("A{$r}", $co['tin']);   $r++;
        $ws->setCellValue("A{$r}", $co['addr1']); $r++;
        $ws->setCellValue("A{$r}", $co['addr2']); $r += 2;

        $ws->setCellValue("A{$r}", "For the period covering: {$from} — {$to}"); $r += 2;
        $ws->fromArray(['', '', '', '', '', 'DEBIT', 'CREDIT'], null, "A{$r}"); $r++;

        foreach (range('A','G') as $col) $ws->getColumnDimension($col)->setWidth(18);

        $done = 0;

        DB::table('cash_disbursement as r')
            ->selectRaw("
                r.id,
                to_char(r.disburse_date,'MM/DD/YYYY') as disburse_date,
                r.cd_no,
                r.check_ref_no,
                r.explanation,
                COALESCE(b.acct_desc, r.bank_id) as bank_name,
                COALESCE(v.vend_name, r.vend_id) as vend_name,
                json_agg(json_build_object(
                    'acct_code', d.acct_code,
                    'acct_desc', COALESCE(a.acct_desc,''),
                    'debit', d.debit,
                    'credit', d.credit
                ) ORDER BY d.id) as lines
            ")
            ->leftJoin('account_code as b', function ($j) use ($cid, $acctHasCompany) {
                $j->on('b.acct_code', '=', 'r.bank_id');
                if ($acctHasCompany) $j->where('b.company_id', '=', $cid);
            })
            ->join('cash_disbursement_details as d', function ($j) {
                $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
            })
            ->leftJoin('account_code as a', function ($j) use ($cid, $acctHasCompany) {
                $j->on('a.acct_code', '=', 'd.acct_code');
                if ($acctHasCompany) $j->where('a.company_id', '=', $cid);
            })
            ->leftJoin('vendor_list as v', function ($j) use ($cid, $vendHasCompany) {
                $j->on('v.vend_code', '=', 'r.vend_id');
                if ($vendHasCompany) $j->where('v.company_id', '=', $cid);
            })
            ->where('r.company_id', $cid)
            ->whereBetween('r.disburse_date', [$this->startDate, $this->endDate])
            ->groupBy('r.id','r.disburse_date','r.cd_no','r.check_ref_no','r.explanation','b.acct_desc','v.vend_name','r.vend_id','r.bank_id')
            ->orderBy('r.id')
            ->chunk(200, function($chunk) use (&$r, $ws, &$done, $progress) {
                foreach ($chunk as $row) {
                    $done++;
                    $progress($done);

                    $cdbId = 'APMC-' . str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                    $ws->setCellValue("A{$r}", $row->disburse_date);
                    $ws->setCellValue("B{$r}", $cdbId); $r++;

                    $ws->setCellValue("A{$r}", "CV# {$row->cd_no}");
                    $ws->setCellValue("B{$r}", "Check#: {$row->check_ref_no} — {$row->bank_name}"); $r++;

                    $ws->setCellValue("A{$r}", $row->vend_name);
                    $ws->setCellValue("B{$r}", $row->explanation); $r++;

                    $itemDebit = 0.0;
                    $itemCredit = 0.0;

                    foreach (json_decode($row->lines, true) ?: [] as $ln) {
                        $debit  = (float)($ln['debit'] ?? 0);
                        $credit = (float)($ln['credit'] ?? 0);

                        $itemDebit  += $debit;
                        $itemCredit += $credit;

                        $ws->setCellValue("A{$r}", $ln['acct_code'] ?? '');
                        $ws->setCellValue("B{$r}", $ln['acct_desc'] ?? '');
                        $ws->setCellValue("F{$r}", $debit);
                        $ws->setCellValue("G{$r}", $credit);
                        $ws->getStyle("F{$r}:G{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                        $r++;
                    }

                    $ws->setCellValue("E{$r}", 'TOTAL');
                    $ws->setCellValue("F{$r}", $itemDebit);
                    $ws->setCellValue("G{$r}", $itemCredit);
                    $ws->getStyle("F{$r}:G{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
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
