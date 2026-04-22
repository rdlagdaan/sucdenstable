<?php 
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PbnEntry;
use App\Models\PbnEntryDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str; // at the top of the file if not present
use Illuminate\Support\Facades\Schema;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;


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
    
    

public function storeMain(Request $request)
{
    $validated = $request->validate([
        'sugar_type'             => 'required',
        'crop_year'              => 'required',
        'pbn_date'               => 'required|date',
        'vend_code'              => 'required',
        'vendor_name'            => 'required',
        'terms'                  => 'nullable|string|max:20',
        'note'                   => 'nullable|string',
        'vatable_sales_flag'     => 'required|integer|in:0,1',
        'zero_rated_sales_flag'  => 'required|integer|in:0,1',
        'vat_exempt_sales_flag'  => 'required|integer|in:0,1',
        'posted_flag'            => 'required|boolean',
        'company_id'             => 'required|integer',
    ]);

    if (
        ((int) $validated['vatable_sales_flag']) +
        ((int) $validated['zero_rated_sales_flag']) +
        ((int) $validated['vat_exempt_sales_flag']) !== 1
    ) {
        return response()->json(['message' => 'Exactly one VAT type must be selected.'], 422);
    }

    try {
        return DB::transaction(function () use ($validated) {
            $companyId = (int) $validated['company_id'];

            $setting = DB::table('application_settings')
                ->where('apset_code', 'PONoImpExp')
                ->lockForUpdate()
                ->first();

            if (!$setting) {
                return response()->json([
                    'message' => 'PONoImpExp setting not found in application_settings.',
                ], 500);
            }

            $current  = (int) ($setting->value ?? 0);
            $next     = $current + 1;
            $width    = max(strlen((string) ($setting->value ?? '0')), 5);
            $poNumber = str_pad((string) $next, $width, '0', STR_PAD_LEFT);

            DB::table('application_settings')
                ->where('apset_code', 'PONoImpExp')
                ->update([
                    'value'      => $poNumber,
                    'updated_at' => now(),
                ]);

            $payload = [
                'pbn_number'             => $poNumber,
                'pbn_date'               => $validated['pbn_date'],
                'sugar_type'             => $validated['sugar_type'],
                'crop_year'              => $validated['crop_year'],
                'vend_code'              => $validated['vend_code'],
                'vendor_name'            => $validated['vendor_name'],
                'note'                   => $validated['note'] ?? null,
                'vatable_sales_flag'     => (int) $validated['vatable_sales_flag'],
                'zero_rated_sales_flag'  => (int) $validated['zero_rated_sales_flag'],
                'vat_exempt_sales_flag'  => (int) $validated['vat_exempt_sales_flag'],
                'posted_flag'            => !empty($validated['posted_flag']) ? 1 : 0,
                'company_id'             => $companyId,
                'created_at'             => now(),
                'updated_at'             => now(),
            ];

            // ✅ Only include columns that actually exist in this build
            if (Schema::hasColumn('pbn_entry', 'po_number')) {
                $payload['po_number'] = $poNumber;
            }

            if (Schema::hasColumn('pbn_entry', 'terms')) {
                $payload['terms'] = $validated['terms'] ?? null;
            }

            if (Schema::hasColumn('pbn_entry', 'visible_flag')) {
                $payload['visible_flag'] = 1;
            }

            if (Schema::hasColumn('pbn_entry', 'user_id')) {
                $payload['user_id'] = auth()->id() ?? null;
            }

            $id = DB::table('pbn_entry')->insertGetId($payload);

            return response()->json([
                'id'        => $id,
                'po_number' => $poNumber,
            ]);
        });
    } catch (\Throwable $e) {
        \Log::error('PbnEntryController.storeMain failed', [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
            'payload' => $validated,
        ]);

        return response()->json([
            'message' => $e->getMessage(),
        ], 500);
    }
}
    
    
    

public function updateMain(Request $request)
{
    $validated = $request->validate([
        'id'                    => 'required|integer',
        'sugar_type'            => 'required',
        'crop_year'             => 'required',
        'pbn_date'              => 'required|date',
        'vend_code'             => 'required',
        'vendor_name'           => 'required',
        'note'                  => 'nullable|string',
        'terms'                 => 'nullable|string|max:20',
        'vatable_sales_flag'    => 'required|integer|in:0,1',
        'zero_rated_sales_flag' => 'required|integer|in:0,1',
        'vat_exempt_sales_flag' => 'required|integer|in:0,1',
        'company_id'            => 'required|integer',
    ]);

    if (
        ((int) $validated['vatable_sales_flag']) +
        ((int) $validated['zero_rated_sales_flag']) +
        ((int) $validated['vat_exempt_sales_flag']) !== 1
    ) {
        return response()->json(['message' => 'Exactly one VAT type must be selected.'], 422);
    }

    $companyId = (int) $validated['company_id'];
    $id        = (int) $validated['id'];

    // Only allow updates if NOT posted
    $main = \DB::table('pbn_entry')
        ->where('id', $id)
        ->where('company_id', $companyId)
        ->where('visible_flag', 1)
        ->first(['id', 'posted_flag']);

    if (!$main) {
        return response()->json(['message' => 'Not found'], 404);
    }

    if ((int)($main->posted_flag ?? 0) === 1) {
        return response()->json(['message' => 'Cannot update. PO is already posted.'], 409);
    }

    $payload = [
        'sugar_type'             => $validated['sugar_type'],
        'crop_year'              => $validated['crop_year'],
        'pbn_date'               => $validated['pbn_date'],
        'vend_code'              => $validated['vend_code'],
        'vendor_name'            => $validated['vendor_name'],
        'note'                   => $validated['note'] ?? null,
        'vatable_sales_flag'     => (int) $validated['vatable_sales_flag'],
        'zero_rated_sales_flag'  => (int) $validated['zero_rated_sales_flag'],
        'vat_exempt_sales_flag'  => (int) $validated['vat_exempt_sales_flag'],
        'updated_at'             => now(),
    ];

if (\Illuminate\Support\Facades\Schema::hasColumn('pbn_entry', 'terms')) {
    $payload['terms'] = $validated['terms'] ?? null;
}

    \DB::table('pbn_entry')
        ->where('id', $id)
        ->where('company_id', $companyId)
        ->where('visible_flag', 1)
        ->update($payload);

    return response()->json([
        'message' => 'Main updated',
        'id'      => $id,
    ]);
}



