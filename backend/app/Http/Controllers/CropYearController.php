<?php

namespace App\Http\Controllers;

use App\Models\CropYear;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

class CropYearController extends Controller
{
    /** Simple list for dropdowns (company-scoped). GET /api/crop-years */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->header('X-Company-ID', $request->input('company_id'));
        $q = CropYear::query()
            ->select('id','crop_year','begin_year','end_year','company_id')
            ->when($companyId, fn($qq) => $qq->where('company_id', (int)$companyId))
            ->orderBy('crop_year','desc');

        return response()->json($q->get());
    }

    /** Admin list (paginated + search + company scope). GET /api/crop-years/admin */
    public function adminIndex(Request $request): JsonResponse
    {
        $perPage  = max(1, min((int)$request->input('per_page', 5), 100));
        $search   = trim((string)$request->input('search',''));
        $companyId = (int)($request->header('X-Company-ID', $request->input('company_id')));

        $q = CropYear::query()
            ->select('id','crop_year','begin_year','end_year','company_id')
            ->when($companyId, fn($qq) => $qq->where('company_id', $companyId));

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('crop_year', 'ILIKE', "%{$search}%")
                  ->orWhere('begin_year', 'ILIKE', "%{$search}%")
                  ->orWhere('end_year', 'ILIKE', "%{$search}%");
            });
        }

        $rows = $q->orderBy('id','asc')->paginate($perPage);
        return response()->json($rows);
    }

    /** Create (company-scoped). POST /api/crop-years/admin */
    public function store(Request $request): JsonResponse
    {
        $companyId = (int)($request->header('X-Company-ID', $request->input('company_id')));
        if (!$companyId) {
            return response()->json(['status'=>'error','message'=>'company_id is required'], 422);
        }

        $request->validate([
            'crop_year'  => 'required|string|max:5|unique:crop_year,crop_year,NULL,id,company_id,'.$companyId,
            'begin_year' => 'required|string|max:5',
            'end_year'   => 'required|string|max:5',
        ]);

        try {
            $row = CropYear::create([
                'crop_year'     => $request->crop_year,
                'begin_year'    => $request->begin_year,
                'end_year'      => $request->end_year,
                'company_id'    => $companyId,
                'active_flag'   => (int)$request->input('active_flag', 1),
                'workstation_id'=> $request->header('X-Workstation-ID'),
                'user_number'   => optional($request->user())->id, // adjust if different
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Crop year created successfully.',
                'data'    => $row,
            ], 201);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['status'=>'error','message'=>'This crop year already exists for this company.'], 409);
        } catch (QueryException $e) {
            return response()->json(['status'=>'error','message'=>'Database error.','error'=>$e->getMessage()], 500);
        }
    }

    /** Update (company-scoped). PUT /api/crop-years/admin/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = (int)($request->header('X-Company-ID', $request->input('company_id')));
        $row = CropYear::query()
            ->when($companyId, fn($qq)=>$qq->where('company_id',$companyId))
            ->findOrFail($id);

        $request->validate([
            'crop_year'  => 'required|string|max:5|unique:crop_year,crop_year,'.$row->id.',id,company_id,'.$row->company_id,
            'begin_year' => 'required|string|max:5',
            'end_year'   => 'required|string|max:5',
        ]);

        $row->update([
            'crop_year'   => $request->crop_year,
            'begin_year'  => $request->begin_year,
            'end_year'    => $request->end_year,
            'active_flag' => (int)$request->input('active_flag', $row->active_flag ?? 1),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Crop year updated successfully.',
            'data'    => $row,
        ]);
    }

    /** Delete (company-scoped). DELETE /api/crop-years/admin/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int)($request->header('X-Company-ID', $request->input('company_id')));
        $row = CropYear::query()
            ->when($companyId, fn($qq)=>$qq->where('company_id',$companyId))
            ->findOrFail($id);

        $row->delete();

        return response()->json(['status'=>'success','message'=>'Crop year deleted successfully.']);
    }
}
