<?php

namespace App\Http\Controllers\AssignUserModules;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsersEmployeesController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->query('q', ''));
        $limit = min((int)$request->query('limit', 20), 100);
        $page = max((int)$request->query('page', 1), 1);

        $base = DB::table('users_employees')
            ->select('id','username','email_address','first_name','last_name','middle_name','designation','active')
            ->orderBy('last_name')->orderBy('first_name');

        if ($q !== '') {
            $qLike = '%'.$q.'%';
            $base->where(function($w) use ($qLike) {
                $w->where('username','ilike',$qLike)
                  ->orWhere('email_address','ilike',$qLike)
                  ->orWhereRaw("(first_name || ' ' || last_name) ilike ?", [$qLike])
                  ->orWhere('designation','ilike',$qLike);
            });
        }

        $items = $base->forPage($page, $limit)->get();
        $total = (clone $base)->count();

        return response()->json([
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

public function store(Request $request)
{
    $data = $request->validate([
        'username'      => ['required','string','max:25', Rule::unique('users_employees','username')],
        'password'      => ['required','string','min:8','max:250'],
        'email_address' => ['required','email','max:100', Rule::unique('users_employees','email_address')],
        'first_name'    => ['required','string','max:50'],
        'last_name'     => ['required','string','max:50'],
        'middle_name'   => ['nullable','string','max:50'],
        'designation'   => ['nullable','string','max:50'],
        'role_id'       => ['nullable','integer'],
        'active'        => ['boolean'],
    ]);

    $now = now();
    $isActive = array_key_exists('active', $data) ? (bool)$data['active'] : true;
    $status = $isActive ? 'active' : 'inactive';

    $id = DB::table('users_employees')->insertGetId([
        'username'                   => $data['username'],
        'password'                   => Hash::make($data['password']),
        'salt'                       => null, // legacy
        'email_address'              => $data['email_address'],
        'activation_code'            => null,
        'forgotten_password_code'    => null,
        'forgotten_password_time'    => null,
        'remember_code'              => null,
        'date_created'               => $now->toDateString(),
        'created_by'                 => 'system',
        'last_login'                 => null,
        'role_id'                    => $data['role_id'] ?? null,
        'status'                     => $status,
        'active'                     => $isActive,
        'first_name'                 => $data['first_name'],
        'last_name'                  => $data['last_name'],
        'middle_name'                => $data['middle_name'] ?? null,
        'time_stamp'                 => $now,
        'designation'                => $data['designation'] ?? null,
        'created_at'                 => $now,
        'updated_at'                 => $now,
    ]);

    $user = DB::table('users_employees')
        ->select('id','username','email_address','first_name','last_name','middle_name','designation','active')
        ->where('id',$id)->first();

    return response()->json(['id' => $id, 'user' => $user], 201);
}


    public function toggleActive($userId, Request $request)
    {
        $validated = $request->validate([
            'active' => ['required','boolean'],
        ]);

        DB::table('users_employees')
            ->where('id', $userId)
            ->update([
                'active' => $validated['active'],
                'updated_at' => now(),
                'status' => $validated['active'] ? 'active' : 'inactive',
            ]);

        return response()->json(['ok' => true, 'id' => (int)$userId, 'active' => (bool)$validated['active'] ]);
    }
}
