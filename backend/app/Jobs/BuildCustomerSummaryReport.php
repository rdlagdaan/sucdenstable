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

class BuildCustomerSummaryReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public string $ticket,
        public string $startDate,   // YYYY-MM-DD
        public string $endDate,     // YYYY-MM-DD
        public string $custId,
        public string $format,      // 'pdf'|'xls' (normalized)
        public ?int $companyId,
        public ?int $userId
    ) {}

    private function key(): string
    {
        // controller uses "csr:$ticket"
        return "csr:{$this->ticket}";
    }

private function patchState(array $patch): void
{
    // ✅ match working modules: default Cache store
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
        return "customer_summary_c{$cid}_";
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

    private function resolveCustomerName(int $cid, string $custId): string
    {
        // customer_list.company_id is varchar in your schema, so compare loosely
        $q = DB::table('customer_list')
            ->where('cust_id', $custId);

        if (Schema::hasColumn('customer_list', 'company_id')) {
            $q->where('company_id', (string)$cid);
        }

        return (string)($q->value('cust_name') ?? $custId);
    }

    private function excludedCancelValues(): array
    {
        // cover your known cancel/delete variants across modules
        return ['c', 'y', 'd'];
    }

    /**
     * Unified dataset from cash_receipts + cash_sales
     * Columns:
     * txn_date, cust_id, particulars, ref_no, rv_no, sj_no, amount, src
     */
/**
 * Unified dataset from cash_receipts + cash_sales
 * Columns:
 * txn_date, cust_id, particulars, ref_no, rv_no, sj_no, amount, src
 *
 * ✅ FIX: Amount fallback order:
 *  - use header amount if > 0
 *  - else use header totals (sum_credit / sum_debit if present)
 *  - else compute from details (sum credit)
 */
private function baseQuery(int $cid, string $start, string $end, string $custId)
{
    $bad = $this->excludedCancelValues();

    // ---- CASH RECEIPTS ----
    $cr = DB::table('cash_receipts as h')
        ->selectRaw("
            h.receipt_date as txn_date,
            h.cust_id      as cust_id,
            h.details      as particulars,
            h.cr_no        as ref_no,
            h.collection_receipt as rv_no,
            NULL           as sj_no,

            COALESCE(
                NULLIF(h.receipt_amount, 0),
                NULLIF(COALESCE(h.sum_credit, 0), 0),
                (
                    SELECT COALESCE(SUM(COALESCE(d.credit,0)),0)
                    FROM cash_receipt_details d
                    WHERE CAST(d.transaction_id AS BIGINT) = h.id
                ),
                0
            ) as amount,

            'CR' as src
        ")
        ->where('h.company_id', $cid)
        ->where('h.cust_id', $custId)
        ->whereBetween('h.receipt_date', [$start, $end])
        ->where(function ($w) use ($bad) {
            $w->whereNull('h.is_cancel')
              ->orWhereNotIn('h.is_cancel', $bad);
        });

    // ---- SALES JOURNAL (CASH SALES) ----
    $sj = DB::table('cash_sales as h')
        ->selectRaw("
            h.sales_date   as txn_date,
            h.cust_id      as cust_id,
            h.explanation  as particulars,
            h.si_no        as ref_no,
            NULL           as rv_no,
            h.cs_no        as sj_no,

            COALESCE(
                NULLIF(h.sales_amount, 0),
                NULLIF(COALESCE(h.sum_credit, 0), 0),
                (
                    SELECT COALESCE(SUM(COALESCE(d.credit,0)),0)
                    FROM cash_sales_details d
                    WHERE CAST(d.transaction_id AS BIGINT) = h.id
                ),
                0
            ) as amount,

            'SJ' as src
        ")
        ->where('h.company_id', $cid)
        ->where('h.cust_id', $custId)
        ->whereBetween('h.sales_date', [$start, $end])
        ->where(function ($w) use ($bad) {
            $w->whereNull('h.is_cancel')
              ->orWhereNotIn('h.is_cancel', $bad);
        });

    return $cr->unionAll($sj);
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
            'cust_id'    => $this->custId,
        ]);

        try {
            $custName = $this->resolveCustomerName($cid, $this->custId);

            // ensure reports dir
            $disk = Storage::disk('local');
            if (!$disk->exists('reports')) {
                $disk->makeDirectory('reports');
            }

            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $ext   = ($fmt === 'pdf') ? 'pdf' : 'xls';
            $path  = "reports/{$this->filePrefix($cid)}{$stamp}.{$ext}";

            // count rows for progress (wrap union query)
            $count = DB::query()
                ->fromSub($this->baseQuery($cid, $start, $end, $this->custId), 'u')
                ->count();

            $step = function (int $done) use ($count) {
                $pct = $count ? min(99, 1 + (int)floor(($done / max(1, $count)) * 98)) : 50;
                $this->patchState(['progress' => $pct]);
            };

            if ($fmt === 'pdf') {
                $this->buildPdf($path, $cid, $start, $end, $custName, $step);
            } else {
                $this->buildExcel($path, $cid, $start, $end, $custName, $step);
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
private function buildPdf(string $file, int $cid, string $start, string $end, string $custName, callable $progress): void
{
    $from = Carbon::parse($start)->format('m/d/Y');
    $to   = Carbon::parse($end)->format('m/d/Y');

    $pdf = new class($from, $to, $custName) extends \TCPDF {

        // header height reservation (mm) — MUST match what Header() draws
        private int $headerReserve = 48;

        public function __construct(
            public string $from,
            public string $to,
            public string $custName
        ) {
            parent::__construct('L', 'mm', 'LETTER', true, 'UTF-8', false);

            // Reserve enough top margin so body never overlaps Header()
            $this->SetMargins(10, $this->headerReserve, 10);
            $this->SetHeaderMargin(0);
            $this->SetAutoPageBreak(true, 10);

            $this->SetFont('helvetica', '', 8);
        }

        public function Header()
        {
            // draw from a fixed Y, independent of margins
            $this->SetY(10);

            // Title
            $this->SetFont('helvetica', 'B', 14);
            $this->SetTextColor(11, 95, 165);
            $this->Cell(0, 7, 'CUSTOMER SUMMARY REPORT', 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);

            $this->Ln(1);
            $this->SetFont('helvetica', '', 8);

            // Date Period + Customer (boxed)
            $this->SetDrawColor(0, 0, 0);
// ✅ add padding so header info text is not touching borders
$this->setCellPaddings(2, 2, 2, 2); // left, top, right, bottom

            $this->Cell(32, 7, 'Date Period', 1, 0, 'L');
            $this->Cell(18, 7, 'From:', 1, 0, 'L');
            $this->Cell(60, 7, $this->from, 1, 0, 'L');
            $this->Cell(12, 7, 'To:', 1, 0, 'L');
            $this->Cell(0, 7, $this->to, 1, 1, 'L');

            $this->Cell(32, 7, 'Customer:', 1, 0, 'L');
            $this->Cell(0, 7, $this->custName, 1, 1, 'L');

            // maliit na gap lang para hindi tumama yung top border ng teal header sa line sa taas
$this->Ln(6);
// force the column header to start lower (fix the line crossing the teal header)
$this->SetY($this->GetY() + 2);


            // Column header row (filled)
            $this->SetFillColor(47, 127, 143);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('helvetica', 'B', 8);

            // ✅ WIDTH FIX ONLY:
            // Must fit usable width: ~259mm (LETTER landscape 279mm - 10mm L - 10mm R)
            $w = [22, 60, 80, 24, 20, 20, 33]; // sum = 259
// start teal header at exact left margin to avoid border shifting
$this->SetX(10);

            $this->Cell($w[0], 7, 'Date',        1, 0, 'C', true);
            $this->Cell($w[1], 7, 'Customer',    1, 0, 'C', true);
            $this->Cell($w[2], 7, 'Particulars', 1, 0, 'C', true);
            $this->Cell($w[3], 7, 'REF#',        1, 0, 'C', true);
            $this->Cell($w[4], 7, 'RV#',         1, 0, 'C', true);
            $this->Cell($w[5], 7, 'SJ#',         1, 0, 'C', true);
            $this->Cell($w[6], 7, 'Amount',      1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('helvetica', '', 8);
        }

        private function ensureSpace(float $rowH): void
        {
            if ($this->GetY() + $rowH > $this->PageBreakTrigger) {
                $this->AddPage(); // Header auto redraws
            }
        }

        // row drawer (wrap-aware, no broken borders)
        public function drawRow(array $w, array $data): void
        {
            $hCust = $this->getStringHeight($w[1], (string)$data[1]);
            $hPart = $this->getStringHeight($w[2], (string)$data[2]);
            $rowH  = max(7, $hCust, $hPart);

            $this->ensureSpace($rowH);

            $x = 10; // left margin fixed
            $y = $this->GetY();

            $this->MultiCell($w[0], $rowH, (string)$data[0], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[0];
            $this->MultiCell($w[1], $rowH, (string)$data[1], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[1];
            $this->MultiCell($w[2], $rowH, (string)$data[2], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[2];
            $this->MultiCell($w[3], $rowH, (string)$data[3], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[3];
            $this->MultiCell($w[4], $rowH, (string)$data[4], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[4];
            $this->MultiCell($w[5], $rowH, (string)$data[5], 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'M'); $x += $w[5];

            // Amount (right aligned) — last cell ends the row (ln=1)
            $this->MultiCell($w[6], $rowH, (string)$data[6], 1, 'R', false, 1, $x, $y, true, 0, false, true, $rowH, 'M');
        }
    };

    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    // ✅ WIDTH FIX ONLY (must match Header widths)
    $w = [22, 60, 80, 24, 20, 20, 33];

    $done = 0;
$grandTotal = 0.0;

    DB::query()
        ->fromSub($this->baseQuery($cid, $start, $end, $this->custId), 'u')
        ->orderBy('txn_date', 'asc')
        ->orderBy('ref_no', 'asc')
        ->chunk(500, function ($chunk) use ($pdf, $w, $custName, &$done, $progress, &$grandTotal) {
            foreach ($chunk as $r) {
                $date = $r->txn_date ? Carbon::parse($r->txn_date)->format('d-M-y') : '';
                $amt  = (float)($r->amount ?? 0);
$grandTotal += $amt;

                $pdf->drawRow($w, [
                    $date,
                    $custName,
                    (string)($r->particulars ?? ''),
                    (string)($r->ref_no ?? ''),
                    (string)($r->rv_no ?? ''),
                    (string)($r->sj_no ?? ''),
                    number_format($amt, 2),
                ]);

                $done++;
                $progress($done);
            }
        });
// ---- GRAND TOTAL (last row) ----
$grandLabelH = 8;

// siguraduhin may space; kung wala, new page (header auto redraw)
// ✅ TCPDF-safe page break check (no protected property access)
$pageLimitY = $pdf->getPageHeight() - $pdf->getBreakMargin();

if ($pdf->GetY() + $grandLabelH > $pageLimitY) {
    $pdf->AddPage();
}

// bold total row
$pdf->SetFont('helvetica', 'B', 9);

// label spans A..F (all except Amount)
$pdf->SetX(10);
$pdf->Cell($w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], $grandLabelH, 'GRAND TOTAL', 1, 0, 'R');

// amount in last column
$pdf->Cell($w[6], $grandLabelH, number_format($grandTotal, 2), 1, 1, 'R');

// back to normal font
$pdf->SetFont('helvetica', '', 8);

    Storage::disk('local')->put($file, $pdf->Output('customer-summary.pdf', 'S'));
}





 private function buildExcel(string $file, int $cid, string $start, string $end, string $custName, callable $progress): void
{
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Customer Summary');

    $from = Carbon::parse($start)->format('m/d/Y');
    $to   = Carbon::parse($end)->format('m/d/Y');

    // widths A..G
    $ws->getColumnDimension('A')->setWidth(14); // Date
    $ws->getColumnDimension('B')->setWidth(34); // Customer
    $ws->getColumnDimension('C')->setWidth(36); // Particulars
    $ws->getColumnDimension('D')->setWidth(16); // REF#
    $ws->getColumnDimension('E')->setWidth(14); // RV#
    $ws->getColumnDimension('F')->setWidth(14); // SJ#
    $ws->getColumnDimension('G')->setWidth(16); // Amount

    $r = 1;

    // Title
    $ws->setCellValue("A{$r}", 'CUSTOMER SUMMARY REPORT');
    $ws->mergeCells("A{$r}:G{$r}");
    $ws->getStyle("A{$r}")->getFont()->setBold(true)->setSize(14);
    $r += 2;

    // Date period block
    $ws->setCellValue("A{$r}", 'Date Period');
    $ws->setCellValue("B{$r}", 'From:');
    $ws->setCellValue("C{$r}", $from);
    $ws->setCellValue("D{$r}", 'To:');
    $ws->setCellValue("E{$r}", $to);
    $ws->mergeCells("E{$r}:G{$r}");
    $r++;

    $ws->setCellValue("A{$r}", 'Customer:');
    $ws->setCellValue("B{$r}", $custName);
    $ws->mergeCells("B{$r}:G{$r}");
    $r += 2;

    // Column headers
    $headerRow = $r;
    $ws->fromArray(['Date','Customer','Particulars','REF#','RV#','SJ#','Amount'], null, "A{$r}");
    $ws->getStyle("A{$r}:G{$r}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle("A{$r}:G{$r}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF2F7F8F');
    $ws->getStyle("A{$r}:G{$r}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $r++;

    $done = 0;
$grandTotal = 0.0;

    DB::query()
        ->fromSub($this->baseQuery($cid, $start, $end, $this->custId), 'u')
        ->orderBy('txn_date', 'asc')
        ->orderBy('ref_no', 'asc')
        ->chunk(500, function($chunk) use (&$r, $ws, $custName, &$done, $progress, &$grandTotal) {
            foreach ($chunk as $x) {
                $date = $x->txn_date ? Carbon::parse($x->txn_date)->format('d-M-y') : '';
                $amt  = (float)($x->amount ?? 0);
$grandTotal += $amt;

                // write cells explicitly (para sure numeric si Amount)
                $ws->setCellValue("A{$r}", $date);
                $ws->setCellValue("B{$r}", $custName);
                $ws->setCellValue("C{$r}", (string)($x->particulars ?? ''));
                $ws->setCellValue("D{$r}", (string)($x->ref_no ?? ''));
                $ws->setCellValue("E{$r}", (string)($x->rv_no ?? ''));
                $ws->setCellValue("F{$r}", (string)($x->sj_no ?? ''));
                $ws->setCellValueExplicit(
                    "G{$r}",
                    $amt,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                );

                $ws->getStyle("G{$r}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                $ws->getStyle("G{$r}")
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

                $r++;
                $done++;
                $progress($done);
            }
        });
// ---- GRAND TOTAL (last row) ----
$ws->setCellValue("A{$r}", ''); // keep date blank
$ws->setCellValue("B{$r}", ''); // keep customer blank
$ws->setCellValue("C{$r}", ''); // keep particulars blank
$ws->setCellValue("D{$r}", '');
$ws->setCellValue("E{$r}", '');
$ws->setCellValue("F{$r}", 'GRAND TOTAL');

$ws->setCellValueExplicit(
    "G{$r}",
    $grandTotal,
    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
);

$ws->getStyle("F{$r}:G{$r}")->getFont()->setBold(true);
$ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
$ws->getStyle("G{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
$ws->getStyle("F{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

$r++; // advance row pointer so borders include this total row

    // borders for the table area (from header row to last row)
    $lastDataRow = $r - 1;
    if ($lastDataRow >= $headerRow) {
        $ws->getStyle("A{$headerRow}:G{$lastDataRow}")
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
