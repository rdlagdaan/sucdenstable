<?php 
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PbnEntry;
use App\Models\PbnEntryDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PbnEntryController extends Controller
{
    
    public function index(Request $request)
    {
        $postedFlag = $request->query('postedFlag'); // expects 1 or 0

        $query = DB::table('pbn_entry')
            ->select('pbn_number', 'sugar_type', 'vend_code', 'vendor_name', 'crop_year', 'pbn_date')
            ->where('visible_flag', 1);

        if ($postedFlag == 1) {
            $query->where('posted_flag', 1);
        } else {
            $query->where(function ($q) {
                $q->whereNull('posted_flag')->orWhere('posted_flag', '!=', 1);
            });
        }

        return response()->json($query->get());
    }    
    
    
    
    
    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'pbn_number' => 'required|string|max:20|unique:pbn_entry',
            'pbn_date' => 'required|date',
            'sugar_type' => 'required|string|max:2',
            'crop_year' => 'required|string|max:5',
            'vend_code' => 'required|string|max:25',
            'vendor_name' => 'required|string|max:200',
            'details' => 'array|required',
        ]);

        DB::beginTransaction();
        try {
            $entry = PbnEntry::create([
                'pbn_number' => $validated['pbn_number'],
                'pbn_date' => $validated['pbn_date'],
                'sugar_type' => $validated['sugar_type'],
                'crop_year' => $validated['crop_year'],
                'vend_code' => $validated['vend_code'],
                'vendor_name' => $validated['vendor_name'],
                'company_id' => 1, // You can make this dynamic
                'user_id' => auth()->id() ?? 1,
                'visible_flag' => 1,
            ]);

            foreach ($validated['details'] as $detail) {
                PbnEntryDetail::create([
                    'pbn_entry_id' => $entry->id,
                    'mill' => $detail['mill'],
                    'quantity' => $detail['quantity'],
                    'commission' => $detail['commission'],
                    'cost' => $detail['cost'],
                    'total_cost' => $detail['total_cost'],
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'PBN Entry created successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to save PBN entry', 'details' => $e->getMessage()], 500);
        }
    }

public function storeMain(Request $request)
{
    $validated = $request->validate([
        'sugar_type' => 'required',
        'crop_year' => 'required',
        'pbn_date' => 'required|date',
        'vend_code' => 'required',
        'vendor_name' => 'required',
        'pbn_number' => 'required',
        'posted_flag' => 'required|boolean',
        'company_id' => 'required|integer',
    ]);

    $entry = PbnEntry::create([
        ...$validated,
        'date_created' => now(),
        'created_by' => auth()->user()->id ?? null,
    ]);

    return response()->json([
        'id' => $entry->id,
        'pbn_number' => $entry->pbn_number,
        'message' => 'Main PBN entry saved successfully.',
    ]);
}


public function saveDetail(Request $request)
{
    $validated = $request->validate([
        'pbn_entry_id' => 'required|integer',
        'pbn_number' => 'required|string',
        'mill' => 'required|string',
        'mill_code' => 'required|string',
        'quantity' => 'required|numeric',
        'unit_cost' => 'required|numeric',
        'commission' => 'required|numeric',
        'company_id' => 'required|numeric',        
        'user_id' => 'required|numeric',        
    ]);

    $quantity = floatval($validated['quantity']);
    $unitCost = floatval($validated['unit_cost']);
    $commission = floatval($validated['commission']);

    // Formulas
    $cost = round($quantity * $unitCost * 100) / 100;
    $totalCommission = $quantity * $commission;
    $totalCost = $cost + $totalCommission;

    $rowCount = PbnEntryDetail::where('pbn_entry_id', $validated['pbn_entry_id'])->count();

    $detail = new PbnEntryDetail();
    $detail->pbn_entry_id = $validated['pbn_entry_id'];
    $detail->row = $rowCount;
    $detail->pbn_number = $validated['pbn_number'];
    $detail->mill = $validated['mill'];
    $detail->mill_code = $validated['mill_code'];
    $detail->quantity = $quantity;
    $detail->unit_cost = $unitCost;
    $detail->commission = $commission;
    $detail->cost = $cost;
    $detail->total_commission = $totalCommission;
    $detail->total_cost = $totalCost;
    $detail->selected_flag = 0;
    $detail->delete_flag = 0;
    $detail->workstation_id = $request->ip(); // IP address of the client
    $detail->user_id = $validated['user_id'];            // current logged-in user
    $detail->company_id = $validated['company_id'];            // current logged-in user
    $detail->save();

    return response()->json([
        'message' => 'Detail saved successfully',
        'detail_id' => $detail->id
    ]);
}


