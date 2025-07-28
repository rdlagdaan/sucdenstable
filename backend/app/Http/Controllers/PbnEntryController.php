<?php 
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PbnEntry;
use App\Models\PbnEntryDetail;
use Illuminate\Support\Facades\DB;

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
}
