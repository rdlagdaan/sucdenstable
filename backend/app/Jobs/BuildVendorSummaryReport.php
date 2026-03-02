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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BuildVendorSummaryReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public string $ticket,
        public string $startDate,   // YYYY-MM-DD
        public string $endDate,     // YYYY-MM-DD
        public string $vendId,
        public string $format,      // 'pdf'|'xls' (normalized)
        public ?int $companyId,
        public ?int $userId
    ) {}

    private function key(): string
    {
        return "vsr:{$this->ticket}";
    }

    private function patchState(array $patch): void
    {
        $cur = Cache::get($this->key()) ?? [];
        Cache::put($this->key(), array_merge($cur, $patch), now()->addHours(2));
    }

    private function requireCompanyId(): int
    {
        $cid = (int) ($this->companyId ?? 0);
        if ($cid <= 0) {
            throw new \RuntimeException('Missing company_id. Refusing to generate unscoped report.');
        }
        return $cid;
    }

    private function normalizeFormat(): string
    {
        $fmt = strtolower(trim((string)$this->format));
        if ($fmt === 'excel' || $fmt === 'xlsx') $fmt = 'xls';
        if (!in_array($fmt, ['pdf', 'xls'], true)) $fmt = 'pdf';
        return $fmt;
    }

    private function filePrefix(int $cid): string
    {
        return "vendor_summary_c{$cid}_";
    }

    private function pruneOldReports(string $keepFile, int $cid, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk   = Storage::disk('local');
        $prefix = $this->filePrefix($cid);

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

    private function resolveVendorName(int $cid, string $vendId): string
    {
        // vendor_list uses vend_code; your cash_* tables store vend_id
        $q = DB::table('vendor_list')->where('vend_code', $vendId);

        if (Schema::hasColumn('vendor_list', 'company_id')) {
            $q->where('company_id', $cid);
        }

        return (string)($q->value('vend_name') ?? $vendId);
    }

    private function excludedCancelValues(): array
    {
        // cover known cancel/delete variants across modules
        return ['c', 'y', 'd'];
    }

    /**
     * Unified dataset from cash_disbursement + cash_purchase
     * Columns:
     * txn_date, vend_id, particulars, ref_no, cv_no, pj_no, amount, src
     */
    private function baseQuery(int $cid, string $start, string $end, string $vendId)
    {
        $bad = $this->excludedCancelValues();

        // ---- CASH DISBURSEMENT ----
        $cd = DB::table('cash_disbursement as h')
            ->selectRaw("
                h.disburse_date as txn_date,
                h.vend_id       as vend_id,
                h.explanation   as particulars,
                h.cd_no         as ref_no,
                h.cd_no         as cv_no,
                NULL            as pj_no,

                COALESCE(
                    NULLIF(h.disburse_amount, 0),
                    NULLIF(COALESCE(h.sum_debit, 0), 0),
                    (
                        SELECT COALESCE(SUM(COALESCE(d.debit,0)),0)
                        FROM cash_disbursement_details d
                        WHERE CAST(d.transaction_id AS BIGINT) = h.id
                    ),
                    0
                ) as amount,

                'CD' as src
            ")
            ->where('h.company_id', $cid)
            ->where('h.vend_id', $vendId)
            ->whereBetween('h.disburse_date', [$start, $end])
            ->where(function ($w) use ($bad) {
                $w->whereNull('h.is_cancel')
                  ->orWhereNotIn('h.is_cancel', $bad);
            });

        // ---- PURCHASE JOURNAL ----
        $cp = DB::table('cash_purchase as h')
            ->selectRaw("
                h.purchase_date as txn_date,
                h.vend_id       as vend_id,
                h.explanation   as particulars,
                h.cp_no         as ref_no,
                NULL            as cv_no,
                h.cp_no         as pj_no,

                COALESCE(
                    NULLIF(h.purchase_amount, 0),
                    NULLIF(COALESCE(h.sum_debit, 0), 0),
                    (
                        SELECT COALESCE(SUM(COALESCE(d.debit,0)),0)
                        FROM cash_purchase_details d
                        WHERE CAST(d.transaction_id AS BIGINT) = h.id
                    ),
                    0
                ) as amount,

                'PJ' as src
            ")
            ->where('h.company_id', $cid)
            ->where('h.vend_id', $vendId)
            ->whereBetween('h.purchase_date', [$start, $end])
            ->where(function ($w) use ($bad) {
                $w->whereNull('h.is_cancel')
                  ->orWhereNotIn('h.is_cancel', $bad);
            });

        return $cd->unionAll($cp);
    }

    public function handle(): void
    {
        $cid = $this->requireCompanyId();
        $fmt = $this->normalizeFormat();

        $start = Carbon::parse($this->startDate)->toDateString();
        $end   = Carbon::parse($this->endDate)->toDateString();

        $this->patchState([
            'status'     => 'running',
            'progress'   => 1,
            'file'       => null,
            'error'      => null,
            'format'     => $fmt,
            'company_id' => $cid,
            'user_id'    => $this->userId,
            'start_date' => $start,
            'end_date'   => $end,
            'vend_id'    => $this->vendId,
        ]);

        try {
            $vendName = $this->resolveVendorName($cid, $this->vendId);

            $disk = Storage::disk('local');
            if (!$disk->exists('reports')) {
                $disk->makeDirectory('reports');
            }

            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $ext   = ($fmt === 'pdf') ? 'pdf' : 'xls';
            $path  = "reports/{$this->filePrefix($cid)}{$stamp}.{$ext}";

            $count = DB::query()
                ->fromSub($this->baseQuery($cid, $start, $end, $this->vendId), 'u')
                ->count();

            $step = function (int $done) use ($count) {
                $pct = $count ? min(99, 1 + (int)floor(($done / max(1, $count)) * 98)) : 50;
                $this->patchState(['progress' => $pct]);
            };

            if ($fmt === 'pdf') {
                $this->buildPdf($path, $cid, $start, $end, $vendName, $step);
            } else {
                $this->buildExcel($path, $cid, $start, $end, $vendName, $step);
            }

            $this->pruneOldReports($path, $cid, sameFormatOnly: true, keep: 1);

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

    /** ---------- Writers ---------- */
    private function buildPdf(string $file, int $cid, string $start, string $end, string $vendName, callable $progress): void
    {
        $from = Carbon::parse($start)->format('m/d/Y');
        $to   = Carbon::parse($end)->format('m/d/Y');

        $pdf = new class($from, $to, $vendName) extends \TCPDF {

            private int $headerReserve = 48;

            public function __construct(
                public string $from,
                public string $to,
                public string $vendName
            ) {
                parent::__construct('L', 'mm', 'LETTER', true, 'UTF-8', false);

                $this->SetMargins(10, $this->headerReserve, 10);
                $this->SetHeaderMargin(0);
                $this->SetAutoPageBreak(true, 10);
                $this->SetFont('helvetica', '', 8);
            }

            public function Header()
            {
                $this->SetY(10);

                $this->SetFont('helvetica', 'B', 14);
                $this->SetTextColor(11, 95, 165);
                $this->Cell(0, 7, 'VENDOR SUMMARY REPORT', 0, 1, 'L');
                $this->SetTextColor(0, 0, 0);

                $this->Ln(1);
                $this->SetFont('helvetica', '', 8);

                $this->SetDrawColor(0, 0, 0);
                $this->setCellPaddings(2, 2, 2, 2);

                $this->Cell(32, 7, 'Date Period', 1, 0, 'L');
                $this->Cell(18, 7, 'From:', 1, 0, 'L');
                $this->Cell(60, 7, $this->from, 1, 0, 'L');
                $this->Cell(12, 7, 'To:', 1, 0, 'L');
                $this->Cell(0, 7, $this->to, 1, 1, 'L');

                $this->Cell(32, 7, 'Vendor:', 1, 0, 'L');
                $this->Cell(0, 7, $this->vendName, 1, 1, 'L');

                $this->Ln(6);
                $this->SetY($this->GetY() + 2);

                $this->SetFillColor(47, 127, 143);
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('helvetica', 'B', 8);

                // usable width ≈ 259mm
                $w = [22, 70, 90, 25, 25, 27]; // sum = 259
                $this->SetX(10);

                $this->Cell($w[0], 7, 'Date',        1, 0, 'C', true);
                $this->Cell($w[1], 7, 'Vendor',      1, 0, 'C', true);
                $this->Cell($w[2], 7, 'Particulars', 1, 0, 'C', true);
                $this->Cell($w[3], 7, 'CV#',         1, 0, 'C', true);
                $this->Cell($w[4], 7, 'PJ#',         1, 0, 'C', true);
                $this->Cell($w[5], 7, 'Amount',      1, 1, 'C', true);

                $this->SetTextColor(0, 0, 0);
                $this->SetFont('helvetica', '', 8);
            }

            private function ensureSpace(float $rowH): void
            {
                if ($this->GetY() + $rowH > $this->PageBreakTrigger) {
                    $this->AddPage();
                }
            }

            public function drawRow(array $w, array $data): void
            {
                $hVend = $this->getStringHeight($w[1], (string)$data[1]);
                $hPart = $this->getStringHeight($w[2], (string)$data[2]);
                $rowH  = max(7, $hVend, $hPart);

                $this->ensureSpace($rowH);

                $x = 10;
                $y = $this->GetY();

                $this->MultiCell($w[0], $rowH, (string)$data[0], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[0];
                $this->MultiCell($w[1], $rowH, (string)$data[1], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[1];
                $this->MultiCell($w[2], $rowH, (string)$data[2], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[2];
                $this->MultiCell($w[3], $rowH, (string)$data[3], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[3];
                $this->MultiCell($w[4], $rowH, (string)$data[4], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[4];

                $this->MultiCell($w[5], $rowH, (string)$data[5], 1, 'R', false, 1, $x, $y, true, 0, false, true, $rowH, 'M');
            }
        };

        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $w = [22, 70, 90, 25, 25, 27];

        $done = 0;
        $grandTotal = 0.0;

        DB::query()
            ->fromSub($this->baseQuery($cid, $start, $end, $this->vendId), 'u')
            ->orderBy('txn_date', 'asc')
            ->orderBy('ref_no', 'asc')
            ->chunk(500, function ($chunk) use ($pdf, $w, $vendName, &$done, $progress, &$grandTotal) {
                foreach ($chunk as $r) {
                    $date = $r->txn_date ? Carbon::parse($r->txn_date)->format('d-M-y') : '';
                    $amt  = (float)($r->amount ?? 0);
                    $grandTotal += $amt;

                    $pdf->drawRow($w, [
                        $date,
                        $vendName,
                        (string)($r->particulars ?? ''),
                        (string)($r->cv_no ?? ''),
                        (string)($r->pj_no ?? ''),
                        number_format($amt, 2),
                    ]);

                    $done++;
                    $progress($done);
                }
            });

        // grand total row
        $grandLabelH = 8;
        $pageLimitY = $pdf->getPageHeight() - $pdf->getBreakMargin();
        if ($pdf->GetY() + $grandLabelH > $pageLimitY) {
            $pdf->AddPage();
        }

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(10);
        $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3] + $w[4], $grandLabelH, 'GRAND TOTAL', 1, 0, 'R');
        $pdf->Cell($w[5], $grandLabelH, number_format($grandTotal, 2), 1, 1, 'R');
        $pdf->SetFont('helvetica', '', 8);

        Storage::disk('local')->put($file, $pdf->Output('vendor-summary.pdf', 'S'));
    }

    private function buildExcel(string $file, int $cid, string $start, string $end, string $vendName, callable $progress): void
    {
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Vendor Summary');

        $from = Carbon::parse($start)->format('m/d/Y');
        $to   = Carbon::parse($end)->format('m/d/Y');

        // A..F
        $ws->getColumnDimension('A')->setWidth(14); // Date
        $ws->getColumnDimension('B')->setWidth(38); // Vendor
        $ws->getColumnDimension('C')->setWidth(44); // Particulars
        $ws->getColumnDimension('D')->setWidth(16); // CV#
        $ws->getColumnDimension('E')->setWidth(16); // PJ#
        $ws->getColumnDimension('F')->setWidth(16); // Amount

        $r = 1;

        $ws->setCellValue("A{$r}", 'VENDOR SUMMARY REPORT');
        $ws->mergeCells("A{$r}:F{$r}");
        $ws->getStyle("A{$r}")->getFont()->setBold(true)->setSize(14);
        $r += 2;

        $ws->setCellValue("A{$r}", 'Date Period');
        $ws->setCellValue("B{$r}", 'From:');
        $ws->setCellValue("C{$r}", $from);
        $ws->setCellValue("D{$r}", 'To:');
        $ws->setCellValue("E{$r}", $to);
        $ws->mergeCells("E{$r}:F{$r}");
        $r++;

        $ws->setCellValue("A{$r}", 'Vendor:');
        $ws->setCellValue("B{$r}", $vendName);
        $ws->mergeCells("B{$r}:F{$r}");
        $r += 2;

        $headerRow = $r;
        $ws->fromArray(['Date','Vendor','Particulars','CV#','PJ#','Amount'], null, "A{$r}");
        $ws->getStyle("A{$r}:F{$r}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $ws->getStyle("A{$r}:F{$r}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF2F7F8F');
        $ws->getStyle("A{$r}:F{$r}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $r++;

        $done = 0;
        $grandTotal = 0.0;

        DB::query()
            ->fromSub($this->baseQuery($cid, $start, $end, $this->vendId), 'u')
            ->orderBy('txn_date', 'asc')
            ->orderBy('ref_no', 'asc')
            ->chunk(500, function($chunk) use (&$r, $ws, $vendName, &$done, $progress, &$grandTotal) {
                foreach ($chunk as $x) {
                    $date = $x->txn_date ? Carbon::parse($x->txn_date)->format('d-M-y') : '';
                    $amt  = (float)($x->amount ?? 0);
                    $grandTotal += $amt;

                    $ws->setCellValue("A{$r}", $date);
                    $ws->setCellValue("B{$r}", $vendName);
                    $ws->setCellValue("C{$r}", (string)($x->particulars ?? ''));
                    $ws->setCellValue("D{$r}", (string)($x->cv_no ?? ''));
                    $ws->setCellValue("E{$r}", (string)($x->pj_no ?? ''));
                    $ws->setCellValueExplicit(
                        "F{$r}",
                        $amt,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                    );

                    $ws->getStyle("F{$r}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');

                    $ws->getStyle("F{$r}")
                        ->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

                    $r++;
                    $done++;
                    $progress($done);
                }
            });

        // GRAND TOTAL
        $ws->setCellValue("E{$r}", 'GRAND TOTAL');
        $ws->setCellValueExplicit(
            "F{$r}",
            $grandTotal,
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
        );
        $ws->getStyle("E{$r}:F{$r}")->getFont()->setBold(true);
        $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("E{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        $r++;

        $lastDataRow = $r - 1;
        if ($lastDataRow >= $headerRow) {
            $ws->getStyle("A{$headerRow}:F{$lastDataRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        }

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
