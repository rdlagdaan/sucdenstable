<?php

namespace App\Http\Controllers\AssignUserModules;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class UserAssignmentController extends Controller
{
    public function getAssignments($userId, Request $request)
    {
        $companyId = (int) $request->query('company_id', 0);
        if ($companyId <= 0) {
            return response()->json(['sub_module_ids' => []]);
        }

        $ids = DB::table('application_users')
            ->where('users_employees_id', $userId)
            ->where('company_id', $companyId) // company scoped
            ->pluck('application_sub_module_id')
            ->map(fn($v) => (int)$v)
            ->values();

        return response()->json(['sub_module_ids' => $ids]);
    }

    public function applyDiff($userId, Request $request)
    {
        $validated = $request->validate([
            'company_id' => 'required|integer|min:1',
            'add'        => 'array',
            'add.*'      => 'integer',
            'remove'     => 'array',
            'remove.*'   => 'integer',
        ]);

        $companyId = (int) $validated['company_id'];
        $adds = collect($validated['add'] ?? [])->unique()->values();
        $remv = collect($validated['remove'] ?? [])->unique()->values();

        DB::transaction(function () use ($userId, $companyId, $adds, $remv) {
            if ($remv->isNotEmpty()) {
                DB::table('application_users')
                    ->where('users_employees_id', $userId)
                    ->where('company_id', $companyId)
                    ->whereIn('application_sub_module_id', $remv)
                    ->delete();
            }

            if ($adds->isNotEmpty()) {
                $now = now();
                $rows = $adds->map(fn($sid) => [
                    'users_employees_id'        => $userId,
                    'application_sub_module_id' => $sid,
                    'company_id'                => $companyId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                // Upsert (unique on users_employees_id + application_sub_module_id + company_id)
                DB::table('application_users')->upsert(
                    $rows,
                    ['users_employees_id','application_sub_module_id','company_id'],
                    ['updated_at']
                );
            }
        });

        // Return refreshed list (company scoped)
        $ids = DB::table('application_users')
            ->where('users_employees_id', $userId)
            ->where('company_id', $companyId)
            ->pluck('application_sub_module_id')
            ->map(fn($v) => (int)$v)
            ->values();

        return response()->json([
            'ok' => true,
            'sub_module_ids' => $ids
        ]);
    }

    public function cloneFrom($userId, Request $request)
    {
        $validated = $request->validate([
            'from_user_id' => 'required|integer',
            'company_id'   => 'required|integer|min:1',
        ]);

        $from = (int)$validated['from_user_id'];
        $companyId = (int)$validated['company_id'];

        $source = DB::table('application_users')
            ->where('users_employees_id', $from)
            ->where('company_id', $companyId)
            ->pluck('application_sub_module_id')
            ->unique()
            ->values();

        DB::transaction(function () use ($userId, $companyId, $source) {
            DB::table('application_users')
                ->where('users_employees_id', $userId)
                ->where('company_id', $companyId)
                ->delete();

            $now = now();
            $rows = $source->map(fn($sid) => [
                'users_employees_id'        => $userId,
                'application_sub_module_id' => $sid,
                'company_id'                => $companyId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if (!empty($rows)) {
                DB::table('application_users')->insert($rows);
            }
        });

        return response()->json(['ok' => true, 'cloned_from' => $from]);
    }
}
