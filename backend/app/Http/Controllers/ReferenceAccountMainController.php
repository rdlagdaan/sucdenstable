<?php

namespace App\Http\Controllers;

use App\Models\AccountMain;
use App\Models\AccountCode;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

class ReferenceAccountMainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage   = max(1, min((int)$request->input('per_page', 10), 100));
        $search    = trim((string)$request->input('search',''));
        $companyId = $this->resolveCompanyId($request);

        $q = AccountMain::query()->where('company_id',$companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function($x) use ($like){
                $x->where('main_acct','ILIKE',$like)
                  ->orWhere('main_acct_code','ILIKE',$like);
            });
        }

        return response()->json(
            $q->orderBy('main_acct_code')->paginate($perPage)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $data = $request->validate([
            'main_acct'      => ['required','string','max:200'],
            'main_acct_code' => ['required','string','max:25'],
        ]);

        $dup = AccountMain::where('company_id',$companyId)
            ->where(function($q) use ($data){
                $q->where('main_acct',$data['main_acct'])
                  ->orWhere('main_acct_code',$data['main_acct_code']);
            })->exists();
        if ($dup) return response()->json(['message'=>'Main account or code already exists for this company.'], 409);

        $row = AccountMain::create([
            'main_acct'      => $data['main_acct'],
            'main_acct_code' => $data['main_acct_code'],
            'company_id'     => $companyId,
            'workstation_id' => $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname(),
            'user_id'        => Auth::id() ?: null,
        ]);

        return response()->json(['status'=>'success','message'=>'Main account created','data'=>$row], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $row = AccountMain::where('id',$id)->where('company_id',$companyId)->firstOrFail();

        $data = $request->validate([
            'main_acct'      => ['required','string','max:200'],
            'main_acct_code' => ['required','string','max:25'],
        ]);

        $dup = AccountMain::where('company_id',$companyId)
            ->where('id','<>',$id)
            ->where(function($q) use ($data){
                $q->where('main_acct',$data['main_acct'])
                  ->orWhere('main_acct_code',$data['main_acct_code']);
            })->exists();
        if ($dup) return response()->json(['message'=>'Main account or code already exists for this company.'], 409);

        $row->update([
            'main_acct'      => $data['main_acct'],
            'main_acct_code' => $data['main_acct_code'],
            'workstation_id' => $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname(),
            'user_id'        => Auth::id() ?: null,
        ]);

        return response()->json(['status'=>'success','message'=>'Main account updated','data'=>$row]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $row = AccountMain::where('id',$id)->where('company_id',$companyId)->firstOrFail();

        // Optional: block delete if referenced by any account_code
        $referenced = AccountCode::where('company_id',$companyId)
            ->where('main_acct',$row->main_acct)
            ->where('main_acct_code',$row->main_acct_code)
            ->exists();
        if ($referenced) {
            return response()->json(['message'=>'Cannot delete: main account is used by account codes.'], 422);
        }

        $row->delete();
        return response()->json(['status'=>'success','message'=>'Main account deleted']);
    }

    private function resolveCompanyId(Request $request): int
    {
        $resolved = $request->header('X-Company-ID')
            ?? (optional(Auth::user())->company_id)
            ?? env('DEFAULT_COMPANY_ID');

        if (!$resolved && Schema::hasTable('companies')) {
            $only = DB::table('companies')->select('id')->limit(2)->pluck('id');
            if ($only->count() === 1) $resolved = (string)$only->first();
        }
        if (!$resolved) abort(response()->json(['message'=>'Missing company_id (X-Company-ID or DEFAULT_COMPANY_ID).'], 422));
        if (Schema::hasTable('companies')) {
            $exists = DB::table('companies')->where('id', $resolved)->exists();
            if (!$exists) abort(response()->json(['message'=>'Invalid company_id.'], 422));
        }
        return (int)$resolved;
    }
}
