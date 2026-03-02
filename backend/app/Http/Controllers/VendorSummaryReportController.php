<?php

namespace App\Http\Controllers;

use App\Jobs\BuildVendorSummaryReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VendorSummaryReportController extends Controller
{
    private function key(string $ticket): string
    {
        return "vsr:{$ticket}";
    }

    // Dropdown datasource for UI
public function vendors(Request $request)
{
    $cid = (int) ($request->query('company_id') ?? 0);
    if ($cid <= 0) {
        return response()->json(['message' => 'Missing company_id'], 422);
    }

    // Customer pattern + REQUIRED vendor deduplication
    $rows = DB::table('vendor_list')
        ->select([
            'vend_code as vend_id',
            'vend_name',
        ])
        ->where('company_id', $cid)
        ->whereNotNull('vend_code')
        ->where('vend_code', '<>', '')
        ->whereNotNull('vend_name')
        ->where('vend_name', '<>', '')
        ->groupBy('vend_code', 'vend_name')
        ->orderBy('vend_name', 'asc')
        ->get();

    return response()->json($rows);
}







    public function start(Request $request)
    {
        try {
            $cid = (int)($request->input('company_id') ?? 0);
            if ($cid <= 0) return response()->json(['message' => 'Missing company_id'], 422);

            $start = (string)$request->input('start_date');
            $end   = (string)$request->input('end_date');
            $vend  = (string)$request->input('vend_id');
            $fmtIn = (string)$request->input('format', 'pdf');
            $uid   = $request->input('user_id') ? (int)$request->input('user_id') : null;

            if (!$start || !$end) return response()->json(['message' => 'Missing start_date/end_date'], 422);
            if (!$vend) return response()->json(['message' => 'Missing vend_id'], 422);

            // normalize exactly like working modules: pdf | xls
            $fmt = strtolower(trim($fmtIn));
            if ($fmt === 'excel' || $fmt === 'xlsx') $fmt = 'xls';
            if (!in_array($fmt, ['pdf','xls'], true)) $fmt = 'pdf';

            $ticket = (string) Str::uuid();

            Cache::put($this->key($ticket), [
                'status'     => 'queued',
                'progress'   => 0,
                'format'     => $fmt,
                'file'       => null,
                'error'      => null,
                'company_id' => $cid,
                'user_id'    => $uid,
                'start_date' => $start,
                'end_date'   => $end,
                'vend_id'    => $vend,
            ], now()->addHours(2));

            BuildVendorSummaryReport::dispatchAfterResponse(
                ticket: $ticket,
                startDate: $start,
                endDate: $end,
                vendId: $vend,
                format: $fmt,
                companyId: $cid,
                userId: $uid
            );

            return response()->json(['ticket' => $ticket]);
        } catch (\Throwable $e) {
            \Log::error('VendorSummaryReportController@start failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Server Error',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // alias safety (same as CustomerSummaryReportController)
    public function report(Request $request)
    {
        return $this->start($request);
    }

    public function status(Request $request, string $ticket)
    {
        $cid = (int)($request->query('company_id') ?? 0);
        if ($cid <= 0) return response()->json(['message' => 'Missing company_id'], 422);

        $st = Cache::get($this->key($ticket));
        if (!$st) return response()->json(['status' => 'error', 'progress' => 100, 'error' => 'Ticket not found'], 404);

        if ((int)($st['company_id'] ?? 0) !== $cid) {
            return response()->json(['status' => 'error', 'progress' => 100, 'error' => 'Forbidden'], 403);
        }

        return response()->json($st);
    }

    public function download(Request $request, string $ticket)
    {
        try {
            $cid = (int)($request->query('company_id') ?? 0);
            if ($cid <= 0) return response()->json(['message' => 'Missing company_id'], 422);

            $st = Cache::get($this->key($ticket));
            if (!$st) return response()->json(['message' => 'Ticket not found'], 404);

            if ((int)($st['company_id'] ?? 0) !== $cid) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            if (($st['status'] ?? '') !== 'done' || empty($st['file'])) {
                return response()->json(['message' => 'Report not ready'], 409);
            }

            $path = (string) $st['file'];
            if (!Storage::disk('local')->exists($path)) {
                return response()->json(['message' => 'File missing'], 404);
            }

            $absolute = Storage::disk('local')->path($path);

            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $name = $ext === 'pdf'
                ? 'vendor_summary_report.pdf'
                : 'vendor_summary_report.xls';

            $mime = $ext === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.ms-excel';

            return response()->download($absolute, $name, [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'attachment; filename="'.$name.'"',
                'Cache-Control'       => 'private, max-age=0, must-revalidate',
                'Pragma'              => 'public',
            ]);
        } catch (\Throwable $e) {
            \Log::error('VendorSummaryReportController@download failed', [
                'ticket' => $ticket,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Server Error',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function view(Request $request, string $ticket)
    {
        try {
            $cid = (int)($request->query('company_id') ?? 0);
            if ($cid <= 0) return response()->json(['message' => 'Missing company_id'], 422);

            $st = Cache::get($this->key($ticket));
            if (!$st) return response()->json(['message' => 'Ticket not found'], 404);

            if ((int)($st['company_id'] ?? 0) !== $cid) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            if (($st['status'] ?? '') !== 'done' || empty($st['file'])) {
                return response()->json(['message' => 'Report not ready'], 409);
            }

            $path = (string) $st['file'];
            if (!Storage::disk('local')->exists($path)) {
                return response()->json(['message' => 'File missing'], 404);
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                return response()->json(['message' => 'View is only for PDF'], 422);
            }

            $absolute = Storage::disk('local')->path($path);

            return response()->file($absolute, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="vendor_summary_report.pdf"',
                'Cache-Control'       => 'private, max-age=0, must-revalidate',
                'Pragma'              => 'public',
            ]);
        } catch (\Throwable $e) {
            \Log::error('VendorSummaryReportController@view failed', [
                'ticket' => $ticket,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Server Error',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
