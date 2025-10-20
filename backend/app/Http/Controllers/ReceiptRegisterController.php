<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Jobs\BuildReceiptRegister; // <-- from the earlier backend we generated

class ReceiptRegisterController extends Controller
{
    /** Month options from month_list (ordered numerically). */
    public function months()
    {
        $rows = DB::table('month_list')
            ->selectRaw("month_num, month_desc")
            ->orderByRaw("CAST(NULLIF(month_num,'') AS integer)")
            ->get();

        return response()->json($rows);
    }

    /** Year options from cash_receipts.receipt_date (desc). */
    public function years()
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

        // return [{year: 2025}, ...]
        return response()->json(array_map(fn($yr)=>['year'=>(int)$yr], $years));
    }

    /** Start the report build (pdf|excel). */
    public function start(Request $req)
    {
        $v = $req->validate([
            'month'  => 'required|integer|min:1|max:12',
            'year'   => 'required|integer|min:1900|max:3000',
            'format' => 'required|in:pdf,excel',
            'query'  => 'nullable|string|max:200',
        ]);

        $ticket = Str::uuid()->toString();
        Cache::put("rr:$ticket", [
            'status'     => 'queued',
            'progress'   => 0,
            'format'     => $v['format'],
            'file'       => null,
            'error'      => null,
            'period'     => [$v['month'], $v['year']],
            'user_id'    => $req->user()->id ?? null,
            'company_id' => $req->user()->company_id ?? null,
            'query'      => $v['query'] ?? null,
        ], now()->addHours(2));

        BuildReceiptRegister::dispatch(
            ticket:    $ticket,
            month:     (int)$v['month'],
            year:      (int)$v['year'],
            format:    $v['format'],
            companyId: $req->user()->company_id ?? null,
            userId:    $req->user()->id ?? null,
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
