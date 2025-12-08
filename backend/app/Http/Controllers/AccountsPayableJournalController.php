<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use App\Jobs\BuildAccountsPayableJournal;

class AccountsPayableJournalController extends Controller
{
    public function start(Request $req): JsonResponse
    {
        $v = $req->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            // accept common spellings; normalize below
            'format'     => 'required|string|in:pdf,excel,xls,xlsx',
            'query'      => 'nullable|string|max:200',
        ]);

        // normalize → 'pdf' | 'xls'
        $fmt = strtolower($v['format']);
        if ($fmt === 'excel' || $fmt === 'xlsx') {
            $fmt = 'xls';
        }

        $ticket     = Str::uuid()->toString();
        $companyId  = $req->user()->company_id ?? null;
        $userId     = $req->user()->id ?? null;

        // seed cache (CRB-compatible shape)
        Cache::put("apj:$ticket", [
            'status'     => 'queued',
            'progress'   => 0,
            'format'     => $fmt, // 'pdf' | 'xls'
            'file'       => null,
            'error'      => null,
            'range'      => [$v['start_date'], $v['end_date']],
            'query'      => $v['query'] ?? null,
            'user_id'    => $userId,
            'company_id' => $companyId,
        ], now()->addHours(2));

        // queue after the HTTP response (works under Octane like CRB/GJB)
        BuildAccountsPayableJournal::dispatchAfterResponse(
            ticket:    $ticket,
            startDate: $v['start_date'],
            endDate:   $v['end_date'],
            format:    $fmt,         // 'pdf' | 'xls'
            companyId: $companyId,
            userId:    $userId,
            query:     $v['query'] ?? null
        );

        return response()->json(['ticket' => $ticket]);
    }

    public function status(string $ticket): JsonResponse
    {
        $state = Cache::get("apj:$ticket");
        if (!$state) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json($state);
    }

    public function download(string $ticket)
    {
        $state = Cache::get("apj:$ticket");
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
        $state = Cache::get("apj:$ticket");
        if (!$state) return response()->json(['error' => 'not_found'], 404);
        if (($state['status'] ?? '') !== 'done' || empty($state['file'])) {
            return response()->json(['error' => 'not_ready'], 409);
        }
        if (($state['format'] ?? '') !== 'pdf') {
            return response()->json(['error' => 'only_pdf_view_supported'], 415);
        }
        if (!Storage::disk('local')->exists($state['file'])) {
            return response()->json(['error' => 'missing_file'], 410);
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
