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
            'status'   => 'running',
            'progress' => 1,
            'format'   => $this->format,  // 'pdf' | 'xls'
            'file'     => null,
            'error'    => null,
            'range'    => [$this->startDate, $this->endDate],
            'query'    => $this->query,
            'user_id'  => $this->userId,
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
            // Pre-count for progress
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

            $dir = 'reports';
            $disk = Storage::disk('local');
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $path  = $this->format === 'pdf'
                ? "$dir/accounts_payable_journal_{$stamp}.pdf"
                : "$dir/accounts_payable_journal_{$stamp}.xls";

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
                'status' => 'error',
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function pruneOldReports(string $keepFile, bool $sameFormatOnly = true, int $keep = 1): void
    {
        $disk = Storage::disk('local');
        $files = collect($disk->files('reports'))
            ->filter(fn($p) => str_starts_with(basename($p), 'accounts_payable_journal_'))
            ->when($sameFormatOnly, function ($c) use ($keepFile) {
                $ext = pathinfo($keepFile, PATHINFO_EXTENSION);
                return $c->filter(fn($p) => pathinfo($p, PATHINFO_EXTENSION) === $ext);
            })
            ->sortByDesc(fn($p) => $disk->lastModified($p))
            ->slice($keep);

        $files->each(fn($p) => $disk->delete($p));
    }

    /** ---------- Writers ---------- */

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

    $pdf = new class($co) extends \TCPDF {
        public function __construct(public array $co)
        {
            parent::__construct('P', 'mm', 'LETTER', true, 'UTF-8', false);
        }

        public function Header()
        {
            $this->SetY(10);
            $name  = $this->co['name']  ?? '';
            $tin   = $this->co['tin']   ?? '';
            $addr1 = $this->co['addr1'] ?? '';
            $addr2 = $this->co['addr2'] ?? '';

            $htmlHeader = ''
                . '<table border="0" cellspacing="0" cellpadding="0" width="100%">'
                . '  <tr><td align="right">'
                . '    <font size="12"><b>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</b></font><br/>'
                . '    <font size="6">' . htmlspecialchars($tin, ENT_QUOTES, 'UTF-8') . '</font><br/>'
                . '    <font size="6">' . htmlspecialchars($addr1, ENT_QUOTES, 'UTF-8') . '</font><br/>'
                . '    <font size="6">' . htmlspecialchars($addr2, ENT_QUOTES, 'UTF-8') . '</font>'
                . '  </td></tr>'
                . '  <tr><td align="right"><hr/></td></tr>'
                . '</table>';

            $this->writeHTML($htmlHeader, true, false, false, false, '');
        }

        public function Footer()
        {
            $this->SetY(-20);
            $this->SetFont('helvetica', 'I', 8);

            $currentDate = date('M d, Y');
            $currentTime = date('h:i:sa');

            $htmlFooter = ''
                . '<table border="0" width="100%">'
                . '<tr>'
                . '  <td><font size="8">Print Date:</font></td>'
                . '  <td><font size="8">' . $currentDate . '</font></td>'
                . '  <td></td>'
                . '  <td></td>'
                . '</tr>'
                . '<tr>'
                . '  <td><font size="8">Print Time:</font></td>'
                . '  <td><font size="8">' . $currentTime . '</font></td>'
                . '  <td></td>'
                . '  <td align="right"><font size="8">' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages() . '</font></td>'
                . '</tr>'
                . '</table>';

            $this->writeHTML($htmlFooter, true, false, false, false, '');
        }
    };

    $pdf->SetMargins(10, 35, 10);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->AddPage('P', 'LETTER');

    $tbl = <<<HTML
<table border="0" cellpadding="0" cellspacing="0" nobr="true" width="100%">
  <tr>
    <td colspan="7" height="2">
      <font size="15"><b>ACCOUNTS PAYABLE JOURNAL</b></font><br/>
      <font size="10"><b>For the period covering {$this->startDate} -- {$this->endDate}</b></font><br/>
    </td>
  </tr>
</table>

<table border="0" cellpadding="0" cellspacing="0" nobr="true" width="100%">
  <tr><td colspan="7" height="1"><hr/></td></tr>
  <tr>
    <td width="70%" colspan="5"></td>
    <td width="15%" align="right"><font size="10">Debit</font></td>
    <td width="15%" align="right"><font size="10">Credit</font></td>
  </tr>
  <tr><td colspan="7"></td></tr>
