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
use Throwable;

class BuildReceiptRegister implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min

    public function __construct(
        public string $ticket,
        public int $month,
        public int $year,
        public string $format,      // 'pdf' | 'excel' (controller normalizes to 'pdf'|'excel')
        public ?int $companyId,
        public ?int $userId,
        public ?string $query = null
    ) {}

    private function key(): string { return "rr:{$this->ticket}"; }

    private function patch(array $patch): void
    {
        $cur = Cache::get($this->key()) ?? [];
        Cache::put($this->key(), array_merge($cur, $patch), now()->addHours(2));
    }

    private function prune(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local');
        $files = collect($disk->files('reports'))
            ->filter(fn($p) => str_starts_with(basename($p), 'receipt_register_'))
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
        $this->patch([
            'status'   => 'running',
            'progress' => 1,
            'file'     => null,
            'error'    => null,
            'period'   => [$this->month, $this->year],
            'format'   => $this->format,
            'query'    => $this->query,
            'user_id'  => $this->userId,
            'company_id' => $this->companyId,
        ]);

        try {
            $start = Carbon::create($this->year, $this->month, 1)->startOfDay()->toDateString();
            $end   = Carbon::create($this->year, $this->month, 1)->endOfMonth()->toDateString();

            // Pre-count for deterministic progress
            $count = DB::table('cash_receipts as r')
                ->when($this->companyId, fn($q) => $q->where('r.company_id', $this->companyId))
                ->whereBetween('r.receipt_date', [$start, $end])
                ->when($this->query, function($q,$s){
                    $like = '%'.str_replace('%','\%',$s).'%';
                    $q->leftJoin('customer_list as cx','cx.cust_id','=','r.cust_id')
                      ->where(function($w) use($like){
                        $w->where('cx.cust_name','ILIKE',$like)
                          ->orWhere('r.details','ILIKE',$like)
                          ->orWhere('r.cr_no','ILIKE',$like)
                          ->orWhere('r.collection_receipt','ILIKE',$like)
                          ->orWhere('r.bank_id','ILIKE',$like);
                      });
                })
                ->count();

            $progress = function (int $done) use ($count) {
                $pct = $count ? min(99, (int) floor(($done / max(1, $count)) * 98) + 1) : 50;
                $this->patch(['progress' => $pct]);
            };

            // Optional guard: block if there are unbalanced receipts in the period
            $hasUnbalanced = DB::table('cash_receipts as r')
                ->when($this->companyId, fn($q) => $q->where('r.company_id', $this->companyId))
                ->whereBetween('r.receipt_date', [$start, $end])
                ->where('r.is_balanced', false)
                ->exists();

            if ($hasUnbalanced) {
                $this->patch([
                    'status'   => 'error',
                    'error'    => 'Report blocked: one or more receipts in the selected period are not balanced.',
                    'progress' => 100,
                ]);
                return;
            }

            $dir = 'reports';
            $disk = Storage::disk('local');
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            $stamp = now()->format('Ymd_His');
            $path  = $this->format === 'pdf'
                ? "$dir/receipt_register_{$stamp}.pdf"
                : "$dir/receipt_register_{$stamp}.xls";

            if ($this->format === 'pdf') {
                $this->buildPdf($path, $start, $end, $progress);
            } else {
                $this->buildExcel($path, $start, $end, $progress);
            }

            $this->prune($path, sameFormatOnly: true, keep: 1);

            $this->patch([
                'status'   => 'done',
                'progress' => 100,
                'file'     => $path,
            ]);
        } catch (Throwable $e) {
            $this->patch(['status' => 'error', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** ---------- Writers ---------- */

    private function buildPdf(string $file, string $start, string $end, callable $progress): void
    {
        $pdf = new \TCPDF('L','mm','LETTER', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->setCellPadding(0);
        $pdf->setCellHeightRatio(1.0);
        $pdf->SetFont('helvetica', '', 8);

        $monthDesc = \Illuminate\Support\Carbon::parse($start)->isoFormat('MMMM');
        $yearDesc  = \Illuminate\Support\Carbon::parse($start)->year;

        $addHeader = function() use ($pdf, $monthDesc, $yearDesc) {
            $pdf->AddPage();
            $pdf->writeHTML(
                '<h2 style="margin:0;">RECEIPT REGISTER</h2>'.
                '<div style="margin:0 0 4px 0;"><b>For the Month of '.$monthDesc.' '.$yearDesc.'</b></div>'.
                '<table width="100%" border="1" cellpadding="3" cellspacing="0" style="border-collapse:collapse;line-height:1;">
                    <tr>
                      <td width="8%"  align="center"><b>Date</b></td>
                      <td width="11%" align="center"><b>Receipt Voucher</b></td>
                      <td width="12%" align="center"><b>Bank</b></td>
                      <td width="22%" align="center" colspan="2"><b>Customer</b></td>
                      <td width="28%" align="center" colspan="2"><b>Particular</b></td>
                      <td width="9%"  align="center"><b>Collection Receipt</b></td>
                      <td width="10%" align="center"><b>Amount</b></td>
                    </tr>
                 </table>',
                true, false, false, false, ''
            );
        };

        $addHeader();

        $openRowsTable = fn() => '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;line-height:1;"><tbody>';
        $rowsHtml = $openRowsTable();

        $pageTotal  = 0.0;
        $grandTotal = 0.0;
        $linesOnPage = 0;
        $done = 0;

        DB::table('cash_receipts as r')
            ->selectRaw("
                to_char(r.receipt_date,'MM/DD/YYYY') as receipt_date,
                r.cr_no,
                COALESCE(r.bank_id,'') as bank_display,
                COALESCE(c.cust_name, r.cust_id) as cust_name,
                r.details,
                r.collection_receipt,
                r.receipt_amount
            ")
            ->leftJoin('customer_list as c','c.cust_id','=','r.cust_id')
            ->when($this->companyId, fn($q)=>$q->where('r.company_id',$this->companyId))
            ->whereBetween('r.receipt_date', [$start, $end])
            ->when($this->query, function($q,$s){
                $like = '%'.str_replace('%','\%',$s).'%';
                $q->where(function($w) use($like){
                    $w->where('c.cust_name','ILIKE',$like)
                      ->orWhere('r.details','ILIKE',$like)
                      ->orWhere('r.cr_no','ILIKE',$like)
                      ->orWhere('r.collection_receipt','ILIKE',$like)
                      ->orWhere('r.bank_id','ILIKE',$like);
                });
            })
            ->orderBy('r.receipt_date')
            ->orderBy('r.cr_no')
            ->chunk(300, function($chunk) use (&$rowsHtml, $openRowsTable, $addHeader, $pdf, &$pageTotal, &$grandTotal, &$linesOnPage, &$done, $progress) {
                foreach ($chunk as $row) {
                    $amt = (float)$row->receipt_amount;
                    $pageTotal  += $amt;
                    $grandTotal += $amt;
                    $linesOnPage++;

                    $rowsHtml .= sprintf(
                        '<tr>
                           <td width="8%%">%s</td>
                           <td width="11%%">%s</td>
                           <td width="12%%">%s</td>
                           <td width="22%%" colspan="2">%s</td>
                           <td width="28%%" colspan="2">%s</td>
                           <td width="9%%">%s</td>
                           <td width="10%%" align="right">%s</td>
                         </tr>',
                        htmlspecialchars($row->receipt_date ?? '', ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($row->cr_no ?? '', ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($row->bank_display ?? '', ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($row->cust_name ?? '', ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($row->details ?? '', ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($row->collection_receipt ?? '', ENT_QUOTES, 'UTF-8'),
                        number_format($amt, 2)
                    );

                    if ($linesOnPage >= 40) {
                        $rowsHtml .= '</tbody></table>';
                        $pdf->writeHTML($rowsHtml, true, false, false, false, '');
                        $pdf->writeHTML(
                            '<table width="100%" cellpadding="0" cellspacing="0" style="line-height:1;">
                               <tr><td align="right"><b>PAGE TOTAL AMOUNT: '.number_format($pageTotal,2).'</b></td></tr>
                             </table>',
                            true, false, false, false, ''
                        );

                        $linesOnPage = 0;
                        $pageTotal   = 0.0;
                        $addHeader();
                        $rowsHtml = $openRowsTable();
                    }

                    $done++; $progress($done);
                }
            });

        // flush remaining rows + totals
        $rowsHtml .= '</tbody></table>';
        $pdf->writeHTML($rowsHtml, true, false, false, false, '');
        $pdf->writeHTML(
            '<table width="100%" cellpadding="0" cellspacing="0" style="line-height:1;">
               <tr><td align="right"><b>PAGE TOTAL AMOUNT: '.number_format($pageTotal,2).'</b></td></tr>
               <tr><td align="right"><b>GRAND TOTAL AMOUNT: '.number_format($grandTotal,2).'</b></td></tr>
             </table>',
            true, false, false, false, ''
        );

        // Footer
        $pdf->SetY(-16);
        $pdf->writeHTML(
            '<table width="100%" cellpadding="0" cellspacing="0" style="line-height:1;">
               <tr>
                 <td>Print Date: '.now()->format('M d, Y').'</td>
                 <td>Print Time: '.now()->format('h:i:s a').'</td>
                 <td align="right">'.$pdf->getAliasNumPage().'/'.$pdf->getAliasNbPages().'</td>
               </tr>
             </table>',
            true, false, false, false, ''
        );

        Storage::disk('local')->put($file, $pdf->Output('receipt-register.pdf', 'S'));
    }

    private function buildExcel(string $file, string $start, string $end, callable $progress): void
    {
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Receipt Register');

        $monthDesc = \Illuminate\Support\Carbon::parse($start)->isoFormat('MMMM');
        $yearDesc  = \Illuminate\Support\Carbon::parse($start)->year;

        $r = 1;
        $ws->setCellValue("A{$r}", 'RECEIPT REGISTER'); $r++;
        $ws->setCellValue("A{$r}", "For the Month of {$monthDesc} {$yearDesc}"); $r += 2;

        $ws->fromArray(['Date','Receipt Voucher','Bank','Customer','','Particular','','Collection Receipt','Amount'], null, "A{$r}");
        $ws->getStyle("A{$r}:I{$r}")->getFont()->setBold(true); $r++;

        foreach (range('A','I') as $col) $ws->getColumnDimension($col)->setWidth(18);
        $ws->getColumnDimension('D')->setWidth(28);
        $ws->getColumnDimension('E')->setWidth(4);
        $ws->getColumnDimension('F')->setWidth(30);
        $ws->getColumnDimension('G')->setWidth(4);

        $pageTotal = 0.0; $grandTotal = 0.0; $linesOnPage = 0; $done = 0;

        DB::table('cash_receipts as r')
            ->selectRaw("
                to_char(r.receipt_date,'MM/DD/YYYY') as receipt_date,
                r.cr_no,
                COALESCE(r.bank_id,'') as bank_display,
                COALESCE(c.cust_name, r.cust_id) as cust_name,
                r.details,
                r.collection_receipt,
                r.receipt_amount
            ")
            ->leftJoin('customer_list as c','c.cust_id','=','r.cust_id')
            ->when($this->companyId, fn($q)=>$q->where('r.company_id',$this->companyId))
            ->whereBetween('r.receipt_date', [$start, $end])
            ->when($this->query, function($q,$s){
                $like = '%'.str_replace('%','\%',$s).'%';
                $q->where(function($w) use($like){
                    $w->where('c.cust_name','ILIKE',$like)
                      ->orWhere('r.details','ILIKE',$like)
                      ->orWhere('r.cr_no','ILIKE',$like)
                      ->orWhere('r.collection_receipt','ILIKE',$like)
                      ->orWhere('r.bank_id','ILIKE',$like);
                });
            })
            ->orderBy('r.receipt_date')
            ->orderBy('r.cr_no')
            ->chunk(300, function($chunk) use (&$r, $ws, &$pageTotal, &$grandTotal, &$linesOnPage, &$done, $progress) {
                foreach ($chunk as $row) {
                    $amt = (float)$row->receipt_amount;
                    $pageTotal  += $amt;
                    $grandTotal += $amt;
                    $linesOnPage++;

                    $ws->fromArray([
                        $row->receipt_date ?? '',
                        $row->cr_no ?? '',
                        $row->bank_display ?? '',
                        $row->cust_name ?? '', '',
                        $row->details ?? '', '',
                        $row->collection_receipt ?? '',
                        $amt
                    ], null, "A{$r}");
                    $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $ws->getCell("C{$r}")->setValueExplicit((string)($row->bank_display ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                    $r++;

                    if ($linesOnPage >= 45) {
                        $ws->setCellValue("H{$r}", 'PAGE TOTAL AMOUNT:');
                        $ws->setCellValue("I{$r}", $pageTotal);
                        $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                        $r += 2;
                        $linesOnPage = 0; $pageTotal = 0.0;

                        $ws->fromArray(['Date','Receipt Voucher','Bank','Customer','','Particular','','Collection Receipt','Amount'], null, "A{$r}");
                        $ws->getStyle("A{$r}:I{$r}")->getFont()->setBold(true);
                        $r++;
                    }

                    $done++; $progress($done);
                }
            });

        // Totals
        $ws->setCellValue("H{$r}", 'PAGE TOTAL AMOUNT:');
        $ws->setCellValue("I{$r}", $pageTotal);
        $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00'); $r++;

        $ws->setCellValue("H{$r}", 'GRAND TOTAL AMOUNT:');
        $ws->setCellValue("I{$r}", $grandTotal);
        $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($wb);
        $stream = fopen('php://temp', 'r+');
        $writer->save($stream); rewind($stream);
        Storage::disk('local')->put($file, stream_get_contents($stream));
        fclose($stream);
    }
}
