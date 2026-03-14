<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BuildMonthlyExpenseReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public string $ticket,
        public string $startAccount,
        public string $endAccount,
        public string $startDate,
        public string $endDate,
        public string $format,        // pdf | xls
        public ?int $companyId,
    ) {}

    private function key(): string
    {
        return "glx:{$this->ticket}";
    }

    private function patchState(array $patch): void
    {
        $cur = Cache::get($this->key(), []);
        Cache::put($this->key(), array_merge($cur, $patch), now()->addHours(6));
    }

    private function filePrefix(): string
    {
        $cid = (int) ($this->companyId ?? 0);
        return "monthly_expense_c{$cid}_";
    }

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

    private function monthMap(): array
    {
        return [
            1  => 'jan_amt',
            2  => 'feb_amt',
            3  => 'mar_amt',
            4  => 'apr_amt',
            5  => 'may_amt',
            6  => 'jun_amt',
            7  => 'jul_amt',
            8  => 'aug_amt',
            9  => 'sep_amt',
            10 => 'oct_amt',
            11 => 'nov_amt',
            12 => 'dec_amt',
        ];
    }

    private function buildRows(int $cid): array
    {
        $detailsHasCompany = Schema::hasColumn('general_accounting_details', 'company_id');
        $acctHasCompany    = Schema::hasColumn('account_code', 'company_id');

        $rows = DB::table('account_code as ac')
            ->leftJoin('general_accounting_details as d', function ($join) use ($detailsHasCompany) {
                $join->on('d.acct_code', '=', 'ac.acct_code');
                if ($detailsHasCompany) {
                    $join->on('d.company_id', '=', 'ac.company_id');
                }
            })
            ->leftJoin('general_accounting as g', function ($join) {
                $join->whereRaw('g.id::text = d.transaction_id');
            })
            ->when($acctHasCompany, fn ($q) => $q->where('ac.company_id', $cid))
            ->where('ac.active_flag', 1)
            ->whereBetween('ac.acct_code', [$this->startAccount, $this->endAccount])
            ->where(function ($q) use ($cid) {
                $q->whereNull('g.id')
                  ->orWhere('g.company_id', $cid);
            })
            ->where(function ($q) {
                $q->whereNull('g.id')
                  ->orWhereBetween('g.gen_acct_date', [$this->startDate, $this->endDate]);
            })
            ->selectRaw("
                ac.acct_code,
                ac.acct_desc,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 1  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS jan_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 2  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS feb_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 3  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS mar_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 4  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS apr_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 5  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS may_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 6  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS jun_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 7  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS jul_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 8  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS aug_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 9  THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS sep_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 10 THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS oct_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 11 THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS nov_amt,
                COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM g.gen_acct_date) = 12 THEN COALESCE(d.debit,0) - COALESCE(d.credit,0) ELSE 0 END), 0) AS dec_amt,
                COALESCE(SUM(COALESCE(d.debit,0) - COALESCE(d.credit,0)), 0) AS tb_balance
            ")
            ->groupBy('ac.acct_code', 'ac.acct_desc')
            ->orderByRaw("
                CASE
                    WHEN ac.acct_code ~ '^[0-9]+' THEN (substring(ac.acct_code from '^[0-9]+'))::int
                    ELSE NULL
                END NULLS LAST
            ")
            ->orderBy('ac.acct_code')
            ->get()
            ->map(function ($r) {
                $detail = (float) $r->tb_balance;
                return [
                    'acct_code'  => (string) $r->acct_code,
                    'acct_desc'  => (string) ($r->acct_desc ?? ''),
                    'jan_amt'    => (float) $r->jan_amt,
                    'feb_amt'    => (float) $r->feb_amt,
                    'mar_amt'    => (float) $r->mar_amt,
                    'apr_amt'    => (float) $r->apr_amt,
                    'may_amt'    => (float) $r->may_amt,
                    'jun_amt'    => (float) $r->jun_amt,
                    'jul_amt'    => (float) $r->jul_amt,
                    'aug_amt'    => (float) $r->aug_amt,
                    'sep_amt'    => (float) $r->sep_amt,
                    'oct_amt'    => (float) $r->oct_amt,
                    'nov_amt'    => (float) $r->nov_amt,
                    'dec_amt'    => (float) $r->dec_amt,
                    'tb_balance' => (float) $r->tb_balance,
                    'detail'     => $detail,
                ];
            })
            ->filter(function ($r) {
                return abs($r['jan_amt']) > 0.00001
                    || abs($r['feb_amt']) > 0.00001
                    || abs($r['mar_amt']) > 0.00001
                    || abs($r['apr_amt']) > 0.00001
                    || abs($r['may_amt']) > 0.00001
                    || abs($r['jun_amt']) > 0.00001
                    || abs($r['jul_amt']) > 0.00001
                    || abs($r['aug_amt']) > 0.00001
                    || abs($r['sep_amt']) > 0.00001
                    || abs($r['oct_amt']) > 0.00001
                    || abs($r['nov_amt']) > 0.00001
                    || abs($r['dec_amt']) > 0.00001
                    || abs($r['tb_balance']) > 0.00001;
            })
            ->values()
            ->all();

        return $rows;
    }

    public function handle(): void
    {
        $this->patchState([
            'status'       => 'running',
            'progress'     => 1,
            'message'      => 'Building monthly expense report...',
            'format'       => $this->format,
            'file_rel'     => null,
            'file_abs'     => null,
            'file_url'     => null,
            'file_disk'    => 'local',
            'download_name'=> null,
            'error'        => null,
        ]);

        try {
            $cid = (int) ($this->companyId ?? 0);
            if ($cid <= 0) {
                throw new \RuntimeException('Missing company_id. Refusing to generate unscoped report.');
            }

            $disk = Storage::disk('local');
            if (!$disk->exists('reports')) {
                $disk->makeDirectory('reports');
            }

            $rows  = $this->buildRows($cid);
            $stamp = now()->format('Ymd_His') . '_' . Str::uuid();
            $ext   = $this->format === 'pdf' ? 'pdf' : 'xls';
            $name  = "monthly-expense-report.{$ext}";
            $path  = "reports/{$this->filePrefix()}{$stamp}.{$ext}";

            $this->patchState([
                'progress' => 40,
                'message'  => 'Preparing file...',
            ]);

            if ($this->format === 'pdf') {
                $this->buildPdf($path, $rows, $cid);
            } else {
                $this->buildXls($path, $rows, $cid);
            }

            $abs = Storage::disk('local')->path($path);

            $this->patchState([
                'status'        => 'done',
                'progress'      => 100,
                'message'       => 'Done',
                'file_rel'      => $path,
                'file_abs'      => $abs,
                'file_disk'     => 'local',
                'download_name' => $name,
            ]);
        } catch (Throwable $e) {
            $this->patchState([
                'status'   => 'failed',
                'progress' => 100,
                'message'  => $e->getMessage(),
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildPdf(string $path, array $rows, int $cid): void
    {
        @ini_set('memory_limit', '512M');

        $co = $this->companyHeader($cid);
        $yearLabel = date('Y', strtotime($this->endDate));

        $pdf = new class($co, $yearLabel) extends \TCPDF {
            public function __construct(
                public array $co,
                public string $yearLabel
            ) {
                // Make the page much wider so TB Balance and DETAIL fully fit
                parent::__construct('L', 'mm', [215.9, 520], true, 'UTF-8', false);
            }

            public function Header()
            {
                $this->SetY(8);
                $this->SetFont('helvetica', 'B', 10);
                $this->Cell(0, 5, $this->co['name'] ?? '', 0, 1, 'L');

                $this->SetFont('helvetica', 'B', 9);
                $this->Cell(0, 5, 'SUMMARY OF OPERATING EXPENSES', 0, 1, 'L');

                $this->SetFont('helvetica', '', 9);
                $this->Cell(0, 5, 'FOR THE YEAR ' . $this->yearLabel, 0, 1, 'L');

                $this->Ln(2);
            }

            public function Footer()
            {
                $this->SetY(-12);
                $this->SetFont('helvetica', 'I', 7);
                $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        };

        $pdf->SetMargins(2, 24, 2);
        $pdf->SetHeaderMargin(4);
        $pdf->SetAutoPageBreak(true, 8);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 5.5);

        $w = [
            'acct' => 9,
            'desc' => 24,
            'm'    => 4.5,
            'tb'   => 6.5,
            'det'  => 6.5,
        ];

        $thead = '
        <table border="1" cellpadding="2" cellspacing="0" width="100%">
            <tr bgcolor="#D9EAF7" align="center">
                <td width="'.$w['acct'].'%"><b>Account</b></td>
                <td width="'.$w['desc'].'%"><b>Description</b></td>
                <td width="'.$w['m'].'%"><b>Jan</b></td>
                <td width="'.$w['m'].'%"><b>Feb</b></td>
                <td width="'.$w['m'].'%"><b>Mar</b></td>
                <td width="'.$w['m'].'%"><b>Apr</b></td>
                <td width="'.$w['m'].'%"><b>May</b></td>
                <td width="'.$w['m'].'%"><b>Jun</b></td>
                <td width="'.$w['m'].'%"><b>Jul</b></td>
                <td width="'.$w['m'].'%"><b>Aug</b></td>
                <td width="'.$w['m'].'%"><b>Sep</b></td>
                <td width="'.$w['m'].'%"><b>Oct</b></td>
                <td width="'.$w['m'].'%"><b>Nov</b></td>
                <td width="'.$w['m'].'%"><b>Dec</b></td>
                <td width="'.$w['tb'].'%"><b>TB Balance</b></td>
                <td width="'.$w['det'].'%"><b>DETAIL</b></td>
            </tr>';

        $tbody = '';
        foreach ($rows as $r) {
            $tbody .= '<tr>
                <td width="'.$w['acct'].'%">'.$this->esc($r['acct_code']).'</td>
                <td width="'.$w['desc'].'%">'.$this->esc($r['acct_desc']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['jan_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['feb_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['mar_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['apr_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['may_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['jun_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['jul_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['aug_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['sep_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['oct_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['nov_amt']).'</td>
                <td width="'.$w['m'].'%" align="right">'.$this->num($r['dec_amt']).'</td>
                <td width="'.$w['tb'].'%" align="right"><b>'.$this->num($r['tb_balance']).'</b></td>
                <td width="'.$w['det'].'%" align="right"><b>'.$this->num($r['detail']).'</b></td>
            </tr>';
        }

        $tfoot = '</table>';

        $pdf->writeHTML($thead . $tbody . $tfoot, true, false, false, false, '');
        Storage::disk('local')->put($path, $pdf->Output('monthly-expense-report.pdf', 'S'));
    }

    private function buildXls(string $path, array $rows, int $cid): void
    {
        $co = $this->companyHeader($cid);
        $yearLabel = date('Y', strtotime($this->endDate));

        $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Monthly Expenses');

        $ws->setCellValue('A1', $co['name']);
        $ws->setCellValue('A2', 'SUMMARY OF OPERATING EXPENSES');
        $ws->setCellValue('A3', 'FOR THE YEAR ' . $yearLabel);

        $headers = [
            'A5' => 'Account',
            'B5' => 'Description',
            'C5' => 'Jan',
            'D5' => 'Feb',
            'E5' => 'Mar',
            'F5' => 'Apr',
            'G5' => 'May',
            'H5' => 'Jun',
            'I5' => 'Jul',
            'J5' => 'Aug',
            'K5' => 'Sep',
            'L5' => 'Oct',
            'M5' => 'Nov',
            'N5' => 'Dec',
            'O5' => 'TB Balance',
            'P5' => 'DETAIL',
        ];

        foreach ($headers as $cell => $label) {
            $ws->setCellValue($cell, $label);
        }

        $widths = [
            'A' => 12, 'B' => 30,
            'C' => 14, 'D' => 14, 'E' => 14, 'F' => 14,
            'G' => 14, 'H' => 14, 'I' => 14, 'J' => 14,
            'K' => 14, 'L' => 14, 'M' => 14, 'N' => 14,
            'O' => 16, 'P' => 16,
        ];
        foreach ($widths as $col => $width) {
            $ws->getColumnDimension($col)->setWidth($width);
        }

        $r = 6;
        foreach ($rows as $row) {
            $ws->setCellValue("A{$r}", $row['acct_code']);
            $ws->setCellValue("B{$r}", $row['acct_desc']);
            $ws->setCellValue("C{$r}", $row['jan_amt']);
            $ws->setCellValue("D{$r}", $row['feb_amt']);
            $ws->setCellValue("E{$r}", $row['mar_amt']);
            $ws->setCellValue("F{$r}", $row['apr_amt']);
            $ws->setCellValue("G{$r}", $row['may_amt']);
            $ws->setCellValue("H{$r}", $row['jun_amt']);
            $ws->setCellValue("I{$r}", $row['jul_amt']);
            $ws->setCellValue("J{$r}", $row['aug_amt']);
            $ws->setCellValue("K{$r}", $row['sep_amt']);
            $ws->setCellValue("L{$r}", $row['oct_amt']);
            $ws->setCellValue("M{$r}", $row['nov_amt']);
            $ws->setCellValue("N{$r}", $row['dec_amt']);
            $ws->setCellValue("O{$r}", $row['tb_balance']);
            $ws->setCellValue("P{$r}", $row['detail']);
            $r++;
        }

        $ws->getStyle("A5:P5")->getFont()->setBold(true);
        $ws->getStyle("A5:P5")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $ws->getStyle("A5:P5")->getFill()->getStartColor()->setRGB('D9EAF7');

        if ($r > 6) {
            $ws->getStyle("C6:P" . ($r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($wb);
        $stream = fopen('php://temp', 'r+');
        $writer->save($stream);
        rewind($stream);

        Storage::disk('local')->put($path, stream_get_contents($stream));

        fclose($stream);
        $wb->disconnectWorksheets();
        unset($writer);
    }

    private function num(float $v): string
    {
        if (abs($v) < 0.00001) {
            return '';
        }
        return number_format($v, 2);
    }

    private function esc(string $v): string
    {
        return e($v);
    }
}