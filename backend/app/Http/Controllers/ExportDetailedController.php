<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExportDetailedController extends Controller
{
    public function vessels(Request $request)
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json([], 200);
        }

        $rows = DB::table('mill_list')
            ->select([
                'id',
                'mill_id',
                'mill_name',
            ])
            ->where('company_id', $companyId) // VERY IMPORTANT
            ->orderBy('mill_id')
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
            'company_id'   => ['required'],
            'vessel_id'    => ['required'],
            'crop_year_id' => ['required', 'integer'],
            'as_of_date'   => ['required', 'date'],
        ]);

        $vessel = DB::table('mill_list')
            ->select([
                'id',
                'mill_id',
                'mill_name',
                'company_id',
            ])
            ->where('id', $validated['vessel_id'])
            ->where('company_id', $validated['company_id']) // VERY IMPORTANT
            ->first();

        if (!$vessel) {
            return response()->json([
                'message' => 'Vessel not found.',
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
                'company_id'   => $validated['company_id'],
                'vessel_id'    => $validated['vessel_id'],
                'crop_year_id' => $validated['crop_year_id'],
                'as_of_date'   => $validated['as_of_date'],
                'vessel'       => $vessel,
                'crop_year'    => $cropYear,
            ],
        ]);
    }
}
