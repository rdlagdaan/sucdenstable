<?php

// File: app/Http/Controllers/CompanyController.php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Role;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;


class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Role::select('id', 'role')->get()
        );
    }



    public function store(Request $request)
    {
   
        try {
            $role = new Role();
            $role->role = $request->input('role');
            $role->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Role created successfully',
                'data' => $role
            ], 201);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'The role already exists!'
            ], 409);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Database error.',
                'error' => $e->getMessage()
            ], 500);
        }


    }



}
