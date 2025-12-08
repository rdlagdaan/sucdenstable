<?php
// app/Http/Controllers/ReferenceCustomerController.php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Concerns\ExportsExcel;

class ReferenceCustomerController extends Controller
{
    use ExportsExcel;

    /**
     * List customers (scoped by company_id) with search + pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage   = max(1, min((int) $request->input('per_page', 10), 100));
        $search    = trim((string) $request->input('search', ''));
        $companyId = $this->resolveCompanyId($request);

        $q = Customer::query()
            ->where('company_id', (string) $companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('cust_id', 'ILIKE', $like)
                  ->orWhere('cust_name', 'ILIKE', $like)
                  ->orWhere('workstation_id', 'ILIKE', $like);
            });
        }

        return response()->json(
            $q->orderBy('created_at', 'desc')->paginate($perPage)
        );
    }

    /**
     * Create a customer (company-scoped uniqueness on cust_id).
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $data = $request->validate([
            'cust_id'   => [
                'required', 'string', 'max:50',
                Rule::unique('customer_list', 'cust_id')
                    ->where(fn ($q) => $q->where('company_id', (string) $companyId)),
            ],
            'cust_name' => 'required|string|max:250',
        ]);

        $data['cust_id']        = strtoupper(trim($data['cust_id']));
        $data['cust_name']      = trim($data['cust_name']);
        $data['company_id']     = (string) $companyId; // varchar(25)
        $data['workstation_id'] = $request->header('X-Workstation-ID')
            ?? $request->ip()
            ?? gethostname();
        $data['user_id']        = Auth::id()
            ? (string) Auth::id()
            : (env('DEFAULT_USER_ID') ? (string) env('DEFAULT_USER_ID') : null);

        try {
            $row = Customer::create($data);
            return response()->json([
                'status'  => 'success',
                'message' => 'Customer created',
                'data'    => $row,
            ], 201);
        } catch (QueryException $e) {
            return $this->pgError($e, 'creating');
        }
    }

    /**
     * Update a customer (scoped by company_id and id).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);

        // Load the row only within this company
        $row = Customer::where('id', $id)
            ->where('company_id', (string) $companyId)
            ->firstOrFail();

        $data = $request->validate([
            'cust_id'   => [
                'required', 'string', 'max:50',
                Rule::unique('customer_list', 'cust_id')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('company_id', (string) $companyId)),
            ],
            'cust_name' => 'required|string|max:250',
        ]);

        $data['cust_id']        = strtoupper(trim($data['cust_id']));
        $data['cust_name']      = trim($data['cust_name']);
        $data['company_id']     = (string) $companyId; // keep enforced
        $data['workstation_id'] = $request->header('X-Workstation-ID')
            ?? $request->ip()
            ?? gethostname();
        $data['user_id']        = Auth::id()
            ? (string) Auth::id()
            : (env('DEFAULT_USER_ID') ? (string) env('DEFAULT_USER_ID') : null);

        try {
            $row->update($data);
            return response()->json([
                'status'  => 'success',
                'message' => 'Customer updated',
                'data'    => $row,
            ]);
        } catch (QueryException $e) {
            return $this->pgError($e, 'updating');
        }
    }

    /**
     * Delete a customer (scoped by company_id and id).
     */
    public function destroy(int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId(request());

        $row = Customer::where('id', $id)
            ->where('company_id', (string) $companyId)
            ->firstOrFail();

        $row->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Customer deleted',
        ]);
    }

    /**
     * Stream Excel export (scoped by company_id + optional search).
     */
    public function export(Request $request)
    {
        $search    = trim((string) $request->query('search', ''));
        $companyId = $this->resolveCompanyId($request);

        $q = Customer::query()
            ->where('company_id', (string) $companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('cust_id', 'ILIKE', $like)
                  ->orWhere('cust_name', 'ILIKE', $like)
                  ->orWhere('workstation_id', 'ILIKE', $like);
            });
        }

        $columns = [
            ['key' => 'cust_id',        'label' => 'Customer ID', 'type' => 'string'],
            ['key' => 'cust_name',      'label' => 'Name',        'type' => 'string'],
            ['key' => 'workstation_id', 'label' => 'Workstation', 'type' => 'string'],
        ];

        return $this->exportExcel($request, $q, $columns, [
            'filename' => 'customers',
            'sheet'    => 'Customers',
        ]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Resolve the effective company_id for this request.
     * Header → Auth user → DEFAULT_COMPANY_ID → single-row fallback in companies table.
     */
    private function resolveCompanyId(Request $request): string
    {
        $resolved = $request->header('X-Company-ID')
            ?? (optional(Auth::user())->company_id)
            ?? env('DEFAULT_COMPANY_ID');

        if (!$resolved && Schema::hasTable('companies')) {
            $only = DB::table('companies')->select('id')->limit(2)->pluck('id');
            if ($only->count() === 1) {
                $resolved = (string) $only->first();
            }
        }

        if (!$resolved) {
            abort(response()->json([
                'status'  => 'error',
                'message' => 'Missing company_id (X-Company-ID or DEFAULT_COMPANY_ID).',
            ], 422));
        }

        if (Schema::hasTable('companies')) {
            $exists = DB::table('companies')->where('id', $resolved)->exists();
            if (!$exists) {
                abort(response()->json([
                    'status'  => 'error',
                    'message' => 'Invalid company_id.',
                ], 422));
            }
        }

        return (string) $resolved;
    }

    /**
     * Map common Postgres errors to API responses.
     */
    private function pgError(QueryException $e, string $action)
    {
        $detail = $e->errorInfo[2] ?? $e->getMessage();
        $state  = $e->errorInfo[0] ?? null;

        if (str_contains($detail, '23505') || $state === '23505') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Duplicate value. (company_id, cust_id) must be unique.',
                'detail'  => $detail,
            ], 409);
        }

        if (str_contains($detail, '23503') || $state === '23503') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Foreign key violation.',
                'detail'  => $detail,
            ], 422);
        }

        if (str_contains($detail, '23502') || $state === '23502') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing required value.',
                'detail'  => $detail,
            ], 422);
        }

        return response()->json([
            'status'  => 'error',
            'message' => "Database error while {$action} customer.",
            'detail'  => $detail,
        ], 500);
    }
}
