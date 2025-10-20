<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Jobs\BuildCashDisbursementBook;

class CashDisbursementBookController extends Controller
{
    public function start(Request $req)
    {
        $v = $req->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'format'     => 'required|in:pdf,excel',
        ]);

        $ticket = Str::uuid()->toString();

        Cache::put("cdb:$ticket", [
            'status'     => 'queued',
            'progress'   => 0,
            'format'     => $v['format'],
            'file'       => null,
            'error'      => null,
            'range'      => [$v['start_date'], $v['end_date']],
            'user_id'    => $req->user()->id ?? null,
            'company_id' => $req->user()->company_id ?? null,
        ], now()->addHours(2));

        BuildCashDisbursementBook::dispatch(
            ticket:    $ticket,
            startDate: $v['start_date'],
            endDate:   $v['end_date'],
            format:    $v['format'],       // pdf|excel
            companyId: $req->user()->company_id ?? null,
            userId:    $req->user()->id ?? null
        );

        return response()->json(['ticket' => $ticket]);
    }

    public function status(string $ticket)
    {
        $state = Cache::get("cdb:$ticket");
        if (!$state) return response()->json(['error' => 'not_found'], 404);
        return response()->json($state);
    }

    public function download(string $ticket)
    {
        $state = Cache::get("cdb:$ticket");
        if (!$state) return response()->json(['error' => 'not_found'], 404);
        if (($state['status'] ?? '') !== 'done' || empty($state['file'])) {
            return response()->json(['error' => 'not_ready'], 409);
        }
        if (!Storage::disk('local')->exists($state['file'])) {
            return response()->json(['error' => 'missing_file'], 410);
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
        $state = Cache::get("cdb:$ticket");
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

        $absolute = Storage::disk('local')->path($state['file']); // storage/app (root may be /private per your config)
        return response()->file($absolute, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($state['file']).'"',
            'Cache-Control'       => 'private, max-age=0, must-revalidate',
            'Pragma'              => 'public',
        ]);
    }
}
