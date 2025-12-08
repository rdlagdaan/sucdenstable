<?php
// app/Http/Controllers/ReferenceBankController.php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

use Symfony\Component\HttpFoundation\StreamedResponse;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Http\Controllers\Concerns\ExportsExcel;


class ReferenceBankController extends Controller
{
    use ExportsExcel;
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $perPage = max(1, min($perPage, 100));
        $search  = trim((string) $request->input('search', ''));

        $q = Bank::query();

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('bank_id', 'ILIKE', $like)
                  ->orWhere('bank_name', 'ILIKE', $like)
                  ->orWhere('bank_address', 'ILIKE', $like)
                  ->orWhere('bank_account_number', 'ILIKE', $like)
                  ->orWhere('workstation_id', 'ILIKE', $like);
            });
        }

        $banks = $q->orderBy('created_at', 'desc')->paginate($perPage);
        return response()->json($banks);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_id'             => 'required|string|max:25|unique:bank,bank_id',
            'bank_name'           => 'required|string|max:150',
            'bank_address'        => 'nullable|string|max:200',
            'bank_account_number' => 'nullable|string|max:25',
            // workstation_id, user_id, company_id are set server-side
        ]);

        // Workstation (auto)
        $data['workstation_id'] = $request->header('X-Workstation-ID')
            ?? $request->ip()
            ?? gethostname();

        // Company (Header → Auth user → DEFAULT_COMPANY_ID)
        $resolvedCompanyId = $request->header('X-Company-ID')
            ?? (optional(Auth::user())->company_id)
            ?? env('DEFAULT_COMPANY_ID');

        // Single-company fallback ONLY if "companies" exists
        if (!$resolvedCompanyId && Schema::hasTable('companies')) {
            $only = DB::table('companies')->select('id')->limit(2)->pluck('id');
            if ($only->count() === 1) {
                $resolvedCompanyId = $only->first();
            }
        }

        if (!$resolvedCompanyId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing company_id. Provide X-Company-ID header or set DEFAULT_COMPANY_ID.',
            ], 422);
        }

        // Validate against companies.id if the table exists
        if (Schema::hasTable('companies')) {
            $exists = DB::table('companies')->where('id', $resolvedCompanyId)->exists();
            if (!$exists) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Invalid company_id. Provide a valid X-Company-ID or set DEFAULT_COMPANY_ID to an existing companies.id.',
                ], 422);
            }
        }

        $data['company_id'] = (int) $resolvedCompanyId;
        $data['user_id']    = Auth::id() ?? (env('DEFAULT_USER_ID') ? (int) env('DEFAULT_USER_ID') : null);

        try {
            $bank = Bank::create($data);
            return response()->json([
                'status'  => 'success',
                'message' => 'Bank created successfully',
                'data'    => $bank,
            ], 201);
        } catch (QueryException $e) {
            return $this->mapPgException($e, 'creating');
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $bank = Bank::findOrFail($id);

        $data = $request->validate([
            'bank_id'             => 'required|string|max:25|unique:bank,bank_id,' . $id,
            'bank_name'           => 'required|string|max:150',
            'bank_address'        => 'nullable|string|max:200',
            'bank_account_number' => 'nullable|string|max:25',
        ]);

        $data['workstation_id'] = $request->header('X-Workstation-ID')
            ?? $request->ip()
            ?? gethostname();

        $resolvedCompanyId = $request->header('X-Company-ID')
            ?? (optional(Auth::user())->company_id)
            ?? env('DEFAULT_COMPANY_ID');

        if (!$resolvedCompanyId && Schema::hasTable('companies')) {
            $only = DB::table('companies')->select('id')->limit(2)->pluck('id');
            if ($only->count() === 1) {
                $resolvedCompanyId = $only->first();
            }
        }

        if (!$resolvedCompanyId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing company_id. Provide X-Company-ID header or set DEFAULT_COMPANY_ID.',
            ], 422);
        }

        if (Schema::hasTable('companies')) {
            $exists = DB::table('companies')->where('id', $resolvedCompanyId)->exists();
            if (!$exists) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Invalid company_id. Provide a valid X-Company-ID or set DEFAULT_COMPANY_ID to an existing companies.id.',
                ], 422);
            }
        }

        $data['company_id'] = (int) $resolvedCompanyId;
        $data['user_id']    = Auth::id() ?? (env('DEFAULT_USER_ID') ? (int) env('DEFAULT_USER_ID') : null);

        try {
            $bank->update($data);
            return response()->json([
                'status'  => 'success',
                'message' => 'Bank updated successfully.',
                'data'    => $bank,
            ]);
        } catch (QueryException $e) {
            return $this->mapPgException($e, 'updating');
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $bank = Bank::findOrFail($id);
        $bank->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Bank deleted successfully.',
        ]);
    }

    private function mapPgException(QueryException $e, string $action)
    {
        $detail   = $e->errorInfo[2] ?? $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? null;

        if (strpos($detail, '23505') !== false || $sqlState === '23505') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Duplicate value. bank_id must be unique.',
                'detail'  => $detail,
            ], 409);
        }
        if (strpos($detail, '23503') !== false || $sqlState === '23503') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Foreign key violation (e.g., company_id or user_id not valid).',
                'detail'  => $detail,
            ], 422);
        }
        if (strpos($detail, '23502') !== false || $sqlState === '23502') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing required value for a NOT NULL column.',
                'detail'  => $detail,
            ], 422);
        }

        return response()->json([
            'status'  => 'error',
            'message' => "Database error while {$action} bank.",
            'detail'  => $detail,
        ], 500);
    }


public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
{
    $search = trim((string)$request->query('search', ''));

    $q = Bank::query();
    if ($search !== '') {
        $like = "%{$search}%";
        $q->where(function ($x) use ($like) {
            $x->where('bank_id', 'ILIKE', $like)
              ->orWhere('bank_name', 'ILIKE', $like)
              ->orWhere('bank_address', 'ILIKE', $like)
              ->orWhere('bank_account_number', 'ILIKE', $like)
              ->orWhere('workstation_id', 'ILIKE', $like);
        });
    }

    $columns = [
        ['key' => 'bank_id',             'label' => 'Bank ID',   'type' => 'string'],
        ['key' => 'bank_name',           'label' => 'Name',      'type' => 'string'],
        ['key' => 'bank_address',        'label' => 'Address',   'type' => 'string'],
        ['key' => 'bank_account_number', 'label' => 'Account No.','type' => 'string'],
    ];

    return $this->exportExcel($request, $q, $columns, [
        'filename' => 'banks',
        'sheet'    => 'Banks',
        // 'autosize' => true, // default
    ]);
}


}
