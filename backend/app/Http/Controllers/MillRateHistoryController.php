<?php

namespace App\Http\Controllers;

use App\Models\MillRateHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MillRateHistoryController extends Controller
{
    /**
     * GET /api/mill-rates?mill_id=...&company_id=...&as_of=YYYY-MM-DD (optional)
     * - If as_of is given, return ONLY the effective row.
     * - Else return all rows (desc by valid_from).
     */
    public function index(Request $request)
    {
        $request->validate([
            'mill_id'    => 'required|integer',
            'company_id' => 'required|integer',
            'as_of'      => 'nullable|date',
        ]);

        $millId    = (int) $request->query('mill_id');
        $companyId = (int) $request->query('company_id');
        $asOf      = $request->query('as_of');

        $query = MillRateHistory::where('mill_id', $millId)
            ->where('company_id', $companyId);

        if ($asOf) {
            $date = Carbon::parse($asOf)->toDateString();
            $row = $query->where('valid_from', '<=', $date)
                         ->where(function ($q) use ($date) {
                             $q->whereNull('valid_to')->orWhere('valid_to', '>', $date);
                         })
                         ->orderByDesc('valid_from')
                         ->first();

            return $row ? response()->json($row) : response()->json(null);
        }

        return $query->orderByDesc('valid_from')->get();
    }

    /**
     * POST /api/mill-rates
     * Creates a new period; if an open period exists, it auto-closes it at new.valid_from.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mill_id'        => 'required|integer',
            'company_id'     => 'required|integer',
            'valid_from'     => 'required|date',
            'valid_to'       => 'nullable|date|after:valid_from',
            'insurance_rate' => 'nullable|numeric',
            'storage_rate'   => 'nullable|numeric',
            'days_free'      => 'nullable|integer',
            'market_value'   => 'nullable|numeric',
            'ware_house'     => 'nullable|string|max:100',
            'shippable_flag' => 'boolean',
            'workstation_id' => 'nullable|string|max:50',
            'user_id'        => 'nullable|integer',
        ]);

        return DB::transaction(function () use ($validated) {
            $millId    = (int) $validated['mill_id'];
            $companyId = (int) $validated['company_id'];
            $from      = Carbon::parse($validated['valid_from'])->toDateString();
            $to        = $validated['valid_to'] ?? null;

            // 1) Close open period if it exists and starts before the new one
            $open = MillRateHistory::where('mill_id', $millId)
                ->where('company_id', $companyId)
                ->whereNull('valid_to')
                ->orderByDesc('valid_from')
                ->first();

            if ($open) {
                if ($from <= $open->valid_from->toDateString()) {
                    return response()->json([
                        'message' => 'New period must start AFTER the current open period\'s start date, or close the open period first.'
                    ], 422);
                }
                // Close the open period at the start of the new one (end-exclusive)
                $open->update(['valid_to' => $from]);
            }

            // 2) (Optional hard-guard) If valid_to provided, ensure no overlap with existing closed rows
            if ($to) {
                $overlap = MillRateHistory::where('mill_id', $millId)
                    ->where('company_id', $companyId)
                    ->where(function ($q) use ($from, $to) {
                        $q->where('valid_from', '<', $to)
                          ->where(function ($w) use ($from) {
                              $w->whereNull('valid_to')->orWhere('valid_to', '>', $from);
                          });
                    })
                    ->exists();

                if ($overlap) {
                    return response()->json([
                        'message' => 'The provided period overlaps an existing period.'
                    ], 422);
                }
            }

            // 3) Create the new period
            $row = MillRateHistory::create($validated);
            return response()->json($row, 201);
        });
    }

    /**
     * PUT /api/mill-rates/{id}
     * - Update rate fields
     * - Or close a period by setting valid_to (must be > valid_from and non-overlapping).
     */
    public function update(Request $request, $id)
    {
        $row = MillRateHistory::findOrFail($id);

        $validated = $request->validate([
            'valid_from'     => 'sometimes|date',
            'valid_to'       => 'nullable|date|after:valid_from',
            'insurance_rate' => 'nullable|numeric',
            'storage_rate'   => 'nullable|numeric',
            'days_free'      => 'nullable|integer',
            'market_value'   => 'nullable|numeric',
            'ware_house'     => 'nullable|string|max:100',
            'shippable_flag' => 'boolean',
            'workstation_id' => 'nullable|string|max:50',
            'user_id'        => 'nullable|integer',
        ]);

        // If changing valid_to or valid_from, do a minimal overlap check
        if (array_key_exists('valid_from', $validated) || array_key_exists('valid_to', $validated)) {
            $newFrom = isset($validated['valid_from'])
                ? Carbon::parse($validated['valid_from'])->toDateString()
                : $row->valid_from->toDateString();

            $newTo = array_key_exists('valid_to', $validated)
                ? ($validated['valid_to'] ? Carbon::parse($validated['valid_to'])->toDateString() : null)
                : ($row->valid_to ? $row->valid_to->toDateString() : null);

            $overlap = MillRateHistory::where('mill_id', $row->mill_id)
                ->where('company_id', $row->company_id)
                ->where('id', '!=', $row->id)
                ->where(function ($q) use ($newFrom, $newTo) {
                    $q->where('valid_from', '<', $newTo ?? 'infinity')
                      ->where(function ($w) use ($newFrom) {
                          $w->whereNull('valid_to')->orWhere('valid_to', '>', $newFrom);
                      });
                })
                ->exists();

            if ($overlap) {
                return response()->json(['message' => 'Updated dates would overlap another period.'], 422);
            }
        }

        $row->update($validated);
        return response()->json($row);
    }

    /**
     * DELETE /api/mill-rates/{id}
     */
    public function destroy($id)
    {
        $row = MillRateHistory::findOrFail($id);
        $row->delete();

        return response()->json(['message' => 'Rate period deleted']);
    }
}
