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

class BuildCheckRegister implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $ticket,
        public int $month,
        public int $year,
        public string $format,      // pdf|excel
        public ?int $companyId,
        public ?int $userId
    ) {}

    private function patch(array $patch): void
    {
        $k = "cr:{$this->ticket}";
        $cur = Cache::get($k) ?? [];
        Cache::put($k, array_merge($cur, $patch), now()->addHours(2));
    }

    private function prune(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local');
        $files = collect($disk->files('reports'))
            ->filter(fn($p) => str_starts_with(basename($p), 'check_register_'))
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
        ]);

        try {
            $start = Carbon::create($this->year, $this->month, 1)->startOfDay()->toDateString();
            $end   = Carbon::create($this->year, $this->month, 1)->endOfMonth()->toDateString();

            $count = DB::table('cash_disbursement as r')
                ->when($this->companyId, fn($q) => $q->where('r.company_id', $this->companyId))
                ->whereBetween('r.disburse_date', [$start, $end])
                ->count();

            $progress = function (int $done) use ($count) {
                $pct = $count ? min(99, (int) floor(($done / max(1, $count)) * 98) + 1) : 50;
                $this->patch(['progress' => $pct]);
            };

            $dir = 'reports';
            if (!Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->makeDirectory($dir);
            }
            $stamp = now()->format('Ymd_His');
            $path  = $this->format === 'pdf'
                ? "$dir/check_register_{$stamp}.pdf"
                : "$dir/check_register_{$stamp}.xls";

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

    /** Build PDF via TCPDF. */
private function buildPdf(string $file, string $start, string $end, callable $progress): void
{
    $pdf = new \TCPDF('L','mm','LETTER', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 10);

    // <<< tighten the vertical spacing >>>
    $pdf->setCellPadding(0);          // no extra padding around cells
    $pdf->setCellHeightRatio(1.0);    // default is ~1.25; smaller = tighter
    // <<< -------------------------------- >>>

    $pdf->SetFont('helvetica', '', 8);

    $monthDesc = \Illuminate\Support\Carbon::parse($start)->isoFormat('MMMM');
    $yearDesc  = \Illuminate\Support\Carbon::parse($start)->year;

    $addHeader = function() use ($pdf, $monthDesc, $yearDesc) {
        $pdf->AddPage();
        $pdf->writeHTML(
            '<h2 style="margin:0;">CHECK REGISTER</h2>'.
            '<div style="margin:0 0 4px 0;"><b>For the Month of '.$monthDesc.' '.$yearDesc.'</b></div>'.
            // column header
            '<table width="100%" border="1" cellpadding="3" cellspacing="0" style="border-collapse:collapse;line-height:1;">
                <tr>
                  <td width="8%"  align="center"><b>Date</b></td>
                  <td width="10%" align="center"><b>Check Voucher</b></td>
                  <td width="12%" align="center"><b>Bank Account</b></td>
                  <td width="22%" align="center" colspan="2"><b>Vendor</b></td>
                  <td width="28%" align="center" colspan="2"><b>Particular</b></td>
                  <td width="10%" align="center"><b>Check Number</b></td>
                  <td width="10%" align="center"><b>Amount</b></td>
                </tr>
              </table>',
            true, false, false, false, ''
        );
    };

    $addHeader();

    // Keep one data table open per page (eliminates per-row block spacing)
    $openRowsTable = function() {
        return '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;line-height:1;"><tbody>';
    };
    $rowsHtml = $openRowsTable();

    $pageTotal  = 0.0;
    $grandTotal = 0.0;
    $linesOnPage = 0;
    $done = 0;

    DB::table('cash_disbursement as r')
        ->selectRaw("
            to_char(r.disburse_date,'MM/DD/YYYY') as disburse_date,
            r.cd_no,
            r.disburse_amount,
            r.check_ref_no,
            r.explanation,
            b.bank_account_number,
            COALESCE(v.vend_name, r.vend_id) as vend_name
        ")
        ->leftJoin('bank as b','b.bank_id','=','r.bank_id')
        ->leftJoin('vendor_list as v','v.vend_code','=','r.vend_id')
        ->when($this->companyId, fn($q)=>$q->where('r.company_id',$this->companyId))
        ->whereBetween('r.disburse_date', [$start, $end])
        ->orderBy('r.disburse_date')->orderBy('r.cd_no')
        ->chunk(300, function($chunk) use (&$rowsHtml, $openRowsTable, $addHeader, $pdf, &$pageTotal, &$grandTotal, &$linesOnPage, &$done, $progress) {
            foreach ($chunk as $row) {
                $amt = (float)$row->disburse_amount;
                $pageTotal  += $amt;
                $grandTotal += $amt;
                $linesOnPage++;

                $rowsHtml .= sprintf(
                    '<tr>
                       <td width="8%%">%s</td>
                       <td width="10%%">%s</td>
                       <td width="12%%">%s</td>
                       <td width="22%%" colspan="2">%s</td>
                       <td width="28%%" colspan="2">%s</td>
                       <td width="10%%">%s</td>
                       <td width="10%%" align="right">%s</td>
                     </tr>',
                    e($row->disburse_date ?? ''),
                    e($row->cd_no ?? ''),
                    e($row->bank_account_number ?? ''),
                    e($row->vend_name ?? ''),
                    e($row->explanation ?? ''),
                    e($row->check_ref_no ?? ''),
                    number_format($amt, 2)
                );

                // Hard page break every ~40 lines
                if ($linesOnPage >= 40) {
                    $rowsHtml .= '</tbody></table>';
                    $pdf->writeHTML($rowsHtml, true, false, false, false, '');

                    $pdf->writeHTML(
                        '<table width="100%" cellpadding="0" cellspacing="0" style="line-height:1;">
                           <tr><td align="right"><b>PAGE TOTAL AMOUNT: '.number_format($pageTotal,2).'</b></td></tr>
                         </table>',
                        true, false, false, false, ''
                    );

                    // reset for next page
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

    \Illuminate\Support\Facades\Storage::disk('local')->put($file, $pdf->Output('check-register.pdf', 'S'));
}


    /** Build Excel via PhpSpreadsheet. */
    private function buildExcel(string $file, string $start, string $end, callable $progress): void
    {
        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Check Register');

        $monthDesc = \Illuminate\Support\Carbon::parse($start)->isoFormat('MMMM');
        $yearDesc  = \Illuminate\Support\Carbon::parse($start)->year;

        $r = 1;
        $ws->setCellValue("A{$r}", 'CHECK REGISTER'); $r++;
        $ws->setCellValue("A{$r}", "For the Month of {$monthDesc} {$yearDesc}"); $r += 2;
        $ws->fromArray(['Date','Check Voucher','Bank Account','Vendor','','Particular','','Check Number','Amount'], null, "A{$r}");
        $ws->getStyle("A{$r}:I{$r}")->getFont()->setBold(true); $r++;

        foreach (range('A','I') as $col) $ws->getColumnDimension($col)->setWidth(18);

        $pageTotal = 0.0; $grandTotal = 0.0; $linesOnPage = 0; $done = 0;

        DB::table('cash_disbursement as r')
            ->selectRaw("
                to_char(r.disburse_date,'MM/DD/YYYY') as disburse_date,
                r.cd_no,
                r.disburse_amount,
                r.check_ref_no,
                r.explanation,
                b.bank_account_number,
                COALESCE(v.vend_name, r.vend_id) as vend_name
            ")
            ->leftJoin('bank as b','b.bank_id','=','r.bank_id')
            ->leftJoin('vendor_list as v','v.vend_code','=','r.vend_id')
            ->when($this->companyId, fn($q)=>$q->where('r.company_id',$this->companyId))
            ->whereBetween('r.disburse_date', [$start, $end])
            ->orderBy('r.disburse_date')->orderBy('r.cd_no')
            ->chunk(300, function($chunk) use (&$r, $ws, &$pageTotal, &$grandTotal, &$linesOnPage, &$done, $progress) {
                foreach ($chunk as $row) {
                    $amt = (float)$row->disburse_amount;
                    $pageTotal  += $amt;
                    $grandTotal += $amt;
                    $linesOnPage++;

                    $ws->fromArray([
                        $row->disburse_date ?? '',
                        $row->cd_no ?? '',
                        $row->bank_account_number ?? '',
                        $row->vend_name ?? '', '', // keep layout similar to PDF (merged look)
                        $row->explanation ?? '', '',
                        $row->check_ref_no ?? '',
                        $amt
                    ], null, "A{$r}");
                    $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $r++;

                    if ($linesOnPage >= 45) {
                        $ws->setCellValue("H{$r}", 'PAGE TOTAL AMOUNT:');
                        $ws->setCellValue("I{$r}", $pageTotal);
                        $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                        $r += 2;
                        $linesOnPage = 0; $pageTotal = 0.0;

                        // repeat header
                        $ws->fromArray(['Date','Check Voucher','Bank Account','Vendor','','Particular','','Check Number','Amount'], null, "A{$r}");
                        $ws->getStyle("A{$r}:I{$r}")->getFont()->setBold(true);
                        $r++;
                    }

                    $done++; $progress($done);
                }
            });

        // final totals
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
