<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use App\Jobs\BuildReceiptRegister;

class ReceiptRegisterController extends Controller
{
    public function months(): JsonResponse
    {
        $rows = DB::table('month_list')
            ->selectRaw('month_num, month_desc')
            ->orderByRaw('CAST(NULLIF(month_num, \'\') AS integer)')
            ->get();

        return response()->json($rows);
    }

    public function years(): JsonResponse
    {
        $years = DB::table('cash_receipts')
            ->selectRaw("DISTINCT EXTRACT(YEAR FROM receipt_date)::int AS year")
            ->orderBy('year','desc')
            ->pluck('year')
            ->all();

        if (empty($years)) {
            $y = (int) date('Y');
            $years = range($y, $y - 5);
        }

        return response()->json(array_map(fn($yr)=>['year'=>(int)$yr], $years));
    }

    public function start(Request $req): JsonResponse
    {
        $v = $req->validate([
            'month'  => 'required|integer|min:1|max:12',
            'year'   => 'required|integer|min:1900|max:3000',
            'format' => 'required|string|in:pdf,excel',
            'query'  => 'nullable|string|max:200',
        ]);

        $fmt = $v['format']; // keep 'pdf' | 'excel' (Excel writer still saves .xls)

        $ticket     = Str::uuid()->toString();
        $companyId  = $req->user()->company_id ?? null;
        $userId     = $req->user()->id ?? null;

        Cache::put("rr:$ticket", [
            'status'     => 'queued',
            'progress'   => 0,
            'format'     => $fmt, // 'pdf' | 'excel'
            'file'       => null,
            'error'      => null,
            'period'     => [(int)$v['month'], (int)$v['year']],
            'user_id'    => $userId,
            'company_id' => $companyId,
            'query'      => $v['query'] ?? null,
        ], now()->addHours(2));

        // Octane-safe
        BuildReceiptRegister::dispatchAfterResponse(
            ticket:    $ticket,
            month:     (int)$v['month'],
            year:      (int)$v['year'],
            format:    $fmt,
            companyId: $companyId,
            userId:    $userId,
            query:     $v['query'] ?? null
        );

        return response()->json(['ticket' => $ticket]);
    }

    public function status(string $ticket)
    {
        $state = Cache::get("rr:$ticket");
        if (!$state) return response()->json(['error'=>'not_found'], 404);
        return response()->json($state);
    }

    public function download(string $ticket)
    {
        $state = Cache::get("rr:$ticket");
        if (!$state) return response()->json(['error'=>'not_found'], 404);
        if (($state['status'] ?? '') !== 'done' || empty($state['file'])) {
            return response()->json(['error'=>'not_ready'], 409);
        }
        if (!Storage::disk('local')->exists($state['file'])) {
            return response()->json(['error'=>'missing_file'], 410);
        }

        $absolute = Storage::disk('local')->path($state['file']);
        $name     = basename($state['file']);
        $mime     = ($state['format'] ?? 'pdf') === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.ms-excel';

        return response()->download($absolute, $name, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control'       => 'private, max-age=0, must-revalidate',
            'Pragma'              => 'public',
        ]);
    }

    public function view(string $ticket)
    {
        $state = Cache::get("rr:$ticket");
        if (!$state) return response()->json(['error'=>'not_found'], 404);
        if (($state['status'] ?? '') !== 'done' || empty($state['file'])) {
            return response()->json(['error'=>'not_ready'], 409);
        }
        if (($state['format'] ?? '') !== 'pdf') {
            return response()->json(['error'=>'only_pdf_view_supported'], 415);
        }
        if (!Storage::disk('local')->exists($state['file'])) {
            return response()->json(['error'=>'missing_file'], 410);
        }

        $absolute = Storage::disk('local')->path($state['file']);
        return response()->file($absolute, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($state['file']).'"',
            'Cache-Control'       => 'private, max-age=0, must-revalidate',
            'Pragma'              => 'public',
        ]);
    }
}
