<?php

namespace App\Http\Controllers;

use App\Jobs\BuildTrialBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrialBalanceController extends Controller
{
    /** Accounts for dropdowns (supports fs/company filters via query params). */
public function accounts(Request $request)
{
    // IMPORTANT: for GET params (?company_id=1&fs=ACT) use query() not input()
    $companyId = (int) ($request->query('company_id') ?? auth()->user()?->company_id ?? 0);
    $fsFilter  = strtoupper((string) ($request->query('fs') ?? 'ALL')); // ALL|ACT|BS|IS

    // Strongly recommended: never allow unscoped dropdown (prevents cross-company listing)
    if ($companyId <= 0) {
        return response()->json([], 400);
        // or: abort(400, 'company_id is required');
    }

    // Deduplicate account_main to ONE row per main_acct_code
    // (This fixes duplicated dropdown options caused by multiple matching rows in account_main.)
    $am = DB::table('account_main')
        ->select('main_acct_code', DB::raw('MAX(main_acct) as main_acct'))
        ->groupBy('main_acct_code');

    $rows = DB::table('account_code as ac')
        ->leftJoinSub($am, 'am', function ($join) {
            $join->on('am.main_acct_code', '=', 'ac.main_acct_code');
        })
        ->select([
            'ac.acct_code',
            'ac.acct_desc',
            'ac.acct_number',
            'ac.main_acct_code',
            DB::raw("COALESCE(am.main_acct, ac.main_acct) as main_acct"),
            'ac.fs',
            'ac.exclude',
            'ac.active_flag',
        ])
        ->where('ac.company_id', $companyId)     // ✅ company scoped
        ->where('ac.active_flag', 1)
        ->when($fsFilter !== 'ALL', function ($q) use ($fsFilter) {
            if ($fsFilter === 'ACT') {
                // exclude=0 OR exclude IS NULL (your current data is NULL)
                $q->where(function ($qq) {
                    $qq->where('ac.exclude', 0)
                       ->orWhereNull('ac.exclude');
                });
            } elseif ($fsFilter === 'BS') {
                $q->where('ac.fs', 'like', 'BS%');
            } elseif ($fsFilter === 'IS') {
                $q->where('ac.fs', 'like', 'IS%');
            }
        })
        // natural-ish sort like GL
        ->orderByRaw("
            CASE
              WHEN ac.acct_code ~ '^[0-9]+' THEN (substring(ac.acct_code from '^[0-9]+'))::int
              ELSE NULL
            END NULLS LAST
        ")
        ->orderBy('ac.acct_code')
        ->get();

    return response()->json($rows);
}



    /** Start TB job (returns ticket immediately). */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'startAccount' => 'required|string|max:75',
            'endAccount'   => 'required|string|max:75',
            'startDate'    => 'required|date',
            'endDate'      => 'required|date|after_or_equal:startDate',
            'format'       => 'nullable|in:pdf,xls,xlsx',
            'orientation'  => 'nullable|in:portrait,landscape',
            'company_id'   => 'nullable|integer',
            'fs'           => 'nullable|in:ALL,ACT,BS,IS',
        ]);

        $ticket      = (string) Str::uuid();
        $format      = $validated['format']      ?? 'pdf';
        $orientation = $validated['orientation'] ?? 'landscape';
        $companyId   = (int)($validated['company_id'] ?? (auth()->user()?->company_id ?? 0));
        $fs          = strtoupper($validated['fs'] ?? 'ALL');

        // seed state (frontend polls)
        Cache::put($this->cacheKey($ticket), [
            'status'       => 'queued',
            'progress'     => 0,
            'message'      => 'Queued',
            'format'       => $format,
            'orientation'  => $orientation,
            'startAccount' => $validated['startAccount'],
            'endAccount'   => $validated['endAccount'],
            'startDate'    => $validated['startDate'],
            'endDate'      => $validated['endDate'],
            'fs'           => $fs,
            'company_id'   => $companyId,
            'file_rel'     => null,
            'file_abs'     => null,
            'file_url'     => null,
            'file_disk'    => 'local',
            'download_name'=> null,
        ], now()->addHours(6));

        // run AFTER response (prevents 408)
        BuildTrialBalance::dispatchAfterResponse(
            ticket:      $ticket,
            startAccount:$validated['startAccount'],
            endAccount:  $validated['endAccount'],
            startDate:   $validated['startDate'],
            endDate:     $validated['endDate'],
            orientation: $orientation,
            format:      $format,
            companyId:   $companyId,
            fs:          $fs
        );

        return response()->json(['ticket' => $ticket]);
    }

    /** Poll job status. */
