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
        $companyId = (int)($request->input('company_id') ?? auth()->user()?->company_id ?? 0);
        $fsFilter  = strtoupper((string)($request->input('fs') ?? 'ALL')); // ALL|ACT|BS|IS

        $rows = DB::table('account_code as ac')
            ->leftJoin('account_main as am', 'am.main_acct_code', '=', 'ac.main_acct_code')
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
            ->when($companyId, fn($q) => $q->where('ac.company_id', $companyId))
            ->where('ac.active_flag', 1)
            ->when($fsFilter !== 'ALL', function ($q) use ($fsFilter) {
                if ($fsFilter === 'ACT') {
                    $q->where('ac.exclude', 0);
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
    public function status(string $ticket)
    {
        $state = Cache::get($this->cacheKey($ticket));
        if (!$state) {
            return response()->json(['status' => 'missing', 'message' => 'Ticket not found'], 404);
        }
        return response()->json($state);
    }

    /** Inline view (PDF only; Excel forced to download). */
    public function view(string $ticket)
    {
        $state = Cache::get($this->cacheKey($ticket));
        if (!$state || ($state['status'] ?? null) !== 'done') {
            return response()->json(['error' => 'File not ready'], 400);
        }

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
    public function download(string $ticket)
    {
        $state = Cache::get($this->cacheKey($ticket));
        if (!$state || ($state['status'] ?? null) !== 'done') {
            return response()->json(['error' => 'File not ready'], 400);
        }

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
}
