<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SugarType;
use Illuminate\Support\Facades\DB;

class SugarTypeController extends Controller
{
    //public function index()
    //{
    //    return response()->json(SugarType::all());
    //}


    public function index()
    {
        $companyId = auth()->user()->company_id ?? 1; // Adjust if needed
        $sugarTypes = DB::table('sugar_type')
            ->select('sugar_type', 'description')
            ->get();

        return response()->json($sugarTypes);
    }



}
