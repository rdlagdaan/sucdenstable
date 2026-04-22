<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SummaryOfPurchasesController extends Controller
{
    public function sugarTypes(Request $request)
    {
        $rows = DB::table('sugar_type')
            ->select([
                'id',
                'sugar_type',
                'description',
            ])
            ->orderByDesc('sugar_type')
            ->get();

        return response()->json($rows);
    }

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
            'company_id'     => ['required'],
            'sugar_type_id'  => ['required', 'integer'],
            'crop_year_id'   => ['required', 'integer'],
            'as_of_date'     => ['required', 'date'],
        ]);

        $sugarType = DB::table('sugar_type')
            ->select([
                'id',
                'sugar_type',
                'description',
            ])
            ->where('id', $validated['sugar_type_id'])
            ->first();

        if (!$sugarType) {
            return response()->json([
                'message' => 'Sugar Type not found.',
            ], 404);
        }

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
                'company_id'    => $validated['company_id'],
                'sugar_type_id' => $validated['sugar_type_id'],
                'crop_year_id'  => $validated['crop_year_id'],
                'as_of_date'    => $validated['as_of_date'],
                'sugar_type'    => $sugarType,
                'crop_year'     => $cropYear,
            ],
        ]);
    }
}