public function saveDetail(Request $request)
{
    $validated = $request->validate([
        'pbn_entry_id'   => 'required|integer',
        'pbn_number'     => 'required|string',   // still used; equals po_number in UI
        'particulars'    => 'required|string',   // ✅ NEW
        'mill'           => 'required|string',
        'mill_code'      => 'required|string',
        'quantity'       => 'required|numeric',
        'price'          => 'required|numeric',  // ✅ renamed from unit_cost
        'handling_fee'   => 'required|numeric',  // ✅ NEW
        'commission'     => 'required|numeric',
        'company_id'     => 'required',          // schema currently varchar; accept as-is
        'user_id'        => 'required',          // schema currently varchar; accept as-is
        'row'            => 'nullable|integer',  // frontend sends rowIndex; optional
    ]);

    $qty   = (float) $validated['quantity'];
    $price = (float) $validated['price'];
    $hf    = (float) $validated['handling_fee'];
    $com   = (float) $validated['commission'];

    // ✅ Formulas (2-decimal safe)
    $cost            = round($qty * $price, 2);
    $totalCommission = round($qty * $com, 2);
    $handling        = round($price * $hf, 2);
    $totalCost       = round($cost + $totalCommission + $handling, 2);

    // ✅ Row sequencing: if client sends row, honor it; else compute next row
    $nextRow = isset($validated['row'])
        ? (int) $validated['row']
        : (int) (PbnEntryDetail::where('pbn_entry_id', $validated['pbn_entry_id'])
            ->where('company_id', (string) $validated['company_id'])
            ->max('row') ?? -1) + 1;

    $detail = new PbnEntryDetail();
    $detail->pbn_entry_id       = (int) $validated['pbn_entry_id'];
    $detail->row                = $nextRow;
    $detail->pbn_number         = (string) $validated['pbn_number'];

    $detail->particulars        = (string) $validated['particulars'];  // ✅ NEW
    $detail->mill               = (string) $validated['mill'];
    $detail->mill_code          = (string) $validated['mill_code'];

    $detail->quantity           = $qty;
    $detail->price              = $price;                              // ✅ renamed
    $detail->handling_fee       = $hf;                                 // ✅ NEW
    $detail->commission         = $com;

    $detail->cost               = $cost;
    $detail->total_commission   = $totalCommission;                    // ✅ ensure exists
    $detail->handling           = $handling;                           // ✅ NEW
    $detail->total_cost         = $totalCost;

    $detail->selected_flag      = 0;
    $detail->delete_flag        = 0;

    $detail->workstation_id     = (string) $request->ip();
    $detail->user_id            = (string) $validated['user_id'];
    $detail->company_id         = (string) $validated['company_id'];

    $detail->created_at         = now();
    $detail->updated_at         = now();

    $detail->save();

    return response()->json([
        'message'   => 'Detail saved successfully',
        'detail_id' => $detail->id,
        'row'       => $detail->row,
    ]);
}




