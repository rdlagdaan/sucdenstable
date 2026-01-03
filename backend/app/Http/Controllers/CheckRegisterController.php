<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use App\Jobs\BuildCheckRegister;

class CheckRegisterController extends Controller
{
    public function months(Request $req): JsonResponse
    {
        // ✅ Option A: require company_id (even if month_list is global, keep API consistent)
        $req->validate([
            'company_id' => 'required|integer|min:1',
        ]);

        $rows = DB::table('month_list')
            ->select('month_num','month_desc')
            ->orderByRaw('CAST(month_num AS integer)')
            ->get();

        return response()->json($rows);
    }

    public function years(Request $req): JsonResponse
    {
        // ✅ Option A: require company_id and scope results
        $v = $req->validate([
            'company_id' => 'required|integer|min:1',
        ]);

        $cid = (int) $v['company_id'];

        $years = DB::table('cash_disbursement')
            ->where('company_id', $cid)
            ->selectRaw("DISTINCT EXTRACT(YEAR FROM disburse_date)::int AS year")
            ->orderBy('year','desc')
            ->pluck('year')
            ->all();

        if (empty($years)) {
            $y = (int) date('Y');
            $years = range($y - 5, $y + 1);
            rsort($years);
        }

        return response()->json(array_map(fn($y) => ['year' => (int)$y], $years));
    }

    public function start(Request $req): JsonResponse
    {
        $v = $req->validate([
            'month'      => 'required|integer|min:1|max:12',
            'year'       => 'required|integer|min:1900|max:3000',
            'format'     => 'required|string|in:pdf,excel,xls,xlsx',
            'company_id' => 'required|integer|min:1', // ✅ Option A
        ]);

        $user = $req->user();
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // Normalize format to pdf|xls
        $fmt = strtolower($v['format']);
        if ($fmt === 'excel' || $fmt === 'xlsx') $fmt = 'xls';

        $ticket    = Str::uuid()->toString();
        $companyId = (int) $v['company_id'];
        $userId    = (int) ($user->id ?? 0);

        Cache::put("cr:$ticket", [
            'status'     => 'queued',
            'progress'   => 0,
            'format'     => $fmt, // pdf|xls
            'file'       => null,
            'error'      => null,
            'period'     => [(int)$v['month'], (int)$v['year']],
            'user_id'    => $userId,
            'company_id' => $companyId,
        ], now()->addHours(2));

        BuildCheckRegister::dispatchAfterResponse(
            ticket:    $ticket,
            month:     (int)$v['month'],
            year:      (int)$v['year'],
            format:    $fmt,        // pdf|xls
            companyId: $companyId,
            userId:    $userId
        );

        return response()->json(['ticket' => $ticket]);
    }

    public function status(Request $req, string $ticket): JsonResponse
    {
        $state = Cache::get("cr:$ticket");
        if (!$state) return response()->json(['error' => 'not_found'], 404);

        $user = $req->user();
        if (!$user) return response()->json(['error' => 'unauthenticated'], 401);

        $uid = (int) ($user->id ?? 0);

        // ✅ enforce same user ticket access (Option A style)
        if ((int)($state['user_id'] ?? 0) !== $uid) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return response()->json($state);
    }

    public function download(Request $req, string $ticket)
    {
        $state = Cache::get("cr:$ticket");
        if (!$state) return response()->json(['error' => 'not_found'], 404);

        $user = $req->user();
        if (!$user) return response()->json(['error' => 'unauthenticated'], 401);

        $uid = (int) ($user->id ?? 0);
        if ((int)($state['user_id'] ?? 0) !== $uid) {
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
        $state = Cache::get("cr:$ticket");
        if (!$state) return response()->json(['error'=>'not_found'], 404);

        $user = $req->user();
        if (!$user) return response()->json(['error' => 'unauthenticated'], 401);

        $uid = (int) ($user->id ?? 0);
        if ((int)($state['user_id'] ?? 0) !== $uid) {
            return response()->json(['error' => 'forbidden'], 403);
        }

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
