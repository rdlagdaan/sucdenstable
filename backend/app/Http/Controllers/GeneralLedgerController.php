<?php

namespace App\Http\Controllers;

use App\Jobs\BuildGeneralLedger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GeneralLedgerController extends Controller
{
    /** Accounts endpoint for the two combo boxes. */
public function accounts(Request $request)
{
    // ✅ Old behavior: company_id comes from request (frontend sends it)
    $companyId = (int) $request->query('company_id', 0);

    if ($companyId <= 0) {
        return response()->json(['error' => 'Missing company_id'], 422);
    }

    // ✅ Prevent duplicate rows from account_main
    $am = DB::table('account_main')
        ->selectRaw("main_acct_code, MAX(main_acct) as main_acct")
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
        ])
        ->where('ac.company_id', $companyId)   // ✅ hard scope
        ->where('ac.active_flag', 1)
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



    /** Start report job. */
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
        ]);

        $ticket      = (string) Str::uuid();
        $format      = $validated['format']      ?? 'pdf';
        $orientation = $validated['orientation'] ?? 'landscape';
$companyId = (int) ($validated['company_id'] ?? 0);
if ($companyId <= 0) {
    return response()->json(['error' => 'Missing company_id'], 422);
}


        // Seed initial job state in cache (frontend polls this)
        $state = [
            'status'       => 'queued',
            'progress'     => 0,
            'message'      => 'Queued',
            'format'       => $format,
            'orientation'  => $orientation,
            'startAccount' => $validated['startAccount'],
            'endAccount'   => $validated['endAccount'],
            'startDate'    => $validated['startDate'],
            'endDate'      => $validated['endDate'],
            'file_rel'     => null,
            'file_abs'     => null,
            'file_url'     => null,
            'file_disk'    => 'local',
            'download_name'=> null,
        ];
        Cache::put($this->cacheKey($ticket), $state, now()->addHours(6));

        // Dispatch job
        BuildGeneralLedger::dispatchSync(
            ticket: $ticket,
            startAccount: $validated['startAccount'],
            endAccount:   $validated['endAccount'],
            startDate:    $validated['startDate'],
            endDate:      $validated['endDate'],
            orientation:  $orientation,
            format:       $format,
            companyId:    $companyId
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

    $name   = $state['download_name'] ?? basename($state['file_rel'] ?? 'report.pdf');
    $fmt    = strtolower($state['format'] ?? pathinfo($name, PATHINFO_EXTENSION) ?: 'pdf');
    $abs    = $state['file_abs'] ?? null;       // optional: if you stored it
    $disk   = $state['file_disk'] ?? 'local';
    $rel    = $state['file_rel'] ?? null;

    // Pick proper content type for Excel
    $excelCtype = ($fmt === 'xlsx')
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/vnd.ms-excel';

    // Prefer absolute path if present
    if ($abs && is_file($abs)) {
        if ($fmt === 'pdf') {
            return response()->file($abs, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$name.'"',
            ]);
        }
        // Excel → download with correct MIME
        return response()->download($abs, $name, [
            'Content-Type' => $excelCtype,
        ]);
    }

    // Fallback: read via Storage disk
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


    /** Download endpoint (any format). */
    public function download(string $ticket)
    {
        $state = Cache::get($this->cacheKey($ticket));
        if (!$state || ($state['status'] ?? null) !== 'done') {
            return response()->json(['error' => 'File not ready'], 400);
        }

        $name = $state['download_name'] ?? basename($state['file_rel'] ?? 'report.bin');
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
        return "gl:{$ticket}";
    }

 



}
