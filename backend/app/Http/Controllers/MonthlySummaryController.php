<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonthlySummaryController extends Controller
{
    public function years(Request $request)
    {
        $rows = DB::table('year_list')
            ->select([
                'id',
                'year_value',
            ])
            ->orderByDesc('year_value')
            ->get();

        return response()->json($rows);
    }

    public function months(Request $request)
    {
        $rows = DB::table('month_list')
            ->select([
                'id',
                'month_num',
                'month_desc',
            ])
            ->orderByRaw("CAST(month_num AS INTEGER)")
            ->get();

        return response()->json($rows);
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'year_id'  => ['required', 'integer'],
            'month_id' => ['required', 'integer'],
        ]);

        $year = DB::table('year_list')
            ->select([
                'id',
                'year_value',
            ])
            ->where('id', $validated['year_id'])
            ->first();

        if (!$year) {
            return response()->json([
                'message' => 'Year not found.',
            ], 404);
        }

        $month = DB::table('month_list')
            ->select([
                'id',
                'month_num',
                'month_desc',
            ])
            ->where('id', $validated['month_id'])
            ->first();

        if (!$month) {
            return response()->json([
                'message' => 'Month not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Prompt parameters accepted.',
            'params' => [
                'year_id'   => $validated['year_id'],
                'month_id'  => $validated['month_id'],
                'year'      => $year,
                'month'     => $month,
            ],
        ]);
    }
}
