<?php

namespace App\Http\Controllers\AssignUserModules;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class RolesController extends Controller
{
    public function index()
    {
        // Minimal payload for dropdown
        $roles = DB::table('roles')
            ->select('id', 'role')
            ->orderBy('role')
            ->get();

        return response()->json(['items' => $roles]);
    }
}
