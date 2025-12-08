<?php

namespace App\Http\Controllers;

use App\Models\Mill;
use App\Models\MillRate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

class ReferenceMillRateController extends Controller
{
    /** List rates for a mill (company-scoped) */
    public function index(Request $request, int $millId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $mill = Mill::where('id',$millId)->where('company_id',(int)$companyId)->firstOrFail();

        $rates = MillRate::where('mill_record_id',$mill->id)
            ->orderBy('crop_year','desc')
            ->get();

        return response()->json($rates);
    }

    /** Create a new crop-year rate for a mill */
    public function store(Request $request, int $millId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $mill = Mill::where('id',$millId)->where('company_id',(int)$companyId)->firstOrFail();

        $data = $request->validate([
            'crop_year'      => 'required|string|max:10',
            'insurance_rate' => 'nullable|numeric|min:0',
            'storage_rate'   => 'nullable|numeric|min:0',
            'days_free'      => 'nullable|integer|min:0',
            'market_value'   => 'nullable|numeric|min:0',
            'ware_house'     => 'nullable|string|max:100',
            'shippable_flag' => 'boolean',
        ]);

        // Enforce uniqueness: one row per (mill_record_id, crop_year)
        $exists = MillRate::where('mill_record_id',$mill->id)
            ->where('crop_year', $data['crop_year'])
            ->exists();
        if ($exists) {
            return response()->json(['status'=>'error','message'=>'This crop year already exists for the selected mill.'], 409);
        }

        $payload = [
            'mill_record_id' => $mill->id,
            'mill_id'        => $mill->mill_id,
            'crop_year'      => $data['crop_year'],
            'insurance_rate' => $data['insurance_rate'] ?? null,
            'storage_rate'   => $data['storage_rate'] ?? null,
            'days_free'      => $data['days_free'] ?? null,
            'market_value'   => $data['market_value'] ?? null,
            'ware_house'     => $data['ware_house'] ?? null,
            'shippable_flag' => (bool)($data['shippable_flag'] ?? false),
            'locked'         => 0,
            'workstation_id' => $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname(),
            'user_id'        => Auth::id() ? (int)Auth::id() : (env('DEFAULT_USER_ID') ? (int)env('DEFAULT_USER_ID') : null),
        ];

        try {
            $row = MillRate::create($payload);
            return response()->json(['status'=>'success','message'=>'Rate created','data'=>$row], 201);
        } catch (QueryException $e) {
            return $this->pgError($e,'creating rate');
        }
    }

    /** Update a crop-year rate (blocked if locked) */
    public function update(Request $request, int $millId, int $rateId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $mill = Mill::where('id',$millId)->where('company_id',(int)$companyId)->firstOrFail();

        $row = MillRate::where('id',$rateId)->where('mill_record_id',$mill->id)->firstOrFail();

        if (($row->locked ?? 0) != 0) {
            return response()->json(['status'=>'error','message'=>'This record is locked and cannot be edited.'], 423);
        }

        $data = $request->validate([
            'crop_year'      => 'sometimes|string|max:10', // not applied here; kept same
            'insurance_rate' => 'nullable|numeric|min:0',
            'storage_rate'   => 'nullable|numeric|min:0',
            'days_free'      => 'nullable|integer|min:0',
            'market_value'   => 'nullable|numeric|min:0',
            'ware_house'     => 'nullable|string|max:100',
            'shippable_flag' => 'boolean',
        ]);

        $payload = [
            'insurance_rate' => $data['insurance_rate'] ?? null,
            'storage_rate'   => $data['storage_rate'] ?? null,
            'days_free'      => $data['days_free'] ?? null,
            'market_value'   => $data['market_value'] ?? null,
            'ware_house'     => $data['ware_house'] ?? null,
            'shippable_flag' => (bool)($data['shippable_flag'] ?? false),
            'workstation_id' => $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname(),
            'user_id'        => Auth::id() ? (int)Auth::id() : (env('DEFAULT_USER_ID') ? (int)env('DEFAULT_USER_ID') : null),
        ];

        try {
            $row->update($payload);
            return response()->json(['status'=>'success','message'=>'Rate updated','data'=>$row]);
        } catch (QueryException $e) {
            return $this->pgError($e,'updating rate');
        }
    }

