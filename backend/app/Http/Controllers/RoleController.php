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
    public function list(): JsonResponse
    {
        return response()->json(
            Role::select('id', 'role')->get()
        );
    }


    public function index(Request $request)
    {

        $perPage = $request->input('per_page', 5);
        $search = $request->input('search', '');

        $query = Role::query();

        if (!empty($search)) {
            $query->where('role', 'ILIKE', "%{$search}%");
        }

        $roles = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($roles);



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



    // ✅ PUT: /api/roles/{id}
    public function update(Request $request, $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        $request->validate([
            'role' => 'required|string|max:255|unique:roles,role,' . $id,
        ]);

        $role->update([
            'role' => $request->role,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Role updated successfully.',
            'data' => $role,
        ]);
    }

    // ✅ DELETE: /api/roles/{id}
    public function destroy($id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $role->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Role deleted successfully.',
        ]);
    }




}
