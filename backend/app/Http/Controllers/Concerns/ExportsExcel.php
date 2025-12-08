<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ExportsExcel
{
    /**
     * Stream an Excel export (.xlsx or .xls).
     *
     * @param  Request $request  expects ?format=xlsx|xls (default xlsx)
     * @param  Builder $query    Eloquent query (already filtered)
     * @param  array   $columns  [
     *    ['key'=>'bank_id', 'label'=>'Bank ID', 'type'=>'string'], // string|number|date
     *    ['key'=>'bank_name','label'=>'Name'],
     *    ['label'=>'Custom','accessor'=>fn($row)=>..., 'type'=>'string'],
     *  ]
     * @param  array   $opts     ['filename'=>'banks','sheet'=>'Banks','autosize'=>true]
     */
    protected function exportExcel(Request $request, Builder $query, array $columns, array $opts = []): StreamedResponse
    {
        $format = strtolower((string) $request->query('format', 'xlsx'));
        if (!in_array($format, ['xlsx','xls'], true)) $format = 'xlsx';

        $filename  = ($opts['filename'] ?? 'export') . '_' . now()->format('Ymd_His') . '.' . $format;
        $sheetName = $opts['sheet'] ?? 'Sheet1';
        $autosize  = $opts['autosize'] ?? true;

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle($sheetName);

        // Header
        $labels = array_map(fn($c) => $c['label'] ?? ($c['key'] ?? ''), $columns);
        $sheet->fromArray([$labels], null, 'A1');
        $sheet->getStyle('A1:' . chr(64 + count($labels)) . '1')->getFont()->setBold(true);

        // Data
        $r = 2;
        foreach ($query->orderBy($columns[0]['key'] ?? 'id', 'asc')->cursor() as $row) {
            $cIdx = 0;
            foreach ($columns as $col) {
                $cIdx++;
                $addr = chr(64 + $cIdx) . $r;

                $val = array_key_exists('accessor', $col) && $col['accessor'] instanceof Closure
                    ? ($col['accessor'])($row)
                    : (isset($col['key']) ? data_get($row, $col['key']) : null);

                $type = strtolower($col['type'] ?? 'string');

                if ($type === 'number' && $val !== null && $val !== '') {
                    $sheet->setCellValue($addr, is_numeric($val) ? +$val : $val);
                } elseif ($type === 'date' && $val) {
                    $sheet->setCellValueExplicit($addr, (string) $val, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValueExplicit($addr, (string) ($val ?? ''), DataType::TYPE_STRING);
                }
            }
            $r++;
        }

        if ($autosize) {
            for ($i = 1; $i <= count($labels); $i++) {
                $sheet->getColumnDimension(chr(64 + $i))->setAutoSize(true);
            }
        }

        $mime = $format === 'xls'
            ? 'application/vnd.ms-excel'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload(function () use ($ss, $format) {
            $writer = IOFactory::createWriter($ss, $format === 'xls' ? 'Xls' : 'Xlsx');
            $writer->save('php://output');
            $ss->disconnectWorksheets();
        }, $filename, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }
}