HTML;

    $done = 0;
    $ctr  = 0;
    $lctr = 0;

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
        ->where('r.company_id', $cid)
        ->whereBetween('r.purchase_date', [$this->startDate, $this->endDate])
        ->groupBy(
            'r.id',
            'r.purchase_date',
            'r.cp_no',
            'r.rr_no',
            'r.explanation',
            'r.bank_id',
            'b.acct_desc'
        )
        ->orderBy('r.id')
        ->chunk(200, function ($chunk) use (&$done, $progress, &$tbl, &$ctr, &$lctr) {
            foreach ($chunk as $row) {
                $done++;
                $progress($done);

                $lines = json_decode($row->lines, true) ?: [];

                $descLine = trim(implode(' - ', array_filter([
                    $row->rr_no,
                    $row->mill_name,
                    $row->bank_name,
                    $row->explanation
                ])));

                $tbl .= "
<tr>
  <td>{$row->purchase_date}</td>
  <td colspan=\"6\">{$row->id}</td>
</tr>
<tr>
  <td colspan=\"6\"><b>{$descLine}</b> <u>{$row->cp_no}</u></td>
  <td></td>
</tr>
<tr>
  <td colspan=\"7\">{$row->rr_no}</td>
</tr>
";

                $td = 0; $tc = 0;

                foreach ($lines as $ln) {
                    $td += $ln['debit'];
                    $tc += $ln['credit'];
                    $ctr++;

                    $tbl .= "
<tr>
  <td>{$ln['acct_code']}</td>
  <td colspan=\"4\">{$ln['acct_desc']}</td>
  <td align=\"right\">".number_format($ln['debit'],2)."</td>
  <td align=\"right\">".number_format($ln['credit'],2)."</td>
</tr>
";
                }

                $tbl .= "
<tr>
  <td colspan=\"5\"></td>
  <td align=\"right\">".number_format($td,2)."</td>
  <td align=\"right\">".number_format($tc,2)."</td>
</tr>
<tr><td colspan=\"7\"><br/><br/></td></tr>
";

                $lctr++;

                if ((($lctr % 3) === 0 || ($ctr % 25) === 0) && $ctr !== 0) {
                    $tbl .= '<br pagebreak="true"/>';
                    $ctr = 0;
                    $lctr = 0;
                }
            }
        });

    $tbl .= '</table>';
    $pdf->writeHTML($tbl, true, false, false, false, '');
    Storage::disk('local')->put($file, $pdf->Output('accounts-payable.pdf', 'S'));
}

private function buildExcel(string $file, callable $progress): void
{
    $cid = (int) $this->companyId;
    $co  = $this->companyHeader();

    $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $wb->getActiveSheet();
    $ws->setTitle('Accounts Payable Journal');

    $r = 1;
    $ws->setCellValue("A{$r}", 'ACCOUNTS PAYABLE JOURNAL'); $r++;
    $ws->setCellValue("A{$r}", $co['name']);  $r++;
    $ws->setCellValue("A{$r}", $co['tin']);   $r++;
    $ws->setCellValue("A{$r}", $co['addr1']); $r++;
    $ws->setCellValue("A{$r}", $co['addr2']); $r += 2;

    $ws->setCellValue("A{$r}", 'ACCOUNTS PAYABLE JOURNAL'); $r++;
    $ws->setCellValue("A{$r}", "For the period covering: {$this->startDate} to {$this->endDate}");
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
        ->where('r.company_id', $cid)
        ->whereBetween('r.purchase_date', [$this->startDate, $this->endDate])
        ->when($this->query, function ($q) {
            $q->where(function ($x) {
                $like = '%' . $this->query . '%';
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
            'b.acct_desc'
        )
        ->orderBy('r.id')
        ->chunk(200, function ($chunk) use (&$r, $ws, &$done, $progress) {
            foreach ($chunk as $row) {
                $done++;
                $progress($done);

                $lines = json_decode($row->lines, true) ?: [];

                $descLine = trim(implode(' - ', array_filter([
                    $row->rr_no,
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

                $ws->setCellValue("A{$r}", $row->rr_no);
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
