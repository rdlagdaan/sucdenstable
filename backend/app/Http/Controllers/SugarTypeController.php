<?php

namespace App\Http\Controllers;

use App\Models\SugarType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

class SugarTypeController extends Controller
{
    /** Simple list for dropdowns. GET /api/sugar-types */
    public function index(Request $request): JsonResponse
    {
        $rows = SugarType::query()
            ->select('id','sugar_type','description')
            ->orderBy('sugar_type','asc')
            ->get();

        return response()->json($rows);
    }

    /** Admin list (paginated + search). GET /api/sugar-types/admin */
    public function adminIndex(Request $request): JsonResponse
    {
        $perPage = max(1, min((int)$request->input('per_page', 5), 100));
        $search  = trim((string)$request->input('search',''));

        $q = SugarType::query()->select('id','sugar_type','description');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('sugar_type','ILIKE',"%{$search}%")
                  ->orWhere('description','ILIKE',"%{$search}%");
            });
        }

        return response()->json(
            $q->orderBy('sugar_type','asc')->paginate($perPage)
        );
    }

    /** Create. POST /api/sugar-types/admin */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'sugar_type'  => 'required|string|max:2|unique:sugar_type,sugar_type',
            'description' => 'required|string|max:15',
        ]);

        try {
            $row = SugarType::create([
                'sugar_type'     => $request->sugar_type,
                'description'    => $request->description,
                'workstation_id' => $request->header('X-Workstation-ID'),
                'user_id'        => optional($request->user())->id,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Sugar type created successfully.',
                'data'    => $row,
            ], 201);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['status'=>'error','message'=>'Sugar type already exists.'], 409);
        } catch (QueryException $e) {
            return response()->json(['status'=>'error','message'=>'Database error.','error'=>$e->getMessage()], 500);
        }
    }

    /** Update. PUT /api/sugar-types/admin/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $row = SugarType::findOrFail($id);

        $request->validate([
            'sugar_type'  => 'required|string|max:2|unique:sugar_type,sugar_type,'.$row->id,
            'description' => 'required|string|max:15',
        ]);

        $row->update([
            'sugar_type'  => $request->sugar_type,
            'description' => $request->description,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Sugar type updated successfully.',
            'data'    => $row,
        ]);
    }

    /** Delete. DELETE /api/sugar-types/admin/{id} */
    public function destroy(int $id): JsonResponse
    {
        $row = SugarType::findOrFail($id);
        $row->delete();

        return response()->json(['status'=>'success','message'=>'Sugar type deleted successfully.']);
    }
}
