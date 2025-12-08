<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModuleAccessController extends Controller
{
    public function userModules(Request $request): JsonResponse
    {
        $user = $request->user(); // auth:sanctum
        if (!$user) {
            return response()->json([]);
        }

        $userId = (int) $user->id;

        // Prefer explicit ?company_id=, fall back to authenticated user's company_id.
        // NOTE: If neither yields a positive int, return empty to avoid cross-company leakage.
        $requestedCompanyId = (int) $request->query('company_id', 0);
        $fallbackCompanyId  = (int) ($user->company_id ?? 0);
        $companyId = $requestedCompanyId > 0 ? $requestedCompanyId : $fallbackCompanyId;

        if ($companyId <= 0) {
            // No valid company scope => no modules
            return response()->json([]);
        }

        $rows = DB::table('application_users as au')
            ->join('application_sub_modules as asm', 'au.application_sub_module_id', '=', 'asm.id')
            ->join('application_modules as am', 'asm.application_module_id', '=', 'am.id')
            ->join('system_mains as sm', 'am.system_main_id', '=', 'sm.id')
            ->where('au.users_employees_id', $userId)
            ->where('au.company_id', $companyId)   // <-- hard company filter
            ->orderBy('sm.sort_order')
            ->orderBy('am.sort_order')
            ->orderBy('asm.sort_order')
            ->get([
                'sm.id as system_id',
                'sm.system_name',
                'am.id as module_id',
                'am.module_name',
                'asm.id as sub_module_id',
                'asm.sub_module_name',
                'asm.component_path',
            ]);

        $hierarchy = [];

        foreach ($rows as $r) {
            $sid = $r->system_id;
            $mid = $r->module_id;

            if (!isset($hierarchy[$sid])) {
                $hierarchy[$sid] = [
                    'system_id'   => $sid,
                    'system_name' => $r->system_name,
                    'modules'     => [],
                ];
            }

            if (!isset($hierarchy[$sid]['modules'][$mid])) {
                $hierarchy[$sid]['modules'][$mid] = [
                    'module_id'   => $mid,
                    'module_name' => $r->module_name,
                    'sub_modules' => [],
                ];
            }

            $hierarchy[$sid]['modules'][$mid]['sub_modules'][] = [
                'sub_module_id'   => $r->sub_module_id,
                'sub_module_name' => $r->sub_module_name,
                'component_path'  => $r->component_path,
            ];
        }

        $result = array_values(array_map(function ($system) {
            $system['modules'] = array_values($system['modules']);
            return $system;
        }, $hierarchy));

        return response()->json($result);
    }
}