public function status(Request $request, string $ticket)
{
    $state = Cache::get($this->cacheKey($ticket));
    if (!$state) {
        return response()->json(['status' => 'missing', 'message' => 'Ticket not found'], 404);
    }

    // ✅ enforce tenant scope
    $this->requireTicketCompany($request, $state);

    return response()->json($state);
}






    /** Inline view (PDF only; Excel forced to download). */
public function view(Request $request, string $ticket)
{
    $state = Cache::get($this->cacheKey($ticket));
    if (!$state || ($state['status'] ?? null) !== 'done') {
        return response()->json(['error' => 'File not ready'], 400);
    }

    // ✅ enforce tenant scope
    $this->requireTicketCompany($request, $state);

    $name   = $state['download_name'] ?? basename($state['file_rel'] ?? 'trial-balance.pdf');
    $fmt    = strtolower($state['format'] ?? pathinfo($name, PATHINFO_EXTENSION) ?: 'pdf');
    $abs    = $state['file_abs'] ?? null;
    $disk   = $state['file_disk'] ?? 'local';
    $rel    = $state['file_rel'] ?? null;

    $excelCtype = ($fmt === 'xlsx')
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/vnd.ms-excel';

    if ($abs && is_file($abs)) {
        if ($fmt === 'pdf') {
            return response()->file($abs, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$name.'"',
            ]);
        }
        return response()->download($abs, $name, ['Content-Type' => $excelCtype]);
    }

    if ($rel && Storage::disk($disk)->exists($rel)) {
        $bytes = Storage::disk($disk)->get($rel);
        if ($fmt === 'pdf') {
            return Response::make($bytes, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$name.'"',
            ]);
        }
        return Response::make($bytes, 200, [
            'Content-Type'        => $excelCtype,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    return response()->json(['error' => 'File missing'], 404);
}


    /** Download (any format). */
public function download(Request $request, string $ticket)
{
    $state = Cache::get($this->cacheKey($ticket));
    if (!$state || ($state['status'] ?? null) !== 'done') {
        return response()->json(['error' => 'File not ready'], 400);
    }

    // ✅ enforce tenant scope
    $this->requireTicketCompany($request, $state);

    $name = $state['download_name'] ?? basename($state['file_rel'] ?? 'trial-balance.bin');
    $abs  = $state['file_abs'] ?? null;
    $disk = $state['file_disk'] ?? 'local';
    $rel  = $state['file_rel'] ?? null;

    if ($abs && is_file($abs)) {
        return response()->download($abs, $name);
    }
    if ($rel && Storage::disk($disk)->exists($rel)) {
        return Storage::disk($disk)->download($rel, $name);
    }
    return response()->json(['error' => 'File missing'], 404);
}


    private function cacheKey(string $ticket): string
    {
        return "tb:{$ticket}";
    }

private function requireTicketCompany(Request $request, array $state): int
{
    $companyId = (int)($request->query('company_id') ?? auth()->user()?->company_id ?? 0);

    $ticketCompanyId = (int)($state['company_id'] ?? 0);

    if ($companyId <= 0 || $ticketCompanyId <= 0 || $companyId !== $ticketCompanyId) {
        abort(403, 'Company mismatch.');
    }

    return $companyId;
}



}
