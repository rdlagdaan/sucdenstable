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

class BuildAccountsReceivableJournal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

public function __construct(
    public string $ticket,
    public string $startDate,
    public string $endDate,
    public string $format,
    public int $companyId,
    public ?string $query = null
) {}


    private function key(): string { return "arj:{$this->ticket}"; }

    private function patchState(array $patch): void
    {
        $current = Cache::get($this->key(), []);
        Cache::put($this->key(), array_merge($current, $patch), now()->addHours(2));
    }

private function pruneOldReports(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
{
    $disk = Storage::disk('local');

    $cid = (int) ($this->companyId ?? 0);
$prefix = "accounts_receivable_journal_c{$cid}_";


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
        'query'      => $this->query,
        'company_id' => $this->companyId,
    ]);

    try {
        // ✅ Hard requirement: company_id must exist (no unscoped reports)
        $cid = (int) ($this->companyId ?? 0);
        if ($cid <= 0) {
            throw new \RuntimeException('Missing company_id. Refusing to generate unscoped report.');
        }

        $total = DB::table('cash_sales as r')
            ->where('r.company_id', $cid)
            ->when($this->query, function ($q) {
                $s = "%{$this->query}%";
                $q->where(function ($w) use ($s) {
                    $w->where('r.cs_no','ilike',$s)
                      ->orWhere('r.booking_no','ilike',$s)
                      ->orWhere('r.explanation','ilike',$s)
                      ->orWhere('r.cust_id','ilike',$s)
                      ->orWhere('r.bank_id','ilike',$s)
                      ->orWhere('r.si_no','ilike',$s);
                });
            })
            ->whereBetween('r.sales_date', [$this->startDate, $this->endDate])
            ->count();

        $step = function (int $done) use ($total) {
            $pct = $total ? min(99, 1 + (int)floor(($done / max(1,$total)) * 98)) : 50;
            $this->patchState(['progress' => $pct]);
        };

        $dir  = 'reports';
        $disk = Storage::disk('local');
        if (!$disk->exists($dir)) $disk->makeDirectory($dir);

        $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
        $ext   = $this->format === 'pdf' ? 'pdf' : 'xls';

        // ✅ Put company/user into filename so cleanup is tenant-safe
$path = "{$dir}/accounts_receivable_journal_c{$cid}_{$stamp}.{$ext}";

        if ($this->format === 'pdf') $this->buildPdf($path, $step);
        else                         $this->buildExcel($path, $step);

        // ✅ cleanup only within same company+user+format
        $this->pruneOldReports($path, sameFormatOnly: true, keep: 1);

        $this->patchState(['status'=>'done','progress'=>100,'file'=>$path]);
    } catch (Throwable $e) {
        $this->patchState(['status'=>'error','error'=>$e->getMessage()]);
        throw $e;
    }
}


    /** -------- Writers -------- */

