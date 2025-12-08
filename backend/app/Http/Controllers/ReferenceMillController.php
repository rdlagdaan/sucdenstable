<?php

namespace App\Http\Controllers;

use App\Models\Mill;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

class ReferenceMillController extends Controller
{
    /** List mills (company-scoped, search + pagination) */
    public function index(Request $request): JsonResponse
    {
        $perPage   = max(1, min((int)$request->input('per_page', 10), 100));
        $search    = trim((string)$request->input('search',''));
        $companyId = $this->resolveCompanyId($request);

        $q = Mill::query()->where('company_id', (int)$companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('mill_id', 'ILIKE', $like)
                  ->orWhere('mill_name', 'ILIKE', $like)
                  ->orWhere('prefix', 'ILIKE', $like);
            });
        }

        return response()->json(
            $q->orderBy('created_at','desc')->paginate($perPage)
        );
    }

    /** Create mill (unique per company on mill_id) */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $data = $request->validate([
            'mill_id'   => [
                'required','string','max:50',
                Rule::unique('mill_list','mill_id')->where(fn($q)=>$q->where('company_id',(int)$companyId)),
            ],
            'mill_name' => 'required|string|max:255',
            'prefix'    => 'nullable|string|max:25',
        ]);

        $payload = [
            'mill_id'       => strtoupper(trim($data['mill_id'])),
            'mill_name'     => trim($data['mill_name']),
            'prefix'        => isset($data['prefix']) ? strtoupper(trim($data['prefix'])) : null,
            'company_id'    => (int)$companyId,
            'workstation_id'=> $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname(),
            'user_id'       => Auth::id() ? (int)Auth::id() : (env('DEFAULT_USER_ID') ? (int)env('DEFAULT_USER_ID') : null),
        ];

        try {
            $row = Mill::create($payload);
            return response()->json(['status'=>'success','message'=>'Mill created','data'=>$row], 201);
        } catch (QueryException $e) {
            return $this->pgError($e, 'creating mill');
        }
    }

    /** Update (scoped by company + id) */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $row = Mill::where('id',$id)->where('company_id',(int)$companyId)->firstOrFail();

        $data = $request->validate([
            'mill_id'   => [
                'required','string','max:50',
                Rule::unique('mill_list','mill_id')->ignore($id)
                    ->where(fn($q)=>$q->where('company_id',(int)$companyId)),
            ],
            'mill_name' => 'required|string|max:255',
            'prefix'    => 'nullable|string|max:25',
        ]);

        $payload = [
            'mill_id'       => strtoupper(trim($data['mill_id'])),
            'mill_name'     => trim($data['mill_name']),
            'prefix'        => isset($data['prefix']) ? strtoupper(trim($data['prefix'])) : null,
            'company_id'    => (int)$companyId,
            'workstation_id'=> $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname(),
            'user_id'       => Auth::id() ? (int)Auth::id() : (env('DEFAULT_USER_ID') ? (int)env('DEFAULT_USER_ID') : null),
        ];

        try {
            $row->update($payload);
            return response()->json(['status'=>'success','message'=>'Mill updated','data'=>$row]);
        } catch (QueryException $e) {
            return $this->pgError($e,'updating mill');
        }
    }

    /** Delete (scoped by company + id) */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $row = Mill::where('id',$id)->where('company_id',(int)$companyId)->firstOrFail();
        $row->delete();
        return response()->json(['status'=>'success','message'=>'Mill deleted']);
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

    private function pgError(QueryException $e, string $action)
    {
        $detail = $e->errorInfo[2] ?? $e->getMessage();
        $state  = $e->errorInfo[0] ?? null;

        if (str_contains($detail,'23505') || $state === '23505')
            return response()->json(['status'=>'error','message'=>'Duplicate value. (company_id, mill_id) must be unique.','detail'=>$detail], 409);

        if (str_contains($detail,'23503') || $state === '23503')
            return response()->json(['status'=>'error','message'=>'Foreign key violation.','detail'=>$detail], 422);

        if (str_contains($detail,'23502') || $state === '23502')
            return response()->json(['status'=>'error','message'=>'Missing required value.','detail'=>$detail], 422);

        return response()->json(['status'=>'error','message'=>"Database error while {$action}.",'detail'=>$detail], 500);
    }
}
