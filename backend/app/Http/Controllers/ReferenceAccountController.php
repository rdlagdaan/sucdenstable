<?php

namespace App\Http\Controllers;

use App\Models\AccountCode;
use App\Models\AccountMain;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;

class ReferenceAccountController extends Controller
{
    /** List (paginated + search + filters) */
    public function index(Request $request): JsonResponse
    {
        $perPage   = max(1, min((int)$request->input('per_page', 10), 100));
        $search    = trim((string)$request->input('search',''));
        $companyId = $this->resolveCompanyId($request);

        $q = AccountCode::query()->where('company_id', $companyId);

        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($x) use ($like) {
                $x->where('acct_code','ILIKE',$like)
                  ->orWhere('acct_desc','ILIKE',$like)
                  ->orWhere('main_acct','ILIKE',$like)
                  ->orWhere('main_acct_code','ILIKE',$like)
                  ->orWhere('acct_type','ILIKE',$like);
            });
        }

        // Optional filter examples (fs, group, type...) if you wire them from the UI
        foreach (['fs','acct_group','acct_group_sub1','acct_group_sub2','normal_bal','acct_type'] as $f) {
            $v = trim((string)$request->input($f, ''));
            if ($v !== '') $q->where($f, $v);
        }

        return response()->json(
            $q->orderBy('acct_code')->paginate($perPage)
        );
    }

    /** Distinct meta for combos */
    public function meta(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $base = AccountCode::query()->where('company_id', $companyId);

        $pluckDistinct = function(string $col) use ($base) {
            return (clone $base)->select($col)->whereNotNull($col)->distinct()->orderBy($col)->pluck($col)->values();
        };

        return response()->json([
            'fs'            => $pluckDistinct('fs'),
            'acct_group'    => $pluckDistinct('acct_group'),
            'acct_group_sub1'=> $pluckDistinct('acct_group_sub1'),
            'acct_group_sub2'=> $pluckDistinct('acct_group_sub2'),
            'normal_bal'    => $pluckDistinct('normal_bal'),
            'acct_type'     => $pluckDistinct('acct_type'),
        ]);
    }

    /** Suggest next acct_code for a given main_acct_code */
    public function nextCode(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $mac = trim((string)$request->query('main_acct_code',''));
        if ($mac === '' || strlen($mac) < 2) {
            return response()->json(['next_code' => null]);
        }
        $prefix2 = substr($mac, 0, 2);

        $max = AccountCode::query()
            ->where('company_id', $companyId)
            ->where('acct_code','LIKE',$prefix2.'__')
            ->select(DB::raw("MAX(CAST(SUBSTRING(acct_code,3,2) AS INTEGER)) AS max_suffix"))
            ->value('max_suffix');

        $nextSuffix = (int)$max + 1;
        if ($nextSuffix < 1) $nextSuffix = 1;
        $next = $prefix2 . str_pad((string)$nextSuffix, 2, '0', STR_PAD_LEFT);

        return response()->json(['next_code' => $next]);
    }

    /** Create */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $data = $request->validate([
            'main_acct'      => ['required','string','max:200'],
            'main_acct_code' => ['required','string','max:25'],
            'acct_desc'      => ['required','string','max:100'],
            'acct_code'      => ['nullable','string','regex:/^\d{4}$/'], // server may override
            'fs'             => ['nullable','string','max:100'],
            'acct_group'     => ['nullable','string','max:100'],
            'acct_group_sub1'=> ['nullable','string','max:100'],
            'acct_group_sub2'=> ['nullable','string','max:100'],
            'normal_bal'     => ['nullable','string','max:50'],
            'acct_type'      => ['nullable','string','max:20'],
            'cash_disbursement_flag' => ['nullable','in:1,'],
            'bank_id'        => ['nullable','string','max:15'], // or exists:banks,id if linked
            'vessel_flag'    => ['nullable','in:1,'],
            'booking_no'     => ['nullable','string','max:25'],
            'ap_ar'          => ['nullable','string','max:2'],
            'active_flag'    => ['nullable','integer','in:0,1'],
            'exclude'        => ['nullable','integer','in:0,1'],
        ]);

        // Verify main account exists or accept free text? We’ll accept free text,
        // but if you want strict linkage, uncomment the check:
        // $exists = AccountMain::where('company_id',$companyId)
        //   ->where('main_acct',$data['main_acct'])
        //   ->where('main_acct_code',$data['main_acct_code'])->exists();
        // if (!$exists) return response()->json(['message'=>'Invalid main account'], 422);

        $workstation = $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname();
        $userId = Auth::id() ?: null;

        try {
            return DB::transaction(function () use ($data, $companyId, $workstation, $userId) {

                // acct_number: auto-increment per company (max+1)
                $maxAcctNo = AccountCode::where('company_id',$companyId)->max('acct_number');
                $nextAcctNo = (int)$maxAcctNo + 1;

                // acct_code: authoritative server-side generation (2-digit prefix + 2-digit suffix)
                $prefix2 = substr($data['main_acct_code'], 0, 2);
                // get current max suffix for prefix
                $maxSuffix = AccountCode::query()
                    ->where('company_id', $companyId)
                    ->where('acct_code','LIKE',$prefix2.'__')
                    ->select(DB::raw("MAX(CAST(SUBSTRING(acct_code,3,2) AS INTEGER)) AS max_suffix"))
                    ->value('max_suffix');

                $nextSuffix = (int)$maxSuffix + 1;
                if ($nextSuffix < 1) $nextSuffix = 1;
                $serverCode = $prefix2 . str_pad((string)$nextSuffix, 2, '0', STR_PAD_LEFT);

                $payload = [
                    'acct_number'   => $nextAcctNo,
                    'main_acct'     => $data['main_acct'],
                    'main_acct_code'=> $data['main_acct_code'],
                    'acct_code'     => $serverCode, // server wins
                    'acct_desc'     => $data['acct_desc'],
                    'fs'            => $data['fs'] ?? null,
                    'acct_group'    => $data['acct_group'] ?? null,
                    'acct_group_sub1'=> $data['acct_group_sub1'] ?? null,
                    'acct_group_sub2'=> $data['acct_group_sub2'] ?? null,
                    'normal_bal'    => $data['normal_bal'] ?? null,
                    'acct_type'     => $data['acct_type'] ?? null,
                    'cash_disbursement_flag' => $data['cash_disbursement_flag'] ?? '',
                    'bank_id'       => $data['bank_id'] ?? null,
                    'vessel_flag'   => $data['vessel_flag'] ?? '',
                    'booking_no'    => $data['booking_no'] ?? null,
                    'ap_ar'         => $data['ap_ar'] ?? null,
                    'active_flag'   => isset($data['active_flag']) ? (int)$data['active_flag'] : 1,
                    'exclude'       => isset($data['exclude']) ? (int)$data['exclude'] : 0,
                    'company_id'    => $companyId,
                    'workstation_id'=> $workstation,
                    'user_id'       => $userId,
                ];

                $row = AccountCode::create($payload);
                return response()->json(['status'=>'success','message'=>'Account created','data'=>$row], 201);
            });
        } catch (QueryException $e) {
            return $this->pgError($e, 'creating');
        }
    }

    /** Update */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $row = AccountCode::where('id',$id)->where('company_id',$companyId)->firstOrFail();

        $data = $request->validate([
            'main_acct'      => ['required','string','max:200'],
            'main_acct_code' => ['required','string','max:25'],
            'acct_desc'      => ['required','string','max:100'],
            'acct_code'      => ['nullable','string','regex:/^\d{4}$/'], // server may override if prefix mismatch
            'fs'             => ['nullable','string','max:100'],
            'acct_group'     => ['nullable','string','max:100'],
            'acct_group_sub1'=> ['nullable','string','max:100'],
            'acct_group_sub2'=> ['nullable','string','max:100'],
            'normal_bal'     => ['nullable','string','max:50'],
            'acct_type'      => ['nullable','string','max:20'],
            'cash_disbursement_flag' => ['nullable','in:1,'],
            'bank_id'        => ['nullable','string','max:15'],
            'vessel_flag'    => ['nullable','in:1,'],
            'booking_no'     => ['nullable','string','max:25'],
            'ap_ar'          => ['nullable','string','max:2'],
            'active_flag'    => ['nullable','integer','in:0,1'],
            'exclude'        => ['nullable','integer','in:0,1'],
        ]);

        try {
            return DB::transaction(function () use ($row, $companyId, $data, $request) {

                $workstation = $request->header('X-Workstation-ID') ?? $request->ip() ?? gethostname();
                $userId = Auth::id() ?: null;

                $prefix2 = substr($data['main_acct_code'], 0, 2);
                $incoming = $data['acct_code'] ?? $row->acct_code;

                // If user changed main account or acct_code prefix mismatch, regenerate acct_code
                if (substr((string)$incoming,0,2) !== $prefix2) {
                    $maxSuffix = AccountCode::query()
                        ->where('company_id', $companyId)
                        ->where('acct_code','LIKE',$prefix2.'__')
                        ->select(DB::raw("MAX(CAST(SUBSTRING(acct_code,3,2) AS INTEGER)) AS max_suffix"))
                        ->value('max_suffix');
                    $nextSuffix = (int)$maxSuffix + 1;
                    if ($nextSuffix < 1) $nextSuffix = 1;
                    $incoming = $prefix2 . str_pad((string)$nextSuffix, 2, '0', STR_PAD_LEFT);
                }

                $row->update([
                    'main_acct'      => $data['main_acct'],
                    'main_acct_code' => $data['main_acct_code'],
                    'acct_code'      => $incoming,
                    'acct_desc'      => $data['acct_desc'],
                    'fs'             => $data['fs'] ?? null,
                    'acct_group'     => $data['acct_group'] ?? null,
                    'acct_group_sub1'=> $data['acct_group_sub1'] ?? null,
                    'acct_group_sub2'=> $data['acct_group_sub2'] ?? null,
                    'normal_bal'     => $data['normal_bal'] ?? null,
                    'acct_type'      => $data['acct_type'] ?? null,
                    'cash_disbursement_flag' => $data['cash_disbursement_flag'] ?? '',
                    'bank_id'        => $data['bank_id'] ?? null,
                    'vessel_flag'    => $data['vessel_flag'] ?? '',
                    'booking_no'     => $data['booking_no'] ?? null,
                    'ap_ar'          => $data['ap_ar'] ?? null,
                    'active_flag'    => isset($data['active_flag']) ? (int)$data['active_flag'] : $row->active_flag,
                    'exclude'        => isset($data['exclude']) ? (int)$data['exclude'] : $row->exclude,
                    'workstation_id' => $workstation,
                    'user_id'        => $userId,
                ]);

                return response()->json(['status'=>'success','message'=>'Account updated','data'=>$row]);
            });
        } catch (QueryException $e) {
            return $this->pgError($e, 'updating');
        }
    }

    /** Delete */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $row = AccountCode::where('id',$id)->where('company_id',$companyId)->firstOrFail();
        $row->delete();
        return response()->json(['status'=>'success','message'=>'Account deleted']);
    }

    /** account_main lookup list (with pagination + search) handled by another controller */

    // ------ helpers ------
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

    private function pgError(QueryException $e, string $action)
    {
        $detail = $e->errorInfo[2] ?? $e->getMessage();
        $state  = $e->errorInfo[0] ?? null;

        if (str_contains($detail,'23505') || $state === '23505')
            return response()->json(['status'=>'error','message'=>'Duplicate value (acct_code or acct_number).','detail'=>$detail], 409);

        if (str_contains($detail,'23503') || $state === '23503')
            return response()->json(['status'=>'error','message'=>'Foreign key violation.','detail'=>$detail], 422);

        if (str_contains($detail,'23502') || $state === '23502')
            return response()->json(['status'=>'error','message'=>'Missing required value.','detail'=>$detail], 422);

        return response()->json(['status'=>'error','message'=>"Database error while {$action} account.",'detail'=>$detail], 500);
    }
}
