<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;

class CompanySettingController extends Controller
{
    // GET /api/company-settings?per_page=&page=&search=
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 5);
        $perPage = max(1, min($perPage, 100));
        $search  = trim((string) $request->input('search', ''));

        $q = Company::query()->select('id', 'company_name', 'logo');

        if ($search !== '') {
            $q->where('company_name', 'ILIKE', "%{$search}%");
        }

        $companies = $q->orderBy('id', 'asc')->paginate($perPage);

        return response()->json($companies);
    }

    // POST /api/company-settings
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required','string','max:100','unique:companies,company_name'],
            'logo'         => ['nullable','string','max:100'],
        ]);

        // Normalize/trim
        $data['company_name'] = trim($data['company_name']);
        if (isset($data['logo'])) {
            $data['logo'] = trim((string)$data['logo']);
        }

        try {
            $company = Company::create($data);

            return response()->json([
                'status'  => 'success',
                'message' => 'Company created successfully.',
                'data'    => $company,
            ], 201);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The company name already exists.',
            ], 409);
        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Database error.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // PUT /api/company-settings/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $data = $request->validate([
            'company_name' => [
                'required','string','max:100',
                Rule::unique('companies','company_name')->ignore($id),
            ],
            'logo' => ['nullable','string','max:100'],
        ]);

        $data['company_name'] = trim($data['company_name']);
        if (isset($data['logo'])) {
            $data['logo'] = trim((string)$data['logo']);
        }

        try {
            $company->fill($data)->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'Company updated successfully.',
                'data'    => $company,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The company name already exists.',
            ], 409);
        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Database error.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // DELETE /api/company-settings/{id}
    public function destroy(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        try {
            $company->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Company deleted successfully.',
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Database error.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
