<?php
// app/Http/Controllers/ReferenceVendorController.php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Concerns\ExportsExcel;

class ReferenceVendorController extends Controller
{
    use ExportsExcel;

    /** List vendors (scoped by company_id) */
    public function index(Request $request): JsonResponse
    {
        $perPage   = max(1, min((int)$request->input('per_page', 10), 100));
        $search    = trim((string)$request->input('search',''));
        $companyId = $this->resolveCompanyId($request);   // int

        $q = Vendor::query()->where('company_id', (int)$companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('vend_code',      'ILIKE', $like)
                  ->orWhere('vend_name',    'ILIKE', $like)
                  ->orWhere('vendor_tin',   'ILIKE', $like)
                  ->orWhere('vendor_address','ILIKE', $like)
                  ->orWhere('workstation_id','ILIKE', $like);
            });
        }

        return response()->json(
            $q->orderBy('created_at', 'desc')->paginate($perPage)
        );
    }

    /** Create vendor (unique per company: (company_id, vend_code)) */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);   // int

        $data = $request->validate([
            'vend_code'      => [
                'required','string','max:25',
                Rule::unique('vendor_list','vend_code')
                    ->where(fn($q) => $q->where('company_id', (int)$companyId)),
            ],
            'vend_name'      => 'required|string|max:100',
            'vendor_tin'     => 'nullable|string|max:30',
            'vendor_address' => 'nullable|string|max:150',
            'vatable'        => 'nullable|string|max:10', // e.g., 'YES'/'NO'
        ]);

        $payload = [
            'vend_code'      => strtoupper(trim($data['vend_code'])),
            'vend_name'      => trim($data['vend_name']),
            'vendor_tin'     => isset($data['vendor_tin']) ? trim($data['vendor_tin']) : null,
            'vendor_address' => isset($data['vendor_address']) ? trim($data['vendor_address']) : null,
            'vatable'        => isset($data['vatable']) ? strtoupper(trim($data['vatable'])) : null,
            'company_id'     => (int)$companyId,
            'workstation_id' => $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname(),
            'user_id'        => Auth::id() ? (int)Auth::id() : (env('DEFAULT_USER_ID') ? (int)env('DEFAULT_USER_ID') : null),
        ];

        try {
            $row = Vendor::create($payload);
            return response()->json(['status'=>'success','message'=>'Vendor created','data'=>$row], 201);
        } catch (QueryException $e) {
            return $this->pgError($e, 'creating');
        }
    }

    /** Update (scoped by company_id + id) */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $row = Vendor::where('id', $id)
            ->where('company_id', (int)$companyId)
            ->firstOrFail();

        $data = $request->validate([
            'vend_code'      => [
                'required','string','max:25',
                Rule::unique('vendor_list','vend_code')
                    ->ignore($id)
                    ->where(fn($q) => $q->where('company_id', (int)$companyId)),
            ],
            'vend_name'      => 'required|string|max:100',
            'vendor_tin'     => 'nullable|string|max:30',
            'vendor_address' => 'nullable|string|max:150',
            'vatable'        => 'nullable|string|max:10',
        ]);

        $payload = [
            'vend_code'      => strtoupper(trim($data['vend_code'])),
            'vend_name'      => trim($data['vend_name']),
            'vendor_tin'     => isset($data['vendor_tin']) ? trim($data['vendor_tin']) : null,
            'vendor_address' => isset($data['vendor_address']) ? trim($data['vendor_address']) : null,
            'vatable'        => isset($data['vatable']) ? strtoupper(trim($data['vatable'])) : null,
            'company_id'     => (int)$companyId, // keep enforced
            'workstation_id' => $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname(),
            'user_id'        => Auth::id() ? (int)Auth::id() : (env('DEFAULT_USER_ID') ? (int)env('DEFAULT_USER_ID') : null),
        ];

        try {
            $row->update($payload);
            return response()->json(['status'=>'success','message'=>'Vendor updated','data'=>$row]);
        } catch (QueryException $e) {
            return $this->pgError($e, 'updating');
        }
    }

    /** Delete (scoped by company_id + id) */
    public function destroy(int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId(request());

        $row = Vendor::where('id', $id)
            ->where('company_id', (int)$companyId)
            ->firstOrFail();

        $row->delete();

        return response()->json(['status'=>'success','message'=>'Vendor deleted']);
    }

    /** Export Excel (scoped by company + search) */
    public function export(Request $request)
    {
        $search    = trim((string)$request->query('search',''));
        $companyId = $this->resolveCompanyId($request);

        $q = Vendor::query()->where('company_id', (int)$companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('vend_code',      'ILIKE', $like)
                  ->orWhere('vend_name',    'ILIKE', $like)
                  ->orWhere('vendor_tin',   'ILIKE', $like)
                  ->orWhere('vendor_address','ILIKE', $like)
                  ->orWhere('workstation_id','ILIKE', $like);
            });
        }

        $columns = [
            ['key'=>'vend_code',      'label'=>'Vendor Code', 'type'=>'string'],
            ['key'=>'vend_name',      'label'=>'Name',        'type'=>'string'],
            ['key'=>'vendor_tin',     'label'=>'TIN',         'type'=>'string'],
            ['key'=>'vendor_address', 'label'=>'Address',     'type'=>'string'],
            ['key'=>'vatable',        'label'=>'Vatable',     'type'=>'string'],
        ];

        return $this->exportExcel($request, $q, $columns, [
            'filename' => 'vendors',
            'sheet'    => 'Vendors',
        ]);
    }

    // ---------- helpers ----------

    /** Resolve effective company ID (int) */
    private function resolveCompanyId(Request $request): int
    {
        $resolved = $request->header('X-Company-ID')
            ?? (optional(Auth::user())->company_id)
            ?? env('DEFAULT_COMPANY_ID');

        if (!$resolved && Schema::hasTable('companies')) {
            $only = DB::table('companies')->select('id')->limit(2)->pluck('id');
            if ($only->count() === 1) $resolved = (string)$only->first();
        }

        if (!$resolved) {
            abort(response()->json(['status'=>'error','message'=>'Missing company_id (X-Company-ID or DEFAULT_COMPANY_ID).'], 422));
        }

        if (Schema::hasTable('companies')) {
            $exists = DB::table('companies')->where('id', $resolved)->exists();
            if (!$exists) abort(response()->json(['status'=>'error','message'=>'Invalid company_id.'], 422));
        }

        return (int)$resolved;
    }

    /** PG error mapper */
    private function pgError(QueryException $e, string $action)
    {
        $detail = $e->errorInfo[2] ?? $e->getMessage();
        $state  = $e->errorInfo[0] ?? null;

        if (str_contains($detail,'23505') || $state === '23505')
            return response()->json(['status'=>'error','message'=>'Duplicate value. (company_id, vend_code) must be unique.','detail'=>$detail], 409);

        if (str_contains($detail,'23503') || $state === '23503')
            return response()->json(['status'=>'error','message'=>'Foreign key violation.','detail'=>$detail], 422);

        if (str_contains($detail,'23502') || $state === '23502')
            return response()->json(['status'=>'error','message'=>'Missing required value.','detail'=>$detail], 422);

        return response()->json(['status'=>'error','message'=>"Database error while {$action} vendor.",'detail'=>$detail], 500);
    }
}
