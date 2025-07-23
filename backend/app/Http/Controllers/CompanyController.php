<?php

// File: app/Http/Controllers/CompanyController.php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Company;

class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Company::select('id', 'company_name')->get()
        );
    }


    public function show($id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        return response()->json([
            'id' => $company->id,
            'name' => $company->company_name, // change to match your column
            'logo' => $company->logo, // change to match your column
        ]);
    }

}
