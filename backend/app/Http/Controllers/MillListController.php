<?php

namespace App\Http\Controllers;

use App\Models\MillList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MillListController extends Controller
{
    /**
     * KEEP: /api/mills
     * Identity-only list for dropdowns.
     */
    public function index(Request $request)
    {
        $companyId = $request->query('company_id');

        return MillList::query()
            ->select(['mill_id', 'mill_name', 'prefix', 'company_id'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('mill_name')
            ->get();
    }

    /**
     * NEW: /api/mills/effective?company_id=...&as_of=YYYY-MM-DD
     * Returns mills with effective rate fields as of a date.
     */
    public function effective(Request $request)
    {
        $request->validate([
            'company_id' => 'required|integer',
            'as_of'      => 'nullable|date',
        ]);

        $companyId = (int) $request->query('company_id');
        $asOf = $request->query('as_of')
            ? Carbon::parse($request->query('as_of'))->toDateString()
            : Carbon::today()->toDateString();

        $rateSub = function (string $col) use ($companyId, $asOf) {
            return DB::table('mill_rate_history as r')
                ->select($col)
                ->whereColumn('r.mill_id', 'mill_list.mill_id')
                ->where('r.valid_from', '<=', $asOf)
                ->where(function ($q) use ($asOf) {
                    $q->whereNull('r.valid_to')->orWhere('r.valid_to', '>', $asOf);
                })
                ->orderByDesc('r.valid_from')
                ->limit(1);

        };

        return MillList::query()
            ->select([
                'mill_list.mill_id',
                'mill_list.mill_name',
                'mill_list.prefix',
                'mill_list.company_id',
            ])
            ->where('mill_list.company_id', $companyId)
            ->selectSub($rateSub('insurance_rate'), 'insurance_rate')
            ->selectSub($rateSub('storage_rate'),   'storage_rate')
            ->selectSub($rateSub('days_free'),      'days_free')
            ->selectSub($rateSub('market_value'),   'market_value')
            ->selectSub($rateSub('ware_house'),     'ware_house')
            ->selectSub($rateSub('shippable_flag'), 'shippable_flag')
            ->orderBy('mill_list.mill_name')
            ->get();
    }

    /**
     * Optional CRUD for mills (create/update/delete).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mill_name'      => 'required|string|max:255',
            'prefix'         => 'nullable|string|max:25',
            'company_id'     => 'required|integer',
            'workstation_id' => 'nullable|string|max:50',
            'user_id'        => 'nullable|integer',
        ]);

        $mill = MillList::create($validated);
        return response()->json($mill, 201);
    }

    public function update(Request $request, $id)
    {
        $mill = MillList::findOrFail($id);

        $validated = $request->validate([
            'mill_name'      => 'sometimes|required|string|max:255',
            'prefix'         => 'nullable|string|max:25',
            'company_id'     => 'sometimes|required|integer',
            'workstation_id' => 'nullable|string|max:50',
            'user_id'        => 'nullable|integer',
        ]);

        $mill->update($validated);
        return response()->json($mill);
    }

    public function destroy($id)
    {
        $mill = MillList::findOrFail($id);
        $mill->delete();

        return response()->json(['message' => 'Mill deleted']);
    }
}
