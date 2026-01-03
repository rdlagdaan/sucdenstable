<?php

namespace App\Http\Controllers;

use App\Jobs\BuildGeneralJournalBook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;

class GeneralJournalBookController extends Controller
{
    /**
     * Option A (multi-tenant): No auth required.
     * Enforce tenant scope using company_id on start/status/download/view.
     */
    public function start(Request $req): JsonResponse
    {
        $v = $req->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'format'     => 'required|string|in:pdf,excel,xls,xlsx',
            'query'      => 'nullable|string|max:200',
            'company_id' => 'required|integer|min:1', // ✅ REQUIRED (Option A)
        ]);

        // Normalize format -> 'pdf' | 'xls'
        $fmt = strtolower($v['format']);
        if ($fmt === 'excel' || $fmt === 'xlsx') {
            $fmt = 'xls';
        }

        $ticket    = Str::uuid()->toString();
        $companyId = (int) $v['company_id'];

        // Seed cache state (frontend polls this)
        Cache::put("gjb:$ticket", [
            'status'     => 'queued',
            'progress'   => 0,
            'format'     => $fmt, // pdf | xls
            'file'       => null,
            'error'      => null,
            'range'      => [$v['start_date'], $v['end_date']],
            'query'      => $v['query'] ?? null,
            'company_id' => $companyId,
        ], now()->addHours(2));

        // Dispatch job after response
        BuildGeneralJournalBook::dispatchAfterResponse(
            ticket:    $ticket,
            startDate: $v['start_date'],
            endDate:   $v['end_date'],
            format:    $fmt,
            companyId: $companyId,
            query:     $v['query'] ?? null
        );

        return response()->json(['ticket' => $ticket]);
    }

    public function status(Request $req, string $ticket): JsonResponse
    {
        $state = Cache::get("gjb:$ticket");
        if (!$state) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $cid = (int) $req->query('company_id', 0);
        if ($cid <= 0) {
            return response()->json(['error' => 'missing_company_id'], 422);
        }

        // ✅ tenant enforcement
        if ((int) ($state['company_id'] ?? 0) !== $cid) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return response()->json($state);
    }

    public function download(Request $req, string $ticket)
    {
        $state = Cache::get("gjb:$ticket");
        if (!$state) return response()->json(['error' => 'not_found'], 404);

        $cid = (int) $req->query('company_id', 0);
        if ($cid <= 0) {
            return response()->json(['error' => 'missing_company_id'], 422);
        }

        // ✅ tenant enforcement
        if ((int) ($state['company_id'] ?? 0) !== $cid) {
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

    public function view(Request $req, string $ticket)
    {
        $state = Cache::get("gjb:$ticket");
        if (!$state) return response()->json(['error' => 'not_found'], 404);

        $cid = (int) $req->query('company_id', 0);
        if ($cid <= 0) {
            return response()->json(['error' => 'missing_company_id'], 422);
        }

        // ✅ tenant enforcement
        if ((int) ($state['company_id'] ?? 0) !== $cid) {
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

        $absolute = Storage::disk('local')->path($state['file']);
        return response()->file($absolute, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($state['file']).'"',
            'Cache-Control'       => 'private, max-age=0, must-revalidate',
            'Pragma'              => 'public',
        ]);
    }
}
