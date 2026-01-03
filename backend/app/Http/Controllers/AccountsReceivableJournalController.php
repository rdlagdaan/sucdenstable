<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use App\Jobs\BuildAccountsReceivableJournal;
use Illuminate\Support\Facades\Schema;

class AccountsReceivableJournalController extends Controller
{


public function start(Request $req): JsonResponse
{
    $v = $req->validate([
        'start_date' => 'required|date',
        'end_date'   => 'required|date|after_or_equal:start_date',
        'format'     => 'required|string|in:pdf,excel,xls,xlsx',
        'query'      => 'nullable|string|max:200',
        'company_id' => 'required|integer|min:1',
    ]);

    $fmt = strtolower($v['format']);
    if ($fmt === 'excel' || $fmt === 'xlsx') {
        $fmt = 'xls';
    }

    $ticket    = Str::uuid()->toString();
    $companyId = (int) $v['company_id'];

    Cache::put("arj:$ticket", [
        'status'     => 'queued',
        'progress'   => 0,
        'format'     => $fmt,
        'file'       => null,
        'error'      => null,
        'range'      => [$v['start_date'], $v['end_date']],
        'query'      => $v['query'] ?? null,
        'company_id' => $companyId,
    ], now()->addHours(2));

BuildAccountsReceivableJournal::dispatchAfterResponse(
    ticket:    $ticket,
    startDate: $v['start_date'],
    endDate:   $v['end_date'],
    format:    $fmt,
    companyId: $companyId,
    query:     $v['query'] ?? null
);


    return response()->json(['ticket' => $ticket]);
}




public function status(string $ticket): JsonResponse
{
    $state = Cache::get("arj:$ticket");
    if (!$state) {
        return response()->json(['error' => 'not_found'], 404);
    }

    $companyId = (int) request()->query('company_id', 0);
    if ($companyId <= 0) {
        return response()->json(['error' => 'missing_company_scope'], 422);
    }

    if ((int)($state['company_id'] ?? 0) !== $companyId) {
        return response()->json(['error' => 'forbidden'], 403);
    }

    return response()->json($state);
}



public function download(string $ticket)
{
    $state = Cache::get("arj:$ticket");
    if (!$state) {
        return response()->json(['error' => 'not_found'], 404);
    }

    $companyId = (int) request()->query('company_id', 0);
    if ($companyId <= 0) {
        return response()->json(['error' => 'missing_company_scope'], 422);
    }

    if ((int)($state['company_id'] ?? 0) !== $companyId) {
        return response()->json(['error' => 'forbidden'], 403);
    }

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
    $state = Cache::get("arj:$ticket");
    if (!$state) {
        return response()->json(['error' => 'not_found'], 404);
    }

    $companyId = (int) request()->query('company_id', 0);
    if ($companyId <= 0) {
        return response()->json(['error' => 'missing_company_scope'], 422);
    }

    if ((int)($state['company_id'] ?? 0) !== $companyId) {
        return response()->json(['error' => 'forbidden'], 403);
    }

    if (($state['status'] ?? '') !== 'done' || empty($state['file'])) {
        return response()->json(['error' => 'not_ready'], 409);
    }

    if (($state['format'] ?? '') !== 'pdf') {
        return response()->json(['error' => 'only_pdf_view_supported'], 415);
    }

    if (!Storage::disk('local')->exists($state['file'])) {
        return response()->json(['error' => 'missing_file'], 410);
    }

    return response()->file(
        Storage::disk('local')->path($state['file']),
        [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($state['file']).'"',
            'Cache-Control'       => 'private, max-age=0, must-revalidate',
            'Pragma'              => 'public',
        ]
    );
}



}
