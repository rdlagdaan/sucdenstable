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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Throwable;

class BuildReceiptRegister implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min

    public function __construct(
        public string $ticket,
        public int $month,
        public int $year,
        public string $format,      // 'pdf' | 'xls' (normalized)
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
        // ✅ tenant-safe prefix
        return "receipt_register_c{$cid}_";
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

    public function handle(): void
    {
        $cid = $this->requireCompanyId();
        $fmt = $this->normalizeFormat();

        $this->patch([
            'status'     => 'running',
            'progress'   => 1,
            'file'       => null,
            'error'      => null,
            'period'     => [$this->month, $this->year],
            'format'     => $fmt,
            'query'      => $this->query,
            'user_id'    => $this->userId,
            'company_id' => $cid,
        ]);

        try {
            $start = Carbon::create($this->year, $this->month, 1)->startOfDay()->toDateString();
            $end   = Carbon::create($this->year, $this->month, 1)->endOfMonth()->toDateString();

            // ✅ progress count (tenant-scoped, and does NOT mutate query with extra joins)
            $countQ = DB::table('cash_receipts as r')
                ->where('r.company_id', $cid)
                ->whereBetween('r.receipt_date', [$start, $end]);

            if ($this->query) {
                $needle = '%'.$this->escapeLike($this->query).'%';
                $countQ->where(function ($w) use ($needle, $cid) {
                    $w->where('r.details', 'ILIKE', $needle)
                      ->orWhere('r.cr_no', 'ILIKE', $needle)
                      ->orWhere('r.collection_receipt', 'ILIKE', $needle)
                      ->orWhere('r.bank_id', 'ILIKE', $needle)
                      ->orWhereExists(function ($sq) use ($needle, $cid) {
                          $sq->from('customer_list as c')
                             ->whereRaw('c.cust_id = r.cust_id')
                             ->when(Schema::hasColumn('customer_list', 'company_id'), fn($q) => $q->where('c.company_id', $cid))
                             ->where('c.cust_name', 'ILIKE', $needle);
                      });
                });
            }

            $count = (clone $countQ)->count();

            $progress = function (int $done) use ($count) {
                $pct = $count ? min(99, (int) floor(($done / max(1, $count)) * 98) + 1) : 50;
                $this->patch(['progress' => $pct]);
            };

            // ✅ optional business guard (keep if you want)
            $hasUnbalanced = DB::table('cash_receipts as r')
                ->where('r.company_id', $cid)
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

            // ensure reports dir
            $disk = Storage::disk('local');
            $dir  = 'reports';
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            // ✅ tenant-safe filename + unique stamp
            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $ext   = ($fmt === 'pdf') ? 'pdf' : 'xls';
            $path  = "{$dir}/{$this->filePrefix($cid)}{$stamp}.{$ext}";

            if ($fmt === 'pdf') {
                $this->buildPdf($path, $cid, $start, $end, $progress);
            } else {
                $this->buildExcel($path, $cid, $start, $end, $progress);
            }

            $this->pruneOldReports($path, $cid, sameFormatOnly: true, keep: 1);

            $this->patch([
                'status'   => 'done',
                'progress' => 100,
                'file'     => $path,
            ]);
        } catch (Throwable $e) {
            $this->patch([
                'status'   => 'error',
                'progress' => 100,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** ---------- Writers ---------- */

private function buildPdf(string $file, int $cid, string $start, string $end, callable $progress): void
{
    $pdf = new \TCPDF('L','mm','LETTER', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->setCellPadding(0);
    $pdf->setCellHeightRatio(1.0);
    $pdf->SetFont('helvetica', '', 8);

    $monthDesc = Carbon::parse($start)->isoFormat('MMMM');
    $yearDesc  = Carbon::parse($start)->year;

    // ✅ same simple company header as check register
    $companyName = ($cid === 2) ? 'AMEROP PHILIPPINES, INC' : 'SUCDEN PHILIPPINES, INC';

    $addHeader = function() use ($pdf, $monthDesc, $yearDesc, $companyName) {
        $pdf->AddPage();
        $pdf->writeHTML(
            '<div style="text-align:right; margin:0;"><b>'.htmlspecialchars($companyName, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</b></div>'.
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

    $pageTotal   = 0.0;
    $grandTotal  = 0.0;
    $linesOnPage = 0;
    $done        = 0;

    $q = DB::table('cash_receipts as r')
        ->selectRaw("
            to_char(r.receipt_date,'MM/DD/YYYY') as receipt_date,
            r.cr_no,
            r.details,
            r.collection_receipt,
            r.receipt_amount,
            COALESCE(c.cust_name, r.cust_id) as cust_name,
            COALESCE(b.bank_name, r.bank_id) as bank_name
        ")
        ->leftJoin('customer_list as c', function ($j) use ($cid) {
            $j->on('c.cust_id', '=', 'r.cust_id');
            if (Schema::hasColumn('customer_list', 'company_id')) {
                $j->where('c.company_id', '=', $cid);
            }
        })
        ->leftJoin('bank as b', function ($j) use ($cid) {
            $j->on('b.bank_id', '=', 'r.bank_id');
            if (Schema::hasColumn('bank', 'company_id')) {
                $j->where('b.company_id', '=', $cid);
            }
        })
        ->where('r.company_id', $cid)
        ->whereBetween('r.receipt_date', [$start, $end]);

    if ($this->query) {
        $needle = '%'.$this->escapeLike($this->query).'%';
        $q->where(function($w) use($needle) {
            $w->where('c.cust_name','ILIKE',$needle)
              ->orWhere('r.details','ILIKE',$needle)
              ->orWhere('r.cr_no','ILIKE',$needle)
              ->orWhere('r.collection_receipt','ILIKE',$needle)
              ->orWhere('r.bank_id','ILIKE',$needle)
              ->orWhere('b.bank_name','ILIKE',$needle);
        });
    }

    $q->orderBy('r.cr_no', 'asc')
      ->orderBy('r.receipt_date', 'asc')
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
                  e($row->receipt_date ?? ''),
                  e($row->cr_no ?? ''),
                  e($row->bank_name ?? ''),
                  e($row->cust_name ?? ''),
                  e($row->details ?? ''),
                  e($row->collection_receipt ?? ''),
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

    $rowsHtml .= '</tbody></table>';
    $pdf->writeHTML($rowsHtml, true, false, false, false, '');

    $pdf->writeHTML(
        '<table width="100%" cellpadding="0" cellspacing="0" style="line-height:1;">
           <tr><td align="right"><b>PAGE TOTAL AMOUNT: '.number_format($pageTotal,2).'</b></td></tr>
           <tr><td align="right"><b>GRAND TOTAL AMOUNT: '.number_format($grandTotal,2).'</b></td></tr>
         </table>',
        true, false, false, false, ''
    );

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

private function buildExcel(string $file, int $cid, string $start, string $end, callable $progress): void
{
    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Receipt Register');

    $monthDesc = Carbon::parse($start)->isoFormat('MMMM');
    $yearDesc  = Carbon::parse($start)->year;

    // ✅ same simple company header as check register
    $companyName = ($cid === 2) ? 'AMEROP PHILIPPINES, INC' : 'SUCDEN PHILIPPINES, INC';

    $r = 1;
    $ws->setCellValue("A{$r}", $companyName); $r++;
    $ws->setCellValue("A{$r}", 'RECEIPT REGISTER'); $r++;
    $ws->setCellValue("A{$r}", "For the Month of {$monthDesc} {$yearDesc}"); $r += 2;

    $ws->fromArray(['Date','Receipt Voucher','Bank','Customer','','Particular','','Collection Receipt','Amount'], null, "A{$r}");
    $ws->getStyle("A{$r}:I{$r}")->getFont()->setBold(true);
    $r++;

    foreach (range('A','I') as $col) $ws->getColumnDimension($col)->setWidth(18);
    $ws->getColumnDimension('D')->setWidth(28);
    $ws->getColumnDimension('E')->setWidth(4);
    $ws->getColumnDimension('F')->setWidth(30);
    $ws->getColumnDimension('G')->setWidth(4);
    $ws->getColumnDimension('C')->setWidth(22);

    $pageTotal = 0.0; $grandTotal = 0.0; $linesOnPage = 0; $done = 0;

    $q = DB::table('cash_receipts as r')
        ->selectRaw("
            to_char(r.receipt_date,'MM/DD/YYYY') as receipt_date,
            r.cr_no,
            r.details,
            r.collection_receipt,
            r.receipt_amount,
            COALESCE(c.cust_name, r.cust_id) as cust_name,
            COALESCE(b.bank_name, r.bank_id) as bank_name
        ")
        ->leftJoin('customer_list as c', function ($j) use ($cid) {
            $j->on('c.cust_id', '=', 'r.cust_id');
            if (Schema::hasColumn('customer_list', 'company_id')) {
                $j->where('c.company_id', '=', $cid);
            }
        })
        ->leftJoin('bank as b', function ($j) use ($cid) {
            $j->on('b.bank_id', '=', 'r.bank_id');
            if (Schema::hasColumn('bank', 'company_id')) {
                $j->where('b.company_id', '=', $cid);
            }
        })
        ->where('r.company_id', $cid)
        ->whereBetween('r.receipt_date', [$start, $end]);

    if ($this->query) {
        $needle = '%'.$this->escapeLike($this->query).'%';
        $q->where(function($w) use($needle) {
            $w->where('c.cust_name','ILIKE',$needle)
              ->orWhere('r.details','ILIKE',$needle)
              ->orWhere('r.cr_no','ILIKE',$needle)
              ->orWhere('r.collection_receipt','ILIKE',$needle)
              ->orWhere('r.bank_id','ILIKE',$needle)
              ->orWhere('b.bank_name','ILIKE',$needle);
        });
    }

    $q->orderBy('r.cr_no', 'asc')
      ->orderBy('r.receipt_date', 'asc')
      ->chunk(300, function($chunk) use (&$r, $ws, &$pageTotal, &$grandTotal, &$linesOnPage, &$done, $progress) {
          foreach ($chunk as $row) {
              $amt = (float)$row->receipt_amount;
              $pageTotal  += $amt;
              $grandTotal += $amt;
              $linesOnPage++;

              $ws->fromArray([
                  $row->receipt_date ?? '',
                  $row->cr_no ?? '',
                  $row->bank_name ?? '',
                  $row->cust_name ?? '', '',
                  $row->details ?? '', '',
                  $row->collection_receipt ?? '',
                  $amt
              ], null, "A{$r}");

              // keep bank as string (avoid excel numeric mangling)
              $ws->getCell("C{$r}")->setValueExplicit((string)($row->bank_name ?? ''), DataType::TYPE_STRING);

              $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
              $r++;

              if ($linesOnPage >= 45) {
                  $ws->setCellValue("H{$r}", 'PAGE TOTAL AMOUNT:');
                  $ws->setCellValue("I{$r}", $pageTotal);
                  $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                  $r += 2;

                  $linesOnPage = 0;
                  $pageTotal   = 0.0;

                  $ws->fromArray(['Date','Receipt Voucher','Bank','Customer','','Particular','','Collection Receipt','Amount'], null, "A{$r}");
                  $ws->getStyle("A{$r}:I{$r}")->getFont()->setBold(true);
                  $r++;
              }

              $done++; $progress($done);
          }
      });

    $ws->setCellValue("H{$r}", 'PAGE TOTAL AMOUNT:');
    $ws->setCellValue("I{$r}", $pageTotal);
    $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
    $r++;

    $ws->setCellValue("H{$r}", 'GRAND TOTAL AMOUNT:');
    $ws->setCellValue("I{$r}", $grandTotal);
    $ws->getStyle("I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($wb);
    $stream = fopen('php://temp', 'r+');
    $writer->save($stream);
    rewind($stream);

    Storage::disk('local')->put($file, stream_get_contents($stream));

    fclose($stream);
    $wb->disconnectWorksheets();
    unset($writer);
}

    private function escapeLike(string $s): string
    {
        // we are using ILIKE with bound string; escape % and _
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
