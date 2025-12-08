<?php

namespace App\Http\Controllers\AssignUserModules;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ModuleTreeController extends Controller
{
    public function tree()
    {
        // Cache the static tree for 10 minutes to avoid repeated joins
        $tree = Cache::remember('aum:tree:v1', 600, function () {
            $systems = DB::table('system_mains')
                ->select('id','system_id','system_name','sort_order')
                ->orderBy('sort_order')->orderBy('system_name')
                ->get();

            $modules = DB::table('application_modules')
                ->select('id','system_main_id','controller','module_name','sort_order')
                ->orderBy('sort_order')->orderBy('module_name')
                ->get()
                ->groupBy('system_main_id');

            $subs = DB::table('application_sub_modules')
                ->select('id','application_module_id','sub_controller','sub_module_name','component_path','sort_order')
                ->orderBy('sort_order')->orderBy('sub_module_name')
                ->get()
                ->groupBy('application_module_id');

            $systemsArr = [];
            foreach ($systems as $sys) {
                $modsArr = [];
                foreach ($modules->get($sys->id, collect()) as $mod) {
                    $subArr = [];
                    foreach ($subs->get($mod->id, collect()) as $sub) {
                        $subArr[] = [
                            'id' => (int)$sub->id,
                            'sub_module_name' => $sub->sub_module_name,
                            'sub_controller'  => $sub->sub_controller,
                            'component_path'  => $sub->component_path,
                            'sort_order'      => (int)$sub->sort_order,
                        ];
                    }
                    $modsArr[] = [
                        'id'          => (int)$mod->id,
                        'module_name' => $mod->module_name,
                        'controller'  => $mod->controller,
                        'sort_order'  => (int)$mod->sort_order,
                        'sub_modules' => $subArr,
                    ];
                }
                $systemsArr[] = [
                    'id'          => (int)$sys->id,
                    'system_id'   => $sys->system_id,
                    'system_name' => $sys->system_name,
                    'sort_order'  => (int)$sys->sort_order,
                    'modules'     => $modsArr,
                ];
            }

            return $systemsArr;
        });

        return response()->json(['systems' => $tree]);
    }
}
