<?php
// app/Http/Controllers/ReferencePlanterController.php

namespace App\Http\Controllers;

use App\Models\Planter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Concerns\ExportsExcel;

class ReferencePlanterController extends Controller
{
    use ExportsExcel;

    /** List planters (scoped by company_id STRING) */
    public function index(Request $request): JsonResponse
    {
        $perPage   = max(1, min((int)$request->input('per_page', 10), 100));
        $search    = trim((string)$request->input('search',''));
        $companyId = $this->resolveCompanyIdString($request); // string

        $q = Planter::query()->where('company_id', $companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('tin',           'ILIKE', $like)
                  ->orWhere('display_name', 'ILIKE', $like)
                  ->orWhere('last_name',    'ILIKE', $like)
                  ->orWhere('first_name',   'ILIKE', $like)
                  ->orWhere('middle_name',  'ILIKE', $like)
                  ->orWhere('address',      'ILIKE', $like)
                  ->orWhere('type',         'ILIKE', $like)
                  ->orWhere('workstation_id','ILIKE', $like);
            });
        }

        return response()->json(
            $q->orderBy('created_at', 'desc')->paginate($perPage)
        );
    }

    /** Create planter (unique per company: (company_id, tin)) */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyIdString($request);

        $data = $request->validate([
            'tin'          => [
                'required','string','max:25',
                Rule::unique('planters_list','tin')
                    ->where(fn($q) => $q->where('company_id', $companyId)),
            ],
            'last_name'    => 'required|string|max:100',
            'first_name'   => 'required|string|max:100',
            'middle_name'  => 'nullable|string|max:100',
            'display_name' => 'nullable|string|max:250',
            'address'      => 'nullable|string|max:1000',
            'type'         => 'nullable|string|max:2',
        ]);

        // Derive display_name if not provided
        $display = $data['display_name'] ?? trim(
            ($data['last_name'] ?? '') . ', ' . ($data['first_name'] ?? '') .
            (isset($data['middle_name']) && $data['middle_name'] !== '' ? ' ' . $data['middle_name'] : '')
        );
        $display = trim($display, " ,");

        // Respect varchar lengths
        $ws = $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname();
        $userId = Auth::id();
        $payload = [
            'tin'           => strtoupper(trim($data['tin'])),
            'last_name'     => trim($data['last_name']),
            'first_name'    => trim($data['first_name']),
            'middle_name'   => isset($data['middle_name']) ? trim($data['middle_name']) : null,
            'display_name'  => mb_substr($display, 0, 250),
            'company_id'    => (string)$companyId,
            'address'       => isset($data['address']) ? mb_substr(trim($data['address']), 0, 1000) : null,
            'type'          => isset($data['type']) ? strtoupper(mb_substr(trim($data['type']), 0, 2)) : null,
            'workstation_id'=> mb_substr($ws, 0, 25),
            'user_id'       => mb_substr((string)($userId ?? env('DEFAULT_USER_ID', '')), 0, 25) ?: null,
        ];

        try {
            $row = Planter::create($payload);
            return response()->json(['status'=>'success','message'=>'Planter created','data'=>$row], 201);
        } catch (QueryException $e) {
            return $this->pgError($e, 'creating');
        }
    }

    /** Update (scoped by company_id + id) */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyIdString($request);

        $row = Planter::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $data = $request->validate([
            'tin'          => [
                'required','string','max:25',
                Rule::unique('planters_list','tin')
                    ->ignore($id)
                    ->where(fn($q) => $q->where('company_id', $companyId)),
            ],
            'last_name'    => 'required|string|max:100',
            'first_name'   => 'required|string|max:100',
            'middle_name'  => 'nullable|string|max:100',
            'display_name' => 'nullable|string|max:250',
            'address'      => 'nullable|string|max:1000',
            'type'         => 'nullable|string|max:2',
        ]);

        $display = $data['display_name'] ?? trim(
            ($data['last_name'] ?? '') . ', ' . ($data['first_name'] ?? '') .
            (isset($data['middle_name']) && $data['middle_name'] !== '' ? ' ' . $data['middle_name'] : '')
        );
        $display = trim($display, " ,");

        $ws = $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname();
        $userId = Auth::id();
        $payload = [
            'tin'           => strtoupper(trim($data['tin'])),
            'last_name'     => trim($data['last_name']),
            'first_name'    => trim($data['first_name']),
            'middle_name'   => isset($data['middle_name']) ? trim($data['middle_name']) : null,
            'display_name'  => mb_substr($display, 0, 250),
            'company_id'    => (string)$companyId,
            'address'       => isset($data['address']) ? mb_substr(trim($data['address']), 0, 1000) : null,
            'type'          => isset($data['type']) ? strtoupper(mb_substr(trim($data['type']), 0, 2)) : null,
            'workstation_id'=> mb_substr($ws, 0, 25),
            'user_id'       => mb_substr((string)($userId ?? env('DEFAULT_USER_ID', '')), 0, 25) ?: null,
        ];

        try {
            $row->update($payload);
            return response()->json(['status'=>'success','message'=>'Planter updated','data'=>$row]);
        } catch (QueryException $e) {
            return $this->pgError($e, 'updating');
        }
    }

    /** Delete (scoped by company_id + id) */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyIdString($request);

        $row = Planter::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $row->delete();

        return response()->json(['status'=>'success','message'=>'Planter deleted']);
    }

    /** Export Excel (scoped by company + search) */
    public function export(Request $request)
    {
        $search    = trim((string)$request->query('search',''));
        $companyId = $this->resolveCompanyIdString($request);

        $q = Planter::query()->where('company_id', $companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('tin',           'ILIKE', $like)
                  ->orWhere('display_name', 'ILIKE', $like)
                  ->orWhere('last_name',    'ILIKE', $like)
                  ->orWhere('first_name',   'ILIKE', $like)
                  ->orWhere('middle_name',  'ILIKE', $like)
                  ->orWhere('address',      'ILIKE', $like)
                  ->orWhere('type',         'ILIKE', $like)
                  ->orWhere('workstation_id','ILIKE', $like);
            });
        }

        $columns = [
            ['key'=>'tin',          'label'=>'TIN',          'type'=>'string'],
            ['key'=>'display_name', 'label'=>'Display Name', 'type'=>'string'],
            ['key'=>'last_name',    'label'=>'Last Name',    'type'=>'string'],
            ['key'=>'first_name',   'label'=>'First Name',   'type'=>'string'],
            ['key'=>'middle_name',  'label'=>'Middle Name',  'type'=>'string'],
            ['key'=>'address',      'label'=>'Address',      'type'=>'string'],
            ['key'=>'type',         'label'=>'Type',         'type'=>'string'],
        ];

        return $this->exportExcel($request, $q, $columns, [
            'filename' => 'planters',
            'sheet'    => 'Planters',
        ]);
    }

    // ---------- helpers ----------

    /** Resolve effective company ID as STRING */
    private function resolveCompanyIdString(Request $request): string
    {
        $resolved = $request->header('X-Company-ID')
            ?? (optional(Auth::user())->company_id)   // if your users table stores it
            ?? env('DEFAULT_COMPANY_ID');

        if (!$resolved && Schema::hasTable('companies')) {
            $only = DB::table('companies')->select('id')->limit(2)->pluck('id');
            if ($only->count() === 1) $resolved = (string)$only->first();
        }

        if (!$resolved) {
            abort(response()->json(['status'=>'error','message'=>'Missing company_id (X-Company-ID or DEFAULT_COMPANY_ID).'], 422));
        }

        // If companies table exists, verify existence (id may be varchar; use string compare)
        if (Schema::hasTable('companies')) {
            $exists = DB::table('companies')->where('id', (string)$resolved)->exists();
            if (!$exists) abort(response()->json(['status'=>'error','message'=>'Invalid company_id.'], 422));
        }

        return (string)$resolved;
    }

    /** PG error mapper */
    private function pgError(QueryException $e, string $action)
    {
        $detail = $e->errorInfo[2] ?? $e->getMessage();
        $state  = $e->errorInfo[0] ?? null;

        if (str_contains($detail,'23505') || $state === '23505')
            return response()->json(['status'=>'error','message'=>'Duplicate value. (company_id, tin) must be unique.','detail'=>$detail], 409);

        if (str_contains($detail,'23503') || $state === '23503')
            return response()->json(['status'=>'error','message'=>'Foreign key violation.','detail'=>$detail], 422);

        if (str_contains($detail,'23502') || $state === '23502')
            return response()->json(['status'=>'error','message'=>'Missing required value.','detail'=>$detail], 422);

        return response()->json(['status'=>'error','message'=>"Database error while {$action} planter.",'detail'=>$detail], 500);
    }
}