private function baseQuery()
{
    $cid = (int) ($this->companyId ?? 0);

    // Detect whether tables support company scoping
    $detailsHasCompany = Schema::hasColumn('cash_sales_details', 'company_id');
    $acctHasCompany    = Schema::hasColumn('account_code', 'company_id');

    $q = DB::table('cash_sales as r')
        ->selectRaw("
            r.id,
            to_char(r.sales_date,'MM/DD/YYYY') as sales_date,
            r.cs_no,
            r.si_no,
            r.cust_id,
            r.booking_no,
            r.explanation,
            b.acct_desc as bank_name,
            json_agg(json_build_object(
                'acct_code', d.acct_code,
                'acct_desc', a.acct_desc,
                'debit', d.debit,
                'credit', d.credit
            ) ORDER BY d.id) as lines
        ");

    // Bank account_code join (optionally company scoped)
    $q->leftJoin('account_code as b', function ($j) use ($acctHasCompany, $cid) {
        $j->on('b.acct_code', '=', 'r.bank_id');
        if ($acctHasCompany && $cid > 0) {
            $j->where('b.company_id', '=', $cid);
        }
    });

    // Details join: ✅ add company_id guard if possible
    $q->join('cash_sales_details as d', function ($j) use ($detailsHasCompany) {
        $j->on(DB::raw('CAST(d.transaction_id AS BIGINT)'), '=', 'r.id');
        if ($detailsHasCompany) {
            $j->on('d.company_id', '=', 'r.company_id');
        }
    });

    // Line account_code join (optionally company scoped)
    $q->leftJoin('account_code as a', function ($j) use ($acctHasCompany, $cid) {
        $j->on('a.acct_code', '=', 'd.acct_code');
        if ($acctHasCompany && $cid > 0) {
            $j->where('a.company_id', '=', $cid);
        }
    });

    return $q;
}

private function applyFilters($q)
{
    $cid = (int) ($this->companyId ?? 0);
    if ($cid <= 0) {
        throw new \RuntimeException('Missing company_id. Refusing to run unscoped query.');
    }

    $q->where('r.company_id', $cid)
      ->when($this->query, function ($x) {
          $s = "%{$this->query}%";
          $x->where(function ($w) use ($s) {
              $w->where('r.cs_no','ilike',$s)
                ->orWhere('r.booking_no','ilike',$s)
                ->orWhere('r.explanation','ilike',$s)
                ->orWhere('r.cust_id','ilike',$s)
                ->orWhere('r.bank_id','ilike',$s)
                ->orWhere('r.si_no','ilike',$s);
          });
      })
      ->whereBetween('r.sales_date', [$this->startDate, $this->endDate]);

    return $q;
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
    $co = $this->companyHeader();

    $pdf = new \TCPDF('P','mm','LETTER', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->setImageScale(1.25);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->AddPage();

    $name  = htmlspecialchars($co['name'],  ENT_QUOTES, 'UTF-8');
    $tin   = htmlspecialchars($co['tin'],   ENT_QUOTES, 'UTF-8');
    $addr1 = htmlspecialchars($co['addr1'], ENT_QUOTES, 'UTF-8');
    $addr2 = htmlspecialchars($co['addr2'], ENT_QUOTES, 'UTF-8');

    $hdr = <<<HTML
      <table width="100%" cellspacing="0" cellpadding="0">
        <tr><td align="right"><b>{$name}</b><br/>
          <span style="font-size:9px">{$tin}</span><br/>
          <span style="font-size:9px">{$addr1}</span><br/>
          <span style="font-size:9px">{$addr2}</span></td></tr>
        <tr><td><hr/></td></tr>
      </table>
      <h2>ACCOUNTS RECEIVABLE JOURNAL</h2>
      <div><b>For the period covering {$this->startDate} — {$this->endDate}</b></div>
      <br/>
      <table width="100%"><tr><td colspan="5"></td><td align="right"><b>Debit</b></td><td align="right"><b>Credit</b></td></tr></table>
    HTML;

    $pdf->writeHTML($hdr, true, false, false, false, '');

    $done = 0; $lineCount = 0;

    $this->applyFilters($this->baseQuery())
        ->groupBy('r.id','r.sales_date','r.cs_no','r.si_no','r.cust_id','r.booking_no','r.explanation','b.acct_desc')
        ->orderBy('r.id')
        ->chunk(200, function($chunk) use (&$done, $progress, $pdf, &$lineCount) {
            foreach ($chunk as $row) {
                $docId = 'ARCR-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                $block = <<<HTML
                  <table width="100%" cellspacing="0" cellpadding="1">
                    <tr><td>{$row->sales_date}</td><td colspan="6">{$docId}</td></tr>
                    <tr>
                      <td colspan="6"><b>SV - {$row->cs_no} - {$row->cust_id} - {$row->explanation}</b>
                      &nbsp;&nbsp;<span style="font-size:11px"><u>{$row->si_no}</u></span></td>
                      <td align="right">{$row->bank_name}</td>
                    </tr>
                    <tr><td colspan="7">&nbsp;&nbsp;&nbsp;OR#: {$row->booking_no}</td></tr>
                  </table>
                HTML;
                $pdf->writeHTML($block, true, false, false, false, '');

                $itemDebit=0; $itemCredit=0;
                $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';

                foreach (json_decode($row->lines, true) as $ln) {
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
                        $pdf->AddPage();
                        $lineCount = 0;
                        $rowsHtml = '<table width="100%" cellspacing="0" cellpadding="1">';
                    }
                }

                $rowsHtml .= sprintf(
                    '<tr><td></td><td colspan="4"></td>
                       <td align="right"><b>%s</b></td>
                       <td align="right"><b>%s</b></td>
                     </tr>
                     <tr><td colspan="7"><hr/></td></tr>
                     <tr><td colspan="7"><br/></td></tr>',
                    number_format($itemDebit,2),
                    number_format($itemCredit,2)
                );
                $rowsHtml .= '</table>';
                $pdf->writeHTML($rowsHtml, true, false, false, false, '');

                $done++; $progress($done);
            }
        });

    Storage::disk('local')->put($file, $pdf->Output('accounts-receivable.pdf', 'S'));
}

private function buildExcel(string $file, callable $progress): void
{
    $co = $this->companyHeader();

    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Accounts Receivable Journal');

    $r = 1;
    $ws->setCellValue("A{$r}", 'ACCOUNTS RECEIVABLE JOURNAL'); $r++;
    $ws->setCellValue("A{$r}", $co['name']);  $r++;
    $ws->setCellValue("A{$r}", $co['tin']);   $r++;
    $ws->setCellValue("A{$r}", $co['addr1']); $r++;
    $ws->setCellValue("A{$r}", $co['addr2']); $r+=2;

    $ws->setCellValue("A{$r}", "For the period covering: {$this->startDate} to {$this->endDate}"); $r+=2;
    $ws->fromArray(['', '', '', '', '', 'DEBIT', 'CREDIT'], null, "A{$r}"); $r++;
    foreach (range('A','G') as $col) $ws->getColumnDimension($col)->setWidth(15);

    $done = 0;
    $this->applyFilters($this->baseQuery())
        ->groupBy('r.id','r.sales_date','r.cs_no','r.si_no','r.cust_id','r.booking_no','r.explanation','b.acct_desc')
        ->orderBy('r.id')
        ->chunk(200, function($chunk) use (&$r, $ws, &$done, $progress) {
            foreach ($chunk as $row) {
                $docId = 'ARCR-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT);

                $ws->setCellValue("A{$r}", $row->sales_date);
                $ws->setCellValue("B{$r}", $docId); $r++;

                $ws->setCellValue("A{$r}", "SV - {$row->cs_no} - {$row->cust_id} - {$row->explanation}");
                $ws->setCellValue("B{$r}", "S.I.#: {$row->si_no}"); $r++;

                $ws->setCellValue("A{$r}", "OR#: {$row->booking_no}");
                $ws->setCellValue("B{$r}", $row->bank_name); $r++;

                $itemDebit=0; $itemCredit=0;
                foreach (json_decode($row->lines,true) as $ln) {
                    $ws->setCellValue("A{$r}", $ln['acct_code'] ?? '');
                    $ws->setCellValue("B{$r}", $ln['acct_desc'] ?? '');
                    $ws->setCellValue("F{$r}", (float)($ln['debit'] ?? 0));
                    $ws->setCellValue("G{$r}", (float)($ln['credit'] ?? 0));
                    $ws->getStyle("F{$r}:G{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $itemDebit  += (float)($ln['debit'] ?? 0);
                    $itemCredit += (float)($ln['credit'] ?? 0);
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
    $writer->save($stream); rewind($stream);
    Storage::disk('local')->put($file, stream_get_contents($stream));
    fclose($stream);
    $wb->disconnectWorksheets();
    unset($writer);
}

}