    /** Delete a crop-year rate (blocked if locked) */
    public function destroy(Request $request, int $millId, int $rateId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $mill = Mill::where('id',$millId)->where('company_id',(int)$companyId)->firstOrFail();

        $row = MillRate::where('id',$rateId)->where('mill_record_id',$mill->id)->firstOrFail();

        if (($row->locked ?? 0) != 0) {
            return response()->json(['status'=>'error','message'=>'This record is locked and cannot be deleted.'], 423);
        }

        $row->delete();

        return response()->json(['status'=>'success','message'=>'Rate deleted']);
    }

    /** Lock (supervisor-only; implement your gate/permission as needed) */
    public function lock(Request $request, int $millId, int $rateId): JsonResponse
    {
        $this->assertSupervisor(); // replace with your real permission check

        $companyId = $this->resolveCompanyId($request);
        $mill = Mill::where('id',$millId)->where('company_id',(int)$companyId)->firstOrFail();
        $row = MillRate::where('id',$rateId)->where('mill_record_id',$mill->id)->firstOrFail();

        $row->update(['locked'=>1]);

        return response()->json(['status'=>'success','message'=>'Rate locked']);
    }

    /** Unlock (supervisor-only; requires reason) */
    public function unlock(Request $request, int $millId, int $rateId): JsonResponse
    {
        $this->assertSupervisor(); // replace with your real permission check

        $companyId = $this->resolveCompanyId($request);
        $mill = Mill::where('id',$millId)->where('company_id',(int)$companyId)->firstOrFail();
        $row = MillRate::where('id',$rateId)->where('mill_record_id',$mill->id)->firstOrFail();

        $request->validate(['reason'=>'required|string|max:255']);
        // You can persist reason into an audit log table here.

        $row->update(['locked'=>0]);

        return response()->json(['status'=>'success','message'=>'Rate unlocked']);
    }

    // --- helpers (same style as Vendors) ---
    private function resolveCompanyId(Request $request): int
    {
        $resolved = $request->header('X-Company-ID')
            ?? (optional(auth()->user())->company_id)
            ?? env('DEFAULT_COMPANY_ID');

        if (!$resolved && Schema::hasTable('companies')) {
            $only = DB::table('companies')->select('id')->limit(2)->pluck('id');
            if ($only->count() === 1) $resolved = (string)$only->first();
        }

        if (!$resolved) abort(response()->json(['status'=>'error','message'=>'Missing company_id (X-Company-ID or DEFAULT_COMPANY_ID).'], 422));

        if (Schema::hasTable('companies')) {
            $exists = DB::table('companies')->where('id',$resolved)->exists();
            if (!$exists) abort(response()->json(['status'=>'error','message'=>'Invalid company_id.'], 422));
        }

        return (int)$resolved;
    }

    private function assertSupervisor(): void
    {
        // TODO: Replace with your real authorization (Spatie roles/permissions, etc.)
        // Example check:
        // if (!auth()->user() || !auth()->user()->hasRole('supervisor')) {
        //     abort(response()->json(['status'=>'error','message'=>'Supervisor permission required.'], 403));
        // }
    }

    private function pgError(QueryException $e, string $action)
    {
        $detail = $e->errorInfo[2] ?? $e->getMessage();
        $state  = $e->errorInfo[0] ?? null;

        if (str_contains($detail,'23505') || $state === '23505')
            return response()->json(['status'=>'error','message'=>'Duplicate value. (mill_record_id, crop_year) must be unique.','detail'=>$detail], 409);

        if (str_contains($detail,'23503') || $state === '23503')
            return response()->json(['status'=>'error','message'=>'Foreign key violation.','detail'=>$detail], 422);

        if (str_contains($detail,'23502') || $state === '23502')
            return response()->json(['status'=>'error','message'=>'Missing required value.','detail'=>$detail], 422);

        return response()->json(['status'=>'error','message'=>"Database error while {$action}.",'detail'=>$detail], 500);
    }
}