public function updateDetail(Request $request)
{
    // ✅ Prefer updating by detail "id" if present (stable), fallback to (pbn_entry_id,row)
    $validator = Validator::make($request->all(), [
        'pbn_entry_id' => 'required|integer',
        'company_id'   => 'required',

        'id'           => 'nullable|integer',   // preferred key (detail id)
        'row'          => 'nullable|integer',   // fallback key (detail row)

        'pbn_number'   => 'required|string',
        'particulars'  => 'required|string',
        'mill'         => 'required|string',
        'mill_code'    => 'required|string',
        'quantity'     => 'required|numeric',
        'price'        => 'required|numeric',
        'handling_fee' => 'required|numeric',
        'commission'   => 'required|numeric',

        // computed fields may be sent from UI; we will recompute anyway
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
    }

    $pbnEntryId = (int) $request->pbn_entry_id;
    $companyId  = (string) $request->company_id;

    $qty   = (float) $request->quantity;
    $price = (float) $request->price;
    $hf    = (float) $request->handling_fee;
    $com   = (float) $request->commission;

    // ✅ Recompute server-side (single source of truth)
    $cost            = round($qty * $price, 2);
    $totalCommission = round($qty * $com, 2);
    $handling        = round($price * $hf, 2);
    $totalCost       = round($cost + $totalCommission + $handling, 2);

    $q = DB::table('pbn_entry_details')
        ->where('pbn_entry_id', $pbnEntryId)
        ->where('company_id', $companyId);

    if ($request->filled('id')) {
        $q->where('id', (int) $request->id);
    } else {
        // fallback: row is required if id is not provided
        if (!$request->filled('row')) {
            return response()->json(['message' => 'Missing detail identifier (id or row).'], 422);
        }
        $q->where('row', (int) $request->row);
    }

    $updated = $q->update([
        'pbn_number'       => (string) $request->pbn_number,
        'particulars'      => (string) $request->particulars,
        'mill'             => (string) $request->mill,
        'mill_code'        => (string) $request->mill_code,
        'quantity'         => $qty,
        'price'            => $price,
        'handling_fee'     => $hf,
        'commission'       => $com,
        'cost'             => $cost,
        'total_commission' => $totalCommission,
        'handling'         => $handling,
        'total_cost'       => $totalCost,
        'updated_at'       => now(),
    ]);

    if (!$updated) {
        return response()->json(['message' => 'Detail not found (or already removed).'], 404);
    }

    return response()->json(['message' => 'Detail updated']);
}




public function deleteDetailAndLog(Request $request)
{
    // ✅ Frontend currently sends: row: rowData.id  (so treat "row" as detail_id)
    $validated = $request->validate([
        'pbn_entry_id' => 'required|integer',
        'pbn_number'   => 'required|string',
        'company_id'   => 'required|integer',

        // keep request shape: "row" is actually the detail id
        'row'          => 'required|integer',
    ]);

    $companyId = (int) $validated['company_id'];
    $detailId  = (int) $validated['row'];
    $entryId   = (int) $validated['pbn_entry_id'];

    // ✅ Fetch strictly by detail id + entry + company (most reliable)
    $record = DB::table('pbn_entry_details')
        ->where('id', $detailId)
        ->where('pbn_entry_id', $entryId)
        ->where('company_id', (string) $companyId)
        ->first();

    if (!$record) {
        return response()->json(['message' => 'Record not found.'], 404);
    }

    // Log snapshot
    $data = (array) $record;
    $data['nid'] = $data['id']; // keep original id in log
    unset($data['id']);

    DB::table('pbn_entry_details_log')->insert($data);

    // Delete the row
    DB::table('pbn_entry_details')
        ->where('id', $detailId)
        ->where('pbn_entry_id', $entryId)
        ->where('company_id', (string) $companyId)
        ->delete();

    return response()->json(['message' => '✅ Deleted and logged successfully.']);
}


public function particulars(Request $request)
{
    $data = $request->validate([
        'company_id' => ['required','integer'],
    ]);

    $companyId = (int) $data['company_id'];

    // Return in the exact shape the frontend expects: particular_name
    $rows = DB::table('purchase_order_particulars')
        ->where('company_id', $companyId)
        ->where('active_flag', 1)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get([
            'particular_name',
        ]);

    return response()->json($rows);
}


public function terms(Request $request)
{
    $data = $request->validate([
        'company_id' => ['nullable','integer'],
    ]);

    $rows = DB::table('purchase_order_terms')
        ->where('active_flag', 1)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get([
            'term_code',
            'term_name',
        ]);

    return response()->json($rows);
}


public function termsAdmin(Request $request)
{
    $request->validate([
        'company_id' => ['nullable', 'integer'],
    ]);

    $rows = DB::table('purchase_order_terms')
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get([
            'id',
            'term_code',
            'term_name',
            'active_flag',
            'sort_order',
        ]);

    return response()->json($rows);
}

public function storeTerm(Request $request)
{
    $validated = $request->validate([
        'term_code'   => ['required', 'string', 'max:20'],
        'term_name'   => ['required', 'string', 'max:100'],
        'active_flag' => ['required', 'integer', 'in:0,1'],
        'sort_order'  => ['nullable', 'integer'],
    ]);

    $exists = DB::table('purchase_order_terms')
        ->whereRaw('LOWER(term_code) = ?', [mb_strtolower(trim($validated['term_code']))])
        ->exists();

    if ($exists) {
        return response()->json(['message' => 'Term code already exists.'], 422);
    }

    $id = DB::table('purchase_order_terms')->insertGetId([
        'term_code'   => trim($validated['term_code']),
        'term_name'   => trim($validated['term_name']),
        'active_flag' => (int) $validated['active_flag'],
        'sort_order'  => (int) ($validated['sort_order'] ?? 0),
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    return response()->json([
        'message' => 'Term created successfully.',
        'id'      => $id,
    ]);
}

public function updateTerm(Request $request, int $id)
{
    $validated = $request->validate([
        'term_code'   => ['required', 'string', 'max:20'],
        'term_name'   => ['required', 'string', 'max:100'],
        'active_flag' => ['required', 'integer', 'in:0,1'],
        'sort_order'  => ['nullable', 'integer'],
    ]);

    $exists = DB::table('purchase_order_terms')
        ->where('id', '!=', $id)
        ->whereRaw('LOWER(term_code) = ?', [mb_strtolower(trim($validated['term_code']))])
        ->exists();

    if ($exists) {
        return response()->json(['message' => 'Term code already exists.'], 422);
    }

    $updated = DB::table('purchase_order_terms')
        ->where('id', $id)
        ->update([
            'term_code'   => trim($validated['term_code']),
            'term_name'   => trim($validated['term_name']),
            'active_flag' => (int) $validated['active_flag'],
            'sort_order'  => (int) ($validated['sort_order'] ?? 0),
            'updated_at'  => now(),
        ]);

    if (!$updated) {
        return response()->json(['message' => 'Term not found.'], 404);
    }

    return response()->json(['message' => 'Term updated successfully.']);
}



public function toggleTermActive(Request $request, int $id)
{
    $validated = $request->validate([
        'active_flag' => ['required', 'integer', 'in:0,1'],
    ]);

    $updated = DB::table('purchase_order_terms')
        ->where('id', $id)
        ->update([
            'active_flag' => (int) $validated['active_flag'],
            'updated_at'  => now(),
        ]);

    if (!$updated) {
        return response()->json(['message' => 'Term not found.'], 404);
    }

    return response()->json(['message' => 'Term status updated successfully.']);
}

public function getPbnDropdownList(Request $request)
{
    $data = $request->validate([
        'company_id'     => ['required', 'integer'],
        'include_posted' => ['nullable', 'in:true,false,1,0'],
    ]);

    $companyId     = (int) $data['company_id'];
    $includePosted = in_array((string) $request->query('include_posted', 'false'), ['true', '1'], true);

    $query = DB::table('pbn_entry')
        ->where('company_id', $companyId)
        ->where(function ($q) {
            $q->whereNull('visible_flag')
              ->orWhere('visible_flag', '!=', 0);
        });

    if ($includePosted) {
        $query->where('posted_flag', 1);
    } else {
        $query->where(function ($q) {
            $q->whereNull('posted_flag')
              ->orWhere('posted_flag', 0)
              ->orWhere('posted_flag', '!=', 1);
        });
    }

    $entries = $query
        ->select([
            'id',
            DB::raw('COALESCE(po_number, pbn_number) as pbn_number'),
            'sugar_type',
            'vend_code',
            'vendor_name',
            'crop_year',
            'pbn_date',
            DB::raw('COALESCE(posted_flag, 0) as posted_flag'),
        ])
        ->orderByDesc('pbn_date')
        ->orderByDesc('id')
        ->get();

    return response()->json($entries);
}



public function show(Request $request, $id)
{
    $data = $request->validate([
        'company_id' => ['required','integer'],
    ]);
    $companyId = (int) $data['company_id'];

    $main = PbnEntry::where('id', (int)$id)
        ->where('company_id', $companyId)
        ->first();

    if (!$main) {
        return response()->json(['message' => 'Not found'], 404);
    }

    $details = PbnEntryDetail::where('pbn_entry_id', $main->id)
        ->where('company_id', $companyId)
        ->orderBy('row')
        ->get();

    return response()->json([
        'main' => $main,
        'details' => $details,
    ]);
}



public function formPdf(\Illuminate\Http\Request $request, $id = null)
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    try { while (ob_get_level() > 0) { @ob_end_clean(); } } catch (\Throwable $e) {}
    @set_time_limit(20);
    @ini_set('max_execution_time', '20');

    // Load TCPDF
    if (!class_exists('\TCPDF', false)) {
        $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
        if (file_exists($tcpdfPath)) require_once $tcpdfPath;
    }

    $errorPdf = function (string $title, string $msg) {
        $pdf = new \TCPDF('P', PDF_UNIT, 'LETTER', true, 'UTF-8', false);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(false, 8);
        $pdf->AddPage('P', 'LETTER');
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->MultiCell(0, 0, $title, 0, 'L', false, 1);
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 0, $msg, 0, 'L', false, 1);

        $content = $pdf->Output('', 'S');

        return response($content, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="purchaseOrder.pdf"')
            ->header('Content-Length', (string) strlen($content))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    };

    try {
        if (!class_exists('\TCPDF', false)) {
            return $errorPdf('PURCHASE ORDER - ERROR', 'TCPDF not loaded (vendor/tecnickcom/tcpdf missing).');
        }

        $id = $id ?? $request->query('id');
        if (!$id) return $errorPdf('PURCHASE ORDER - ERROR', 'Missing id.');

        $companyId = $request->query('company_id');
        if (!$companyId) return $errorPdf('PURCHASE ORDER - ERROR', 'Missing company_id.');
        $companyId = (int) $companyId;

        try { \DB::statement("SET LOCAL statement_timeout = '8000ms'"); } catch (\Throwable $e) {}

        $main = \DB::table('pbn_entry')
            ->where('company_id', $companyId)
            ->where('visible_flag', 1)
            ->where(function ($q) use ($id) {
                $q->where('id', (int) $id)
                  ->orWhere('po_number', (string) $id)
                  ->orWhere('pbn_number', (string) $id);
            })
            ->first();

        if (!$main) {
            return $errorPdf('PURCHASE ORDER - NOT FOUND', "No record found.\n\nRequested: {$id}\ncompany_id: {$companyId}");
        }

        $details = \DB::table('pbn_entry_details')
            ->where('pbn_entry_id', (int) $main->id)
            ->where('company_id', (string) $companyId)
            ->where(function ($q) {
                $q->whereNull('delete_flag')->orWhere('delete_flag', '!=', 1);
            })
            ->orderBy('row')
            ->get();

        $decode = function ($s) {
            $s = (string) $s;
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $s = trim(preg_replace('/\s+/u', ' ', $s));
            return $s;
        };

        $decodeMultiline = function ($s) {
            $s = (string) $s;
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $s = str_replace(["\r\n", "\r"], "\n", $s);
            $s = preg_replace("/[ \t]+/u", ' ', $s);
            $s = preg_replace("/\n{3,}/u", "\n\n", $s);
            return trim($s);
        };

        // -----------------------------
        // Data preparation
        // -----------------------------
        $poNo       = $decode($main->po_number ?? $main->pbn_number ?? '');
        $vendorName = $decode($main->vendor_name ?? '');
        $note       = $decodeMultiline($main->note ?? '');

        $poDate = '';
        if (!empty($main->pbn_date)) {
            try {
                $poDate = \Carbon\Carbon::parse($main->pbn_date)->format('F d, Y');
            } catch (\Throwable $e) {
                $poDate = $decode($main->pbn_date);
            }
        }

        $cropYearDisplay = $decode($main->crop_year ?? '');
        $cropYearKey     = $decode($main->crop_year ?? '');
        if ($cropYearKey !== '') {
            $cy = \DB::table('crop_year')
                ->where('company_id', $companyId)
                ->where('crop_year', $cropYearKey)
                ->first();
            if ($cy && !empty($cy->begin_year) && !empty($cy->end_year)) {
                $cropYearDisplay = $decode($cy->begin_year) . '-' . $decode($cy->end_year);
            }
        }

        $terms = '';
        if (\Illuminate\Support\Facades\Schema::hasColumn('pbn_entry', 'terms')) {
            $terms = $decode($main->terms ?? '');
        }
        if (trim((string) $terms) === '') {
            foreach (['term', 'payment_terms', 'payment_term', 'currency', 'curr'] as $k) {
                if (isset($main->{$k})) {
                    $tmp = $decode($main->{$k});
                    if ($tmp !== '') { $terms = $tmp; break; }
                }
            }
        }

        $vendorAddress = '';
        $vendorCode = $decode($main->vend_code ?? '');
        $vendorNameLookup = $decode($main->vendor_name ?? '');

        $vendor = null;
        if ($vendorCode !== '' || $vendorNameLookup !== '') {
            $vendor = \DB::table('vendor_list')
                ->where('company_id', $companyId)
                ->where(function ($q) use ($vendorCode, $vendorNameLookup) {
                    if ($vendorCode !== '') $q->orWhere('vend_code', $vendorCode);
                    if ($vendorNameLookup !== '') $q->orWhere('vend_name', $vendorNameLookup);
                })
                ->first();
        }

        if ($vendor) {
            $vendorAddress = $decode($vendor->vendor_address ?? '');
            if (trim((string) $terms) === '') {
                $terms = $decode($vendor->terms ?? ($vendor->term ?? ($vendor->currency ?? '')));
            }
        }

        $logoPath = ($companyId === 2)
            ? public_path('ameropLogo.jpg')
            : public_path('sucdenLogo.jpg');

        if (!is_file($logoPath)) {
            $logoPath = ($companyId === 2)
                ? public_path('ameropLogo.png')
                : public_path('sucdenLogo.png');
        }

        $companyLine1 = ($companyId === 2) ? 'Amerop Philippines, Inc.' : 'Sucden Philippines, Inc.';
        $companyLine2 = 'Unit 2202, The Podium West Tower, 12 ADB Ave., Wack-Wack, Ortigas';
        $companyLine3 = 'Center, Mandaluyong City, Philippines 1550';
        $confirmedCompany = ($companyId === 2) ? 'Amerop Philippines, Inc.' : 'Sucden Philippines, Inc.';

        // -----------------------------
        // Normalize detail rows
        // -----------------------------
        $detailRows = [];
        $grandTotal = 0.0;
        $totalQty   = 0.0;
        $singlePriceCandidate = null;

        foreach ($details as $d) {
            $particulars = $decode($d->particulars ?? '');
            $millCode = $decode($d->mill_code ?? '');
            if ($millCode === '') $millCode = $decode($d->mill ?? '');

            $qty   = (float) ($d->quantity ?? 0);
            $price = (float) ($d->price ?? 0);
            $amount = (float) (isset($d->cost) ? $d->cost : ($qty * $price));
            $handlingFee = (float) ($d->handling_fee ?? 0);

            $isTextOnly = ($particulars !== '') && ($qty == 0.0) && ($price == 0.0) && ($amount == 0.0);

            if (!$isTextOnly) {
                $grandTotal += $amount;
                $totalQty   += $qty;
                if ($price > 0) {
                    if ($singlePriceCandidate === null) {
                        $singlePriceCandidate = $price;
                    } elseif (abs($singlePriceCandidate - $price) > 0.00001) {
                        $singlePriceCandidate = '';
                    }
                }
            }

            $detailRows[] = [
                'type'         => $isTextOnly ? 'text' : 'normal',
                'particulars'  => $particulars,
                'millmark'     => $millCode,
                'qty'          => $qty,
                'price'        => $price,
                'amount'       => $amount,
                'handling_fee' => $handlingFee,
            ];
        }

        // Total-row Price/Lkg should always show a value.
        // Use weighted average price = grand total / total quantity.
        $displayUnitPrice = '';
        if ($totalQty > 0) {
            $displayUnitPrice = number_format($grandTotal / $totalQty, 2);
        }

        // Summary box values based on saved mutually-exclusive VAT flags
        $vatableFlag   = (int) ($main->vatable_sales_flag ?? 0);
        $zeroRatedFlag = (int) ($main->zero_rated_sales_flag ?? 0);
        $vatExemptFlag = (int) ($main->vat_exempt_sales_flag ?? 0);

        $summaryValues = [
            'total_purchase'   => number_format($grandTotal, 2),
            'vatable_sales'    => $vatableFlag === 1 ? number_format($grandTotal, 2) : '',
            'vat'              => '',
            'zero_rated_sales' => $zeroRatedFlag === 1 ? number_format($grandTotal, 2) : '',
            'vat_exempt_sales' => $vatExemptFlag === 1 ? number_format($grandTotal, 2) : '',
        ];

        // Notes content
        $notesText = $note;
        if ($notesText === '') {
            $notesText = '';
        }

        // -----------------------------
        // PDF setup
        // -----------------------------
        $pdf = new \TCPDF('P', PDF_UNIT, 'LETTER', true, 'UTF-8', false);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);

        // ===========================================
        // LAYOUT / STYLE CONFIG (VARIABLE-DRIVEN)
        // ===========================================
        $layout = [
            'page' => [
                'orientation'   => 'P',
                'size'          => 'LETTER',
                'margin_left'   => 8,
                'margin_top'    => 7,
                'margin_right'  => 8,
                'margin_bottom' => 7,
            ],
            'colors' => [
                'brand_blue'        => [0, 112, 192],
                'po_blue'           => [31, 78, 121],
                'black'             => [0, 0, 0],
                'gray'              => [80, 80, 80],
                'line_gray'         => [120, 120, 120],
                'table_line_dark'   => [92, 92, 92],
                'table_outer_dark'  => [70, 70, 70],
                'table_header_fill' => [220, 230, 241],
                'white'             => [255, 255, 255],
            ],
            'lines' => [
                'blue_rule_width'     => 0.55,
                'field_underline'     => 0.10,
                'box_outer'           => 0.18,
                'box_inner'           => 0.10,
                'table_outer'         => 0.40,
                'table_inner'         => 0.28,
                'footer_divider'      => 0.12,
            ],
            'fonts' => [
                'base' => ['family' => 'helvetica', 'style' => '',  'size' => 9],
                'company_name'   => ['family' => 'helvetica', 'style' => 'B', 'size' => 11],
                'company_addr'   => ['family' => 'helvetica', 'style' => '',  'size' => 8],
                'title'          => ['family' => 'helvetica', 'style' => 'B', 'size' => 18],
                'po_label'       => ['family' => 'helvetica', 'style' => 'B', 'size' => 11],
                'po_value'       => ['family' => 'helvetica', 'style' => 'B', 'size' => 17],
                'field_label'    => ['family' => 'helvetica', 'style' => '',  'size' => 9],
                'field_value'    => ['family' => 'helvetica', 'style' => 'B', 'size' => 11],
                'table_header'   => ['family' => 'helvetica', 'style' => 'B', 'size' => 8],
                'table_row'      => ['family' => 'helvetica', 'style' => '',  'size' => 8],
                'table_total'    => ['family' => 'helvetica', 'style' => 'B', 'size' => 9],
                'notes_label'    => ['family' => 'helvetica', 'style' => 'B', 'size' => 8],
                'notes_body'     => ['family' => 'helvetica', 'style' => '',  'size' => 8],
                'legal'          => ['family' => 'helvetica', 'style' => '',  'size' => 7],
                'summary_label'  => ['family' => 'helvetica', 'style' => '',  'size' => 8],
                'summary_value'  => ['family' => 'helvetica', 'style' => 'B', 'size' => 8],
                'footer_label'   => ['family' => 'helvetica', 'style' => 'B', 'size' => 9],
                'footer_value'   => ['family' => 'helvetica', 'style' => '',  'size' => 9],
                'disclaimer'     => ['family' => 'helvetica', 'style' => 'I', 'size' => 8],
            ],
            'header' => [
                'logo' => [
                    'x' => 8,
                    'y' => 7.5,
                    'w' => 32,
                    'h' => 0,
                ],
                'company_name' => [
                    'x' => 48,
                    'y' => 8.0,
                    'w' => 125,
                    'align' => 'C',
                ],
                'company_addr1' => [
                    'x' => 48,
                    'y' => 12.5,
                    'w' => 125,
                    'align' => 'C',
                ],
                'company_addr2' => [
                    'x' => 48,
                    'y' => 16.0,
                    'w' => 125,
                    'align' => 'C',
                ],
                'rule' => [
                    'x1' => 8,
                    'y'  => 22.6,
                    'x2' => 208,
                ],
                'title' => [
                    'x' => 8,
                    'y' => 25.8,
                    'w' => 95,
                    'align' => 'L',
                ],
                'po_label' => [
                    'x' => 146,
                    'y' => 27.0,
                    'w' => 16,
                    'align' => 'R',
                    'text' => 'PO#:',
                ],
                'po_value' => [
                    'x' => 164.0,
                    'y' => 23.9,
                    'w' => 36.0,
                    'align' => 'L',
                ],
                'po_line' => [
                    'x1' => 164.0,
                    'y'  => 33.0,
                    'x2' => 200.0,
                ],
            ],
            'fields' => [
                'vendor' => [
                    'label' => ['x' => 8,   'y' => 39.0, 'w' => 18],
                    'value' => ['x' => 26,  'y' => 39.0, 'w' => 103],
                    'line'  => ['x1' => 8,  'y' => 45.2, 'x2' => 129],
                ],
                'terms' => [
                    'label' => ['x' => 136, 'y' => 39.0, 'w' => 16],
                    'value' => ['x' => 152, 'y' => 39.0, 'w' => 48],
                    'line'  => ['x1' => 152,'y' => 45.2, 'x2' => 200],
                ],
                'address' => [
                    'label' => ['x' => 8,   'y' => 47.3, 'w' => 18],
                    'value' => ['x' => 26,  'y' => 47.0, 'w' => 103, 'h' => 8.2],
                    'line'  => ['x1' => 8,  'y' => 56.2, 'x2' => 129],
                ],
                'crop_year' => [
                    'label' => ['x' => 136, 'y' => 50.3, 'w' => 20],
                    'value' => ['x' => 156, 'y' => 50.3, 'w' => 44],
                    'line'  => ['x1' => 136,'y' => 56.2, 'x2' => 200],
                ],
                'date' => [
                    'label' => ['x' => 8,   'y' => 58.0, 'w' => 18],
                    'value' => ['x' => 26,  'y' => 58.0, 'w' => 103],
                    'line'  => ['x1' => 8,  'y' => 64.2, 'x2' => 129],
                ],
            ],
            'table' => [
                'x' => 8,
                'y' => 67.0,
                'w' => 192.0,
                'header_h'    => 7.0,
                'row_h'       => 7.0,
                'small_row_h' => 5.0,
                'blank_row_h' => 7.0,
                'total_h'     => 7.0,
                'max_rows'    => 5,
                'padding_l'   => 1.2,
                'padding_r'   => 1.2,
                'cols' => [
                    'item'        => 10.0,
                    'particulars' => 66.0,
                    'millmark'    => 26.0,
                    'qty'         => 26.0,
                    'price'       => 30.0,
                    'amount'      => 34.0,
                ],
                'labels' => [
                    'item'        => 'Item',
                    'particulars' => 'Particulars',
                    'millmark'    => 'Millmark',
                    'qty'         => 'Qty in Lkg',
                    'price'       => 'Price/Lkg',
                    'amount'      => 'Amount',
                ],
            ],
            'notes' => [
                'label' => [
                    'x' => 8,
                    'y' => 140.0,
                    'w' => 16,
                    'text' => 'NOTES:',
                ],
                'box' => [
                    'x' => 8,
                    'y' => 145.0,
                    'w' => 112.0,
                    'h' => 16.0,
                ],
                'content' => [
                    'x' => 10.0,
                    'y' => 147.5,
                    'w' => 108.0,
                    'h' => 11.0,
                ],
            ],
            'legal' => [
                'x' => 8,
                'y' => 170.4,
                'w' => 108.0,
                'line_h' => 3.8,
                'text_lines' => [
                    'This document serves as the basis for booking purchased goods.',
                    'This is an important basis for the agreement between both parties regarding the delivery and payment of goods.',
                    'Any/All charges shall be arranged according to the specific set up of this order, if applicable.',
                    'For comprehensive terms and conditions governing this transaction, please refer to the executed contract.',
                ],
            ],
            'summary' => [
                'box' => [
                    'x' => 126.0,
                    'y' => 145.0,
                    'w' => 74.0,
                    'h' => 28.5,
                ],
                'row_h'    => 5.7,
                'label_w'  => 42.0,
                'value_w'  => 32.0,
                'rows' => [
                    ['label' => 'Total Purchase',   'key' => 'total_purchase'],
                    ['label' => 'Vatable Sales',    'key' => 'vatable_sales'],
                    ['label' => 'VAT',              'key' => 'vat'],
                    ['label' => 'Zero-Rated Sales', 'key' => 'zero_rated_sales'],
                    ['label' => 'VAT Exempt Sales', 'key' => 'vat_exempt_sales'],
                ],
            ],
            'footer' => [
                'box' => [
                    'x' => 8,
                    'y' => 188.5,
                    'w' => 192.0,
                    'h' => 26.0,
                ],
                'divider' => [
                    'x'  => 104.0,
                    'y1' => 188.5,
                    'y2' => 214.5,
                ],
                'left' => [
                    'label' => ['x' => 14.0,  'y' => 194.5, 'w' => 34.0, 'text' => 'Confirmed By:'],
                    'value' => ['x' => 49.0,  'y' => 194.5, 'w' => 46.0],
                ],
                'right' => [
                    'label' => ['x' => 112.0, 'y' => 194.5, 'w' => 66.0, 'text' => 'Conforme by Supplier'],
                ],
            ],
            'disclaimer' => [
                'x' => 8,
                'y' => 218.5,
                'w' => 192.0,
                'align' => 'C',
                'text' => '*This document is not valid for claiming input taxes*',
            ],
            'format' => [
                'qty_decimals'    => 2,
                'price_decimals'  => 2,
                'amount_decimals' => 2,
                'empty_amount_dash' => '-',
            ],
        ];

        $pdf->SetMargins(
            $layout['page']['margin_left'],
            $layout['page']['margin_top'],
            $layout['page']['margin_right']
        );
        $pdf->SetAutoPageBreak(false, $layout['page']['margin_bottom']);
        $pdf->AddPage($layout['page']['orientation'], $layout['page']['size']);

        // -----------------------------
        // Small render helpers
        // -----------------------------
        $applyFont = function ($key) use ($pdf, $layout) {
            $f = $layout['fonts'][$key];
            $pdf->SetFont($f['family'], $f['style'], $f['size']);
        };

        $setTextColor = function ($key) use ($pdf, $layout) {
            $c = $layout['colors'][$key];
            $pdf->SetTextColor($c[0], $c[1], $c[2]);
        };

        $setDrawColor = function ($key) use ($pdf, $layout) {
            $c = $layout['colors'][$key];
            $pdf->SetDrawColor($c[0], $c[1], $c[2]);
        };

        $drawText = function (
            float $x,
            float $y,
            float $w,
            string $text,
            string $fontKey,
            string $align = 'L',
            string $colorKey = 'black',
            float $h = 0
        ) use ($pdf, $applyFont, $setTextColor) {
            $applyFont($fontKey);
            $setTextColor($colorKey);
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $h, $text, 0, 0, $align, false, '', 0, false, 'T', 'T');
        };

        $drawLine = function (
            float $x1,
            float $y,
            float $x2,
            float $width,
            string $colorKey = 'line_gray'
        ) use ($pdf, $setDrawColor) {
            $setDrawColor($colorKey);
            $pdf->SetLineWidth($width);
            $pdf->Line($x1, $y, $x2, $y);
        };

        $drawField = function (
            array $cfg,
            string $labelText,
            string $valueText,
            bool $valueBold = true,
            bool $multiline = false
        ) use ($pdf, $layout, $applyFont, $setTextColor, $drawLine) {
            // Label
            $applyFont('field_label');
            $setTextColor('black');
            $pdf->SetXY($cfg['label']['x'], $cfg['label']['y']);
            $pdf->Cell($cfg['label']['w'], 0, $labelText, 0, 0, 'L', false, '', 0, false, 'T', 'T');

            // Value
            $applyFont($valueBold ? 'field_value' : 'field_label');
            $pdf->SetXY($cfg['value']['x'], $cfg['value']['y']);

            if ($multiline) {
                $pdf->MultiCell(
                    $cfg['value']['w'],
                    $cfg['value']['h'] ?? 6,
                    $valueText,
                    0,
                    'L',
                    false,
                    1
                );
            } else {
                $pdf->Cell($cfg['value']['w'], 0, $valueText, 0, 0, 'L', false, '', 0, false, 'T', 'T');
            }

            // Line
            $drawLine(
                $cfg['line']['x1'],
                $cfg['line']['y'],
                $cfg['line']['x2'],
                $cfg['line']['width'] ?? $layout['lines']['field_underline'],
                'black'
            );
        };

        $fitText = function (
            string $text,
            float $width,
            string $fontKey
        ) use ($pdf, $layout) {
            $f = $layout['fonts'][$fontKey];
            $pdf->SetFont($f['family'], $f['style'], $f['size']);
            $text = (string) $text;
            while ($text !== '' && $pdf->GetStringWidth($text) > $width) {
                $text = mb_substr($text, 0, -1);
            }
            return $text;
        };

        // -----------------------------
        // HEADER
        // -----------------------------
        if ($logoPath && is_file($logoPath)) {
            $pdf->Image(
                $logoPath,
                $layout['header']['logo']['x'],
                $layout['header']['logo']['y'],
                $layout['header']['logo']['w'],
                $layout['header']['logo']['h'],
                '',
                '',
                '',
                false,
                300,
                '',
                false,
                false,
                0,
                false,
                false,
                false
            );
        }

        $drawText(
            $layout['header']['company_name']['x'],
            $layout['header']['company_name']['y'],
            $layout['header']['company_name']['w'],
            $companyLine1,
            'company_name',
            $layout['header']['company_name']['align']
        );
        $drawText(
            $layout['header']['company_addr1']['x'],
            $layout['header']['company_addr1']['y'],
            $layout['header']['company_addr1']['w'],
            $companyLine2,
            'company_addr',
            $layout['header']['company_addr1']['align']
        );
        $drawText(
            $layout['header']['company_addr2']['x'],
            $layout['header']['company_addr2']['y'],
            $layout['header']['company_addr2']['w'],
            $companyLine3,
            'company_addr',
            $layout['header']['company_addr2']['align']
        );

        $drawLine(
            $layout['header']['rule']['x1'],
            $layout['header']['rule']['y'],
            $layout['header']['rule']['x2'],
            $layout['lines']['blue_rule_width'],
            'brand_blue'
        );

        $drawText(
            $layout['header']['title']['x'],
            $layout['header']['title']['y'],
            $layout['header']['title']['w'],
            'PURCHASE ORDER',
            'title',
            $layout['header']['title']['align']
        );

        $drawText(
            $layout['header']['po_label']['x'],
            $layout['header']['po_label']['y'],
            $layout['header']['po_label']['w'],
            $layout['header']['po_label']['text'],
            'po_label',
            $layout['header']['po_label']['align'],
            'po_blue'
        );
        $drawText(
            $layout['header']['po_value']['x'],
            $layout['header']['po_value']['y'],
            $layout['header']['po_value']['w'],
            $poNo,
            'po_value',
            $layout['header']['po_value']['align'],
            'po_blue'
        );
        $drawLine(
            $layout['header']['po_line']['x1'],
            $layout['header']['po_line']['y'],
            $layout['header']['po_line']['x2'],
            $layout['lines']['field_underline'],
            'black'
        );

        // -----------------------------
        // FIELDS
        // -----------------------------
        $drawField($layout['fields']['vendor'], 'Vendor:', $vendorName, true, false);
        $drawField($layout['fields']['terms'], 'Terms:', $terms, true, false);
        $drawField($layout['fields']['address'], 'Address:', $vendorAddress, false, true);
        $drawField($layout['fields']['crop_year'], 'Crop Year:', $cropYearDisplay, true, false);
        $drawField($layout['fields']['date'], 'Date:', $poDate, true, false);

        // -----------------------------
        // TABLE GEOMETRY
        // -----------------------------
        $t = $layout['table'];
        $x = $t['x'];
        $y = $t['y'];
        $w = $t['w'];

        $col = $t['cols'];
        $xItem = $x;
        $xPart = $xItem + $col['item'];
        $xMill = $xPart + $col['particulars'];
        $xQty  = $xMill + $col['millmark'];
        $xPrice= $xQty  + $col['qty'];
        $xAmt  = $xPrice + $col['price'];

        $rowsToDraw = max(count($detailRows), $t['max_rows']);
        $tableBodyHeight = ($t['header_h']) + ($rowsToDraw * $t['row_h']) + $t['total_h'];
        $tableBottomY = $y + $tableBodyHeight;

        // Table outer border base
        $setDrawColor('table_outer_dark');
        $pdf->SetLineWidth(0.40);
        $pdf->Rect($x, $y, $w, $tableBodyHeight);

        // Header fill
        $fill = $layout['colors']['table_header_fill'];
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->Rect($x, $y, $w, $t['header_h'], 'F');

        // Thin inner vertical lines only
        $setDrawColor('line_gray');
        $pdf->SetLineWidth(0.12);
        foreach ([$xPart, $xMill, $xQty, $xPrice, $xAmt] as $vx) {
            $pdf->Line($vx, $y, $vx, $tableBottomY);
        }

        // Thicker horizontal lines
        $setDrawColor('table_line_dark');
        $pdf->SetLineWidth(0.28);
        $pdf->Line($x, $y + $t['header_h'], $x + $w, $y + $t['header_h']);

        for ($i = 1; $i <= $rowsToDraw; $i++) {
            $yy = $y + $t['header_h'] + ($i * $t['row_h']);
            $pdf->Line($x, $yy, $x + $w, $yy);
        }

        // Header labels
        $drawText($xItem, $y + 1.6, $col['item'],        $t['labels']['item'],        'table_header', 'C');
        $drawText($xPart, $y + 1.6, $col['particulars'], $t['labels']['particulars'], 'table_header', 'C');
        $drawText($xMill, $y + 1.6, $col['millmark'],    $t['labels']['millmark'],    'table_header', 'C');
        $drawText($xQty,  $y + 1.6, $col['qty'],         $t['labels']['qty'],         'table_header', 'C');
        $drawText($xPrice,$y + 1.6, $col['price'],       $t['labels']['price'],       'table_header', 'C');
        $drawText($xAmt,  $y + 1.6, $col['amount'],      $t['labels']['amount'],      'table_header', 'C');

        // Detail rows
        $rowY = $y + $t['header_h'];
        $itemNo = 1;
        $renderedBaseRows = 0;

        foreach ($detailRows as $r) {
            if ($renderedBaseRows >= $t['max_rows']) {
                break;
            }

            $cy = $rowY + ($renderedBaseRows * $t['row_h']) + 1.5;

            if ($r['type'] === 'text') {
                $txt = $fitText($r['particulars'], $col['particulars'] - 2.5, 'table_row');
                $drawText($xPart + 1.0, $cy, $col['particulars'] - 2.0, $txt, 'table_row', 'L');
                if ($r['millmark'] !== '') {
                    $drawText($xMill, $cy, $col['millmark'], $fitText($r['millmark'], $col['millmark'] - 2, 'table_row'), 'table_row', 'C');
                }
                $drawText($xAmt, $cy, $col['amount'] - 1.0, $layout['format']['empty_amount_dash'], 'table_row', 'R');
            } else {
                $drawText($xItem, $cy, $col['item'], (string) $itemNo, 'table_row', 'C');

                $partText = $fitText($r['particulars'], $col['particulars'] - 2.5, 'table_row');
                $millText = $fitText($r['millmark'],    $col['millmark'] - 2.5,    'table_row');

                $drawText($xPart + 1.0, $cy, $col['particulars'] - 2.0, $partText, 'table_row', 'L');
                $drawText($xMill, $cy, $col['millmark'], $millText, 'table_row', 'C');
                $drawText($xQty,  $cy, $col['qty'] - 1.0, number_format($r['qty'], $layout['format']['qty_decimals']), 'table_row', 'R');
                $drawText($xPrice,$cy, $col['price'] - 1.0, number_format($r['price'], $layout['format']['price_decimals']), 'table_row', 'R');
                $drawText($xAmt,  $cy, $col['amount'] - 1.0, number_format($r['amount'], $layout['format']['amount_decimals']), 'table_row', 'R');

                $itemNo++;
            }

            $renderedBaseRows++;

            if ($r['type'] === 'normal' && $r['handling_fee'] > 0 && $renderedBaseRows < $t['max_rows']) {
                $cy2 = $rowY + ($renderedBaseRows * $t['row_h']) + 1.5;
                $hfText = 'Handling Fee P' . number_format($r['handling_fee'], 2) . ' per bag';
                $hfText = $fitText($hfText, $col['particulars'] - 2.5, 'table_row');
                $drawText($xPart + 1.0, $cy2, $col['particulars'] - 2.0, $hfText, 'table_row', 'L');
                $drawText($xAmt, $cy2, $col['amount'] - 1.0, $layout['format']['empty_amount_dash'], 'table_row', 'R');
                $renderedBaseRows++;
            }
        }

        // Blank rows
        while ($renderedBaseRows < $t['max_rows']) {
            $cy = $rowY + ($renderedBaseRows * $t['row_h']) + 1.5;
            $drawText($xAmt, $cy, $col['amount'] - 1.0, $layout['format']['empty_amount_dash'], 'table_row', 'R');
            $renderedBaseRows++;
        }

        // Total row fill
        $fill = $layout['colors']['table_header_fill'];
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->Rect($x, $tableBottomY - $t['total_h'], $w, $t['total_h'], 'F');

        // Redraw thin inner vertical separators inside the filled total row
        $setDrawColor('line_gray');
        $pdf->SetLineWidth(0.12);
        foreach ([$xPart, $xMill, $xQty, $xPrice, $xAmt] as $vx) {
            $pdf->Line($vx, $tableBottomY - $t['total_h'], $vx, $tableBottomY);
        }

        // Redraw the full outer frame LAST so left/right borders stay thick after fills
        $setDrawColor('table_outer_dark');
        $pdf->SetLineWidth(0.40);

        // top outer border
        $pdf->Line($x, $y, $x + $w, $y);

        // left outer border
        $pdf->Line($x, $y, $x, $tableBottomY);

        // right outer border
        $pdf->Line($x + $w, $y, $x + $w, $tableBottomY);

        // bottom outer border
        $pdf->Line($x, $tableBottomY, $x + $w, $tableBottomY);

        $totalY = $tableBottomY - $t['total_h'] + 1.5;
        $drawText($xItem + 1.0, $totalY, ($col['item'] + $col['particulars'] + $col['millmark']) - 2.0, 'Total', 'table_total', 'L');
        $drawText(
            $xQty,
            $totalY,
            $col['qty'] - 1.0,
            number_format($totalQty, $layout['format']['qty_decimals']),
            'table_total',
            'R'
        );

        // Price/Lkg column must show numeric value, not PHP
        $drawText(
            $xPrice,
            $totalY,
            $col['price'] - 1.0,
            $displayUnitPrice,
            'table_total',
            'R'
        );

        // PHP belongs in the Amount area, before the final total amount
        $drawText($xAmt + 1.2, $totalY, 12.0, 'PHP', 'table_total', 'L');
        $drawText(
            $xAmt + 10.0,
            $totalY,
            $col['amount'] - 11.0,
            number_format($grandTotal, 2),
            'table_total',
            'R'
        );

        // -----------------------------
        // NOTES
        // -----------------------------
        $drawText(
            $layout['notes']['label']['x'],
            $layout['notes']['label']['y'],
            $layout['notes']['label']['w'],
            $layout['notes']['label']['text'],
            'notes_label',
            'L'
        );

        $pdf->SetLineWidth($layout['lines']['box_outer']);
        $setDrawColor('line_gray');
        $pdf->Rect(
            $layout['notes']['box']['x'],
            $layout['notes']['box']['y'],
            $layout['notes']['box']['w'],
            $layout['notes']['box']['h']
        );

        $applyFont('notes_body');
        $setTextColor('black');
        $pdf->SetXY($layout['notes']['content']['x'], $layout['notes']['content']['y']);
        $pdf->MultiCell(
            $layout['notes']['content']['w'],
            3.8,
            $notesText,
            0,
            'L',
            false,
            1
        );

        // -----------------------------
        // LEGAL TEXT
        // -----------------------------
        $applyFont('legal');
        $setTextColor('black');

        $legalX = $layout['legal']['x'];
        $legalY = $layout['legal']['y'];
        $legalW = $layout['legal']['w'];
        $legalLineH = $layout['legal']['line_h'];
        $legalLines = $layout['legal']['text_lines'] ?? [];

        foreach ($legalLines as $i => $line) {
            $pdf->SetXY($legalX, $legalY + ($i * $legalLineH));
            $pdf->Cell($legalW, 0, $line, 0, 0, 'L', false, '', 0, false, 'T', 'T');
        }

        // -----------------------------
        // SUMMARY BOX
        // -----------------------------
        $sx = $layout['summary']['box']['x'];
        $sy = $layout['summary']['box']['y'];
        $sw = $layout['summary']['box']['w'];
        $sh = $layout['summary']['box']['h'];

        $pdf->SetLineWidth($layout['lines']['box_outer']);
        $pdf->Rect($sx, $sy, $sw, $sh);

        $labelW = $layout['summary']['label_w'];
        $rowH = $layout['summary']['row_h'];
        $valueX = $sx + $labelW;

        $pdf->SetLineWidth($layout['lines']['box_inner']);
        $pdf->Line($valueX, $sy, $valueX, $sy + $sh);

        $currentY = $sy;
        foreach ($layout['summary']['rows'] as $index => $row) {
            if ($index > 0) {
                $pdf->Line($sx, $currentY, $sx + $sw, $currentY);
            }

            $drawText($sx + 1.2, $currentY + 1.7, $labelW - 2.4, $row['label'], 'summary_label', 'L');
            $drawText($valueX + 1.2, $currentY + 1.7, $sw - $labelW - 2.4, (string) ($summaryValues[$row['key']] ?? ''), 'summary_value', 'R');

            $currentY += $rowH;
        }

        // -----------------------------
        // FOOTER
        // -----------------------------
        $fx = $layout['footer']['box']['x'];
        $fy = $layout['footer']['box']['y'];
        $fw = $layout['footer']['box']['w'];
        $fh = $layout['footer']['box']['h'];

        $pdf->SetLineWidth(0.40); // 🔴 thicker outer box
        $pdf->Rect($fx, $fy, $fw, $fh);

            $pdf->SetLineWidth(0.40); // 🔴 match outer box thickness
            $pdf->Line(
            $layout['footer']['divider']['x'],
            $layout['footer']['divider']['y1'],
            $layout['footer']['divider']['x'],
            $layout['footer']['divider']['y2']
        );

        $drawText(
            $layout['footer']['left']['label']['x'],
            $layout['footer']['left']['label']['y'],
            $layout['footer']['left']['label']['w'],
            $layout['footer']['left']['label']['text'],
            'footer_label',
            'L'
        );
        $drawText(
            $layout['footer']['left']['value']['x'],
            $layout['footer']['left']['value']['y'],
            $layout['footer']['left']['value']['w'],
            $confirmedCompany,
            'footer_value',
            'L'
        );

        $drawText(
            $layout['footer']['right']['label']['x'],
            $layout['footer']['right']['label']['y'],
            $layout['footer']['right']['label']['w'],
            $layout['footer']['right']['label']['text'],
            'footer_label',
            'L'
        );

        // -----------------------------
        // DISCLAIMER
        // -----------------------------
        $drawText(
            $layout['disclaimer']['x'],
            $layout['disclaimer']['y'],
            $layout['disclaimer']['w'],
            $layout['disclaimer']['text'],
            'disclaimer',
            $layout['disclaimer']['align']
        );

        $content = $pdf->Output('purchaseOrder.pdf', 'S');

        return response($content, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="purchaseOrder.pdf"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');

    } catch (\Throwable $e) {
        return $errorPdf(
            'PURCHASE ORDER - EXCEPTION',
            $e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine()
        );
    }
}    



public function formExcel(Request $request, $id = null)
{
    $id = $id ?? $request->query('id');
    if (!$id) {
        return response()->json([
            'message' => 'Missing PBN id. Call /api/pbn/form-excel/{id} or /api/pbn/form-excel?id={id}',
        ], 422);
    }

    $validated = $request->validate([
        'company_id' => ['required','integer'],
    ]);
    $companyId = (int) $validated['company_id'];

    $main = PbnEntry::where('id', (int)$id)
        ->where('company_id', $companyId)
        ->firstOrFail();

    $details = PbnEntryDetail::where('pbn_entry_id', $main->id)
        ->where('company_id', $companyId)
        ->orderBy('row')
        ->get();

    $cropYear    = $main->crop_year ?? '';
    $currentDate = now()->format('M d, Y h:i');

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Randy D. Lagdaan')
        ->setLastModifiedBy('Randy D. Lagdaan')
        ->setTitle('PBN Form')
        ->setSubject('Office XLS Document')
        ->setDescription('PBN Form')
        ->setKeywords('Excel PHP')
        ->setCategory('PBN Form');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('PO Details');

    // --- header block (kept, minimal change) ---
    $sheet->setCellValue('A1', 'Shipper: SUCDEN PHILIPPINES, INC'); $sheet->mergeCells('A1:I1');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A2', 'Buyer: SUCDEN AMERICAS CORP.');     $sheet->mergeCells('A2:I2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A3', 'PO Details (CY' . $cropYear . ')'); $sheet->mergeCells('A3:I3');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A4', $currentDate);                       $sheet->mergeCells('A4:I4');
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A5', 'PO No.: ' . ($main->po_number ?? $main->pbn_number ?? '')); $sheet->mergeCells('A5:I5');
    $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(10);

$sheet->setCellValue('A6', 'Terms: ' . ($main->terms ?? '')); $sheet->mergeCells('A6:I6');
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(10);


    // ===== NEW PO / PBN DETAILS EXCEL =====
    // Column widths
    $sheet->getColumnDimension('A')->setWidth(25); // Particulars
    $sheet->getColumnDimension('B')->setWidth(18); // Mill
    $sheet->getColumnDimension('C')->setWidth(15); // Qty
    $sheet->getColumnDimension('D')->setWidth(15); // Price
    $sheet->getColumnDimension('E')->setWidth(15); // Commission
    $sheet->getColumnDimension('F')->setWidth(15); // Handling Fee
    $sheet->getColumnDimension('G')->setWidth(15); // Handling
    $sheet->getColumnDimension('H')->setWidth(18); // Total Commission
    $sheet->getColumnDimension('I')->setWidth(18); // Total Cost

// Header row at 8
$sheet->setCellValue('A8', 'Particulars');
$sheet->setCellValue('B8', 'Mill');
$sheet->setCellValue('C8', 'Quantity (LKG)');
$sheet->setCellValue('D8', 'Price');
$sheet->setCellValue('E8', 'Commission');
$sheet->setCellValue('F8', 'Handling Fee');
$sheet->setCellValue('G8', 'Handling');
$sheet->setCellValue('H8', 'Total Commission');
$sheet->setCellValue('I8', 'Total Cost');

$sheet->getStyle('A8:I8')->getFont()->setBold(true);
$sheet->getStyle('A8:I8')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->freezePane('A9');

$row = 8;

    // Totals
    $totalQty = 0.0;
    $totalCommission = 0.0;
    $totalCost = 0.0;
$isFirstDataRow = true;

    foreach ($details as $d) {
        $row++;

        $qty   = (float) ($d->quantity ?? 0);
        $price = (float) ($d->price ?? 0);
        $com   = (float) ($d->commission ?? 0);
        $hf    = (float) ($d->handling_fee ?? 0);

        // Match backend rules:
        // handling = price * handling_fee
        // total_commission = qty * commission
        // total_cost = (qty*price) + (qty*commission) + (price*handling_fee)
        $handling = round($price * $hf, 2);
        $lineCommission = round($qty * $com, 2);
        $lineCost = round(($qty * $price) + $lineCommission + $handling, 2);

        $totalQty += $qty;
        $totalCommission += $lineCommission;
        $totalCost += $lineCost;

        $sheet->setCellValue('A' . $row, (string) ($d->particulars ?? ''));
        $sheet->setCellValue('B' . $row, (string) ($d->mill_code ?: ($d->mill ?? '')));
        $sheet->setCellValue('C' . $row, $qty);
        $sheet->setCellValue('D' . $row, $price);
        $sheet->setCellValue('E' . $row, $com);
        $sheet->setCellValue('F' . $row, $hf);
        $sheet->setCellValue('G' . $row, $handling);
        $sheet->setCellValue('H' . $row, $lineCommission);
        $sheet->setCellValue('I' . $row, $lineCost);

        $sheet->getStyle("C{$row}:I{$row}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    // Totals row
    $row++;
    $sheet->setCellValue('A' . $row, 'TOTAL');
    $sheet->mergeCells("A{$row}:B{$row}");
    $sheet->setCellValue('C' . $row, round($totalQty, 2));
    $sheet->setCellValue('H' . $row, round($totalCommission, 2));
    $sheet->setCellValue('I' . $row, round($totalCost, 2));

    $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:I{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C{$row}:I{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Output
    $filename = 'poDetailsExcel.xls';
    $tmpDir = storage_path('app/tmp');
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }
    $path = $tmpDir . '/' . $filename;

    $writer = new Xls($spreadsheet);
    $writer->save($path);

    return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.ms-excel',
        ])
        ->deleteFileAfterSend(true);
}





}