public function updateDetail(Request $request)
{
    $validator = Validator::make($request->all(), [
        'pbn_entry_id' => 'required|integer',
        'row' => 'required|integer',
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
    }

    DB::table('pbn_entry_details')
        ->where('pbn_entry_id', $request->pbn_entry_id)
        ->where('row', $request->row)
        ->update([
            'mill' => $request->mill,
            'mill_code' => $request->mill_code,
            'quantity' => $request->quantity,
            'unit_cost' => $request->unit_cost,
            'commission' => $request->commission,
            'cost' => $request->cost,
            'total_commission' => $request->total_commission,
            'total_cost' => $request->total_cost,
            'updated_at' => now(),
        ]);

    return response()->json(['message' => 'Detail updated']);
}



public function deleteDetailAndLog(Request $request)
{
    $validated = $request->validate([
        'pbn_entry_id' => 'required|integer',
        'pbn_number' => 'required|string',
        'row' => 'required|integer',
    ]);

    // ✅ Step 1: Fetch the original record to be deleted
    $record = DB::table('pbn_entry_details')
        ->where('pbn_entry_id', $validated['pbn_entry_id'])
        ->where('pbn_number', $validated['pbn_number'])
        ->where('id', $validated['row'])
        ->first();

    // 🔒 Step 2: If record doesn't exist, return 404
    if (!$record) {
        return response()->json(['message' => 'Record not found.'], 404);
    }

    // ✅ Step 3: Prepare the data for logging
    $data = (array) $record;
    $data['nid'] = $data['id'];  // move original id to `nid` field
    unset($data['id']);          // let the log table auto-increment its own id

    // ✅ Step 4: Insert to log table
    DB::table('pbn_entry_details_log')->insert($data);

    // ✅ Step 5: Delete the original record
    DB::table('pbn_entry_details')
        ->where('pbn_entry_id', $validated['pbn_entry_id'])
        ->where('pbn_number', $validated['pbn_number'])
        ->where('id', $validated['row'])
        ->delete();

    return response()->json(['message' => '✅ Deleted and logged successfully.']);
}


public function getPbnDropdownList(Request $request)
{
    $companyId = $request->query('company_id');

    // Checkbox sends "true" (string) if checked → show posted (1)
    // Otherwise show unposted (0)
    $includePosted = $request->query('include_posted') === 'true';
    $postedFlag = $includePosted ? 1 : 0;

    $entries = DB::table('pbn_entry')
        ->where('company_id', $companyId)
        ->where('posted_flag', $postedFlag)
        ->select([
            'id',
            'pbn_number',
            'sugar_type',
            //'vend_code',
            'vendor_name',
            'crop_year',
            'pbn_date',
            'posted_flag'
        ])
        ->orderByDesc('pbn_number')
        ->get();

       
    return response()->json($entries);
}


public function show(Request $request, $id)
{
    $companyId = $request->query('company_id'); // ✅ Read from query params

    $main = PbnEntry::where('id', intval($id))
        ->where('company_id', $companyId)
        ->first();

    if (!$main) {
        return response()->json(['message' => 'Not found'], 404);
    }

    $details = PbnEntryDetail::where('pbn_entry_id', $main->id)
        ->where('company_id', $companyId)
        ->get();

    return response()->json([
        'main' => $main,
        'details' => $details,
    ]);
}





}
