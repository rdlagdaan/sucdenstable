<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TotalPurchasesBySupplierWithDrilldownController extends Controller
{
    public function cropYears(Request $request)
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json([], 200);
        }

        $rows = DB::table('crop_year')
            ->select([
                'id',
                'crop_year',
                'begin_year',
                'end_year',
            ])
            ->where('company_id', $companyId)
            ->orderByDesc('crop_year')
            ->get();

        return response()->json($rows);
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'company_id'   => ['required'],
            'crop_year_id' => ['required', 'integer'],
            'as_of_date'   => ['required', 'date'],
        ]);

        $cropYear = DB::table('crop_year')
            ->select([
                'id',
                'crop_year',
                'begin_year',
                'end_year',
                'company_id',
            ])
            ->where('id', $validated['crop_year_id'])
            ->where('company_id', $validated['company_id'])
            ->first();

        if (!$cropYear) {
            return response()->json([
                'message' => 'Crop Year not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Prompt parameters accepted.',
            'params' => [
                'company_id'   => $validated['company_id'],
                'crop_year_id' => $validated['crop_year_id'],
                'as_of_date'   => $validated['as_of_date'],
                'crop_year'    => $cropYear,
            ],
        ]);
    }
}
