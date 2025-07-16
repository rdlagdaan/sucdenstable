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
}
