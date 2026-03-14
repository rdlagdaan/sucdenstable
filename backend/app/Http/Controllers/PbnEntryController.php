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
        'sugar_type'   => 'required',
        'crop_year'    => 'required',
        'pbn_date'     => 'required|date',
        'vend_code'    => 'required',
        'vendor_name'  => 'required',
        'terms'        => 'nullable|string|max:20',
        'note'         => 'nullable|string',
        'posted_flag'  => 'required|boolean',
        'company_id'   => 'required|integer',
    ]);

    return DB::transaction(function () use ($validated) {
        $companyId = (int) $validated['company_id'];

        $setting = DB::table('application_settings')
            ->where('apset_code', 'PONoImpExp')
            ->lockForUpdate()
            ->first();

        if (!$setting) {
            abort(500, 'PONoImpExp setting not found.');
        }

        $current  = (int) $setting->value;
        $next     = $current + 1;
        $poNumber = str_pad((string) $next, strlen((string) $setting->value), '0', STR_PAD_LEFT);

        DB::table('application_settings')
            ->where('apset_code', 'PONoImpExp')
            ->update([
                'value'      => $poNumber,
                'updated_at' => now(),
            ]);

        $payload = [
            'po_number'    => $poNumber,
            'pbn_number'   => $poNumber,
            'pbn_date'     => $validated['pbn_date'],
            'sugar_type'   => $validated['sugar_type'],
            'crop_year'    => $validated['crop_year'],
            'vend_code'    => $validated['vend_code'],
            'vendor_name'  => $validated['vendor_name'],
            'terms'        => $validated['terms'] ?? null,
            'note'         => $validated['note'] ?? null,
            'posted_flag'  => !empty($validated['posted_flag']) ? 1 : 0,
            'company_id'   => $companyId,
            'visible_flag' => 1,
            'user_id'      => auth()->id(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ];

        $entry = PbnEntry::create($payload);

        return response()->json([
            'id'        => $entry->id,
            'po_number' => $poNumber,
        ]);
    });
}
    
    
    

public function updateMain(Request $request)
{
    $validated = $request->validate([
    'id'          => 'required|integer',
    'sugar_type'  => 'required',
    'crop_year'   => 'required',
    'pbn_date'    => 'required|date',
    'vend_code'   => 'required',
    'vendor_name' => 'required',
    'note'        => 'nullable|string',
    'terms'       => 'nullable|string|max:20',
    'company_id'  => 'required|integer',
]);

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
    'sugar_type'  => $validated['sugar_type'],
    'crop_year'   => $validated['crop_year'],
    'pbn_date'    => $validated['pbn_date'],
    'vend_code'   => $validated['vend_code'],
    'vendor_name' => $validated['vendor_name'],
    'note'        => $validated['note'] ?? null,
    'updated_at'  => now(),
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



public function getPbnDropdownList(Request $request)
{
    $data = $request->validate([
        'company_id'     => ['required','integer'],
        'include_posted' => ['nullable','in:true,false,1,0'],
    ]);

    $companyId    = (int) $data['company_id'];
    $includePosted = $request->query('include_posted') === 'true' || $request->query('include_posted') === '1';
    $postedFlag    = $includePosted ? 1 : 0;

    $entries = DB::table('pbn_entry')
        ->where('company_id', $companyId)
        ->where('posted_flag', $postedFlag)
        ->select(['id','pbn_number','sugar_type','vendor_name','crop_year','pbn_date','posted_flag'])
        ->orderByDesc('pbn_number')
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
        $pdf->SetMargins(3, 5, 3);
        $pdf->SetAutoPageBreak(true, 5);
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

        // DB timeout (Postgres)
        try { \DB::statement("SET LOCAL statement_timeout = '8000ms'"); } catch (\Throwable $e) {}

        // Main (id OR po_number OR pbn_number)
        $main = \DB::table('pbn_entry')
            ->where('company_id', $companyId)
            ->where('visible_flag', 1)
            ->where(function ($q) use ($id) {
                $q->where('id', (int)$id)
                  ->orWhere('po_number', (string)$id)
                  ->orWhere('pbn_number', (string)$id);
            })
            ->first();

        if (!$main) {
            return $errorPdf('PURCHASE ORDER - NOT FOUND', "No record found.\n\nRequested: {$id}\ncompany_id: {$companyId}");
        }

        $details = \DB::table('pbn_entry_details')
            ->where('pbn_entry_id', (int)$main->id)
            ->where('company_id', (string)$companyId)
            ->where(function ($q) {
                $q->whereNull('delete_flag')->orWhere('delete_flag', '!=', 1);
            })
            ->orderBy('row')
            ->get();

        $decode = function ($s) {
            $s = (string)$s;
            $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
            $s = trim(preg_replace('/\s+/', ' ', $s));
            return $s;
        };

        // Header fields
        $poNo       = $decode($main->po_number ?? $main->pbn_number ?? '');
        $vendorName = $decode($main->vendor_name ?? '');
        $poDate     = !empty($main->pbn_date) ? \Carbon\Carbon::parse($main->pbn_date)->format('Y-m-d') : '';
        $note       = $decode($main->note ?? '');

        // Crop Year display: BEGIN-END (fallback to raw crop_year)
        $cropYearDisplay = $decode($main->crop_year ?? '');
        $cropYearKey = $decode($main->crop_year ?? '');
        if ($cropYearKey !== '') {
            $cy = \DB::table('crop_year')
                ->where('company_id', $companyId)
                ->where('crop_year', $cropYearKey)
                ->first();
            if ($cy && !empty($cy->begin_year) && !empty($cy->end_year)) {
                $cropYearDisplay = $decode($cy->begin_year) . '-' . $decode($cy->end_year);
            }
        }

        // Terms: use saved pbn_entry.terms first
$terms = '';
if (\Illuminate\Support\Facades\Schema::hasColumn('pbn_entry', 'terms')) {
    $terms = $decode($main->terms ?? '');
}

if (trim((string)$terms) === '') {
    foreach (['term','payment_terms','payment_term','currency','curr'] as $k) {
        if (isset($main->{$k})) {
            $tmp = $decode($main->{$k});
            if ($tmp !== '') { $terms = $tmp; break; }
        }
    }
}

        // Vendor Address (vendor_list has vendor_address)
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
            if (trim((string)$terms) === '') {
                $terms = $decode($vendor->terms ?? ($vendor->term ?? ($vendor->currency ?? '')));
            }
        }

        // Company-specific logo
        $logoPath = ($companyId === 2)
            ? public_path('ameropLogo.jpg')
            : public_path('sucdenLogo.jpg');

        if (!is_file($logoPath)) {
            $logoPath = ($companyId === 2)
                ? public_path('ameropLogo.png')
                : public_path('sucdenLogo.png');
        }

        $confirmedCompany = ($companyId === 2) ? 'Amerop Philippines' : 'Sucden Philippines';

        // Note: keep line breaks
        $notePlain = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
        $notePlain = str_replace(["\r\n","\r"], "\n", $notePlain);
        $noteH = nl2br($notePlain, false);

        // Build rows + totals (HTML)


        
// TD style helpers for dotted vertical borders (Screen 2 look)
// TD style helpers (Screen 2): solid horizontal, dotted vertical, solid outer border
$tdPartStyle = 'style="border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px solid #000; border-right:1px dotted #000;"';
$tdMidStyle  = 'style="border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;"';
$tdLastStyle = 'style="border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px solid #000;"';
$tdSpanStyle = 'style="border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px solid #000; border-right:1px solid #000;"';
        $rowsHtml = '';
        $grandTotal = 0.0;

        foreach ($details as $d) {
            $partRaw = $decode($d->particulars ?? '');
            $part = htmlspecialchars($partRaw, ENT_QUOTES, 'UTF-8');
            // Millmark must be mill_id (mill_code). Fallback to mill (name) only if mill_code is blank.
$millIdRaw = $decode($d->mill_code ?? '');
if ($millIdRaw === '') {
    $millIdRaw = $decode($d->mill ?? '');
}
$mill = htmlspecialchars($millIdRaw, ENT_QUOTES, 'UTF-8');

            $qty   = (float)($d->quantity ?? 0);
            $price = (float)($d->price ?? 0);
            $hf    = (float)($d->handling_fee ?? 0);

            $amount = (float)(isset($d->cost) ? $d->cost : ($qty * $price));

            $isTextOnly = (trim($partRaw) !== '') && ($qty == 0.0) && ($price == 0.0) && ($amount == 0.0);

if ($isTextOnly) {
    $rowsHtml .= '
      <tr>
<td height="10" style="padding:0 1px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px solid #000; border-right:1px dotted #000;">
  <table border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td height="10" valign="middle" style="line-height:10px;"><font size="8"> '.$part.' </font></td>
    </tr>
  </table>
</td>
        <td height="10" style="padding:0 1px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
          <table border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
              <td height="10" align="center" valign="middle" style="line-height:10px;"><font size="8"> '.$mill.' </font></td>
            </tr>
          </table>
        </td>
        <td height="10" style="padding:0 1px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
          <table border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
              <td height="10" align="right" valign="middle" style="line-height:10px;"><font size="8">&nbsp;</font></td>
            </tr>
          </table>
        </td>
        <td height="10" style="padding:0 1px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
          <table border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
              <td height="10" align="right" valign="middle" style="line-height:10px;"><font size="8">&nbsp;</font></td>
            </tr>
          </table>
        </td>
        <td height="10" style="padding:0 1px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px solid #000;">
          <table border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
              <td height="10" align="right" valign="middle" style="line-height:10px;"><font size="8">-</font></td>
            </tr>
          </table>
        </td>
      </tr>
    ';
    continue;
}

            $grandTotal += $amount;

$rowsHtml .= '
  <tr>
    <td height="22" style="padding:0 2px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px solid #000; border-right:1px dotted #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="22" valign="middle" style="line-height:22px;"><font size="10"> '.$part.' </font></td>
        </tr>
      </table>
    </td>
    <td height="22" style="padding:0 2px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="22" align="center" valign="middle" style="line-height:22px;"><font size="10"> '.$mill.' </font></td>
        </tr>
      </table>
    </td>
    <td height="22" style="padding:0 2px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="22" align="right" valign="middle" style="line-height:22px;"><font size="10"> '.number_format($qty, 2).' </font></td>
        </tr>
      </table>
    </td>
    <td height="22" style="padding:0 2px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="22" align="right" valign="middle" style="line-height:22px;"><font size="10"> '.number_format($price, 2).' </font></td>
        </tr>
      </table>
    </td>
    <td height="22" style="padding:0 2px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px solid #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="22" align="right" valign="middle" style="line-height:22px;"><font size="10"> '.number_format($amount, 2).' </font></td>
        </tr>
      </table>
    </td>
  </tr>
';

if ($hf > 0) {
                $hfText = htmlspecialchars('Handling Fee P' . number_format($hf, 2) . ' per bag', ENT_QUOTES, 'UTF-8');
$rowsHtml .= '
  <tr>
<td height="10" style="padding:0 1px; border-bottom:1px solid #000; border-left:1px solid #000; border-right:1px dotted #000;" valign="middle">
  <table border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td height="10" valign="middle" style="line-height:10px;"><font size="8"> '.$hfText.' </font></td>
    </tr>
  </table>
</td>
    <td height="10" style="padding:0 1px; border-top:0px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="10" align="center" valign="middle" style="line-height:10px;"><font size="8">&nbsp;</font></td>
        </tr>
      </table>
    </td>
    <td height="10" style="padding:0 1px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="10" align="right" valign="middle" style="line-height:10px;"><font size="8">&nbsp;</font></td>
        </tr>
      </table>
    </td>
    <td height="10" style="padding:0 1px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="10" align="right" valign="middle" style="line-height:10px;"><font size="8">&nbsp;</font></td>
        </tr>
      </table>
    </td>
    <td height="10" style="padding:0 1px; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px solid #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td height="10" align="right" valign="middle" style="line-height:10px;"><font size="8">-</font></td>
        </tr>
      </table>
    </td>
  </tr>
';
            }
        }

        // Pad rows
        $maxRows = 14;
        $rowCount = count($details);
for ($i = $rowCount; $i < $maxRows; $i++) {
$rowsHtml .= '
  <tr>
    <td '.$tdPartStyle.' height="10">&nbsp;</td>
    <td '.$tdMidStyle.' height="10">&nbsp;</td>
    <td '.$tdMidStyle.' height="10">&nbsp;</td>
    <td '.$tdMidStyle.' height="10">&nbsp;</td>
    <td '.$tdLastStyle.' height="10">&nbsp;</td>
  </tr>
';
        }

        $grandTotalF = number_format($grandTotal, 2);

// ===== Table border styles to match Screen 2 =====

// For rows that span all columns (note/footer): keep solid outer border
$tdSpanStyle  = 'style="border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px solid #000; border-right:1px solid #000;"';

        // ============================
        // PDF setup
        // ============================
        $pdf = new \TCPDF('P', PDF_UNIT, 'LETTER', true, 'UTF-8', false);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);

// Margins (TARGET): narrower left/right so border/table is closer to page edge
// Margins (TARGET): match Screen 2 top/bottom tighter
$mL = 8;   // keep your working left
$mT = 5;   // TOP tighter (was 12)
$mR = 8;   // keep your working right
$mB = 5;   // BOTTOM tighter (was 12)

$pdf->SetMargins($mL, $mT, $mR);
$pdf->SetAutoPageBreak(false, $mB);
        $pdf->AddPage('P', 'LETTER');

        $pageW = $pdf->getPageWidth();
        $contentW = $pageW - $mL - $mR;

        // ===== Screen-1 target styling =====
        // Blue in Screen 1 is closer to “Office/Excel blue”
        $ruleBlue = [0, 112, 192];            // #0070C0
        $labelBlueGray = [31, 78, 121];       // #1F4E79 (PO label tone)
        $black = [0, 0, 0];

        // Stroke weights (Screen 1 is thinner than your current)
        $ruleW = 0.55;   // thicker like Screen 4 (tweak 0.75–1.00 if needed)
        $ulineW = 0.18;       // underline thickness (mm)

        // ============================
        // HEADER (TCPDF primitives)
        // ============================
        $x0 = $mL;
        $y0 = $mT;

        // Logo + company block
        $logoW = 50;          // slightly smaller/cleaner like Screen 1
        $gap   = 5;

        if ($logoPath && is_file($logoPath)) {
            $pdf->Image($logoPath, $x0, $y0 + 0.2, $logoW, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        $companyLine1 = ($companyId === 2) ? 'Amerop Philippines, Inc.' : 'Sucden Philippines, Inc.';
        $companyLine2 = 'Unit 2202, The PODIUM West Tower, 12 ADB Ave., Wack-Wack, Ortigas';
        $companyLine3 = 'Center, Mandaluyong City, Philippines 1550';

// Screen 1 behavior: wider whitespace after logo, but company header text remains LEFT-aligned.
// So: X is pushed right by a fixed gap; alignment is 'L'.

$headerBlockW = 125;          // width available for company lines
$gapAfterLogo = 28;           // <-- increase/decrease until it matches Screen 1 gap

$logoEndX = $x0 + $logoW;
$xText = $logoEndX + $gapAfterLogo;

$yText = $y0 + 0.5;

$pdf->SetTextColor(0, 0, 0);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetXY($xText, $yText);
$pdf->Cell($headerBlockW, 0, $companyLine1, 0, 1, 'L', false, '', 0, false, 'T', 'T');

$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY($xText, $yText + 3.8);
$pdf->Cell($headerBlockW, 0, $companyLine2, 0, 1, 'L', false, '', 0, false, 'T', 'T');

$pdf->SetXY($xText, $yText + 7.2);
$pdf->Cell($headerBlockW, 0, $companyLine3, 0, 1, 'L', false, '', 0, false, 'T', 'T');
        // Blue rule: thinner + brighter + correct y
        $ruleY = $y0 + 18.6; // moved down (was 15.6)
        $pdf->SetDrawColor($ruleBlue[0], $ruleBlue[1], $ruleBlue[2]);
        $pdf->SetLineWidth($ruleW);
        $pdf->Line($x0, $ruleY, $x0 + $contentW, $ruleY);

        // Title closer to the rule (Screen 1)
        $titleY = $ruleY + 3.8;
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetXY($x0, $titleY);
        $pdf->Cell(120, 0, 'PURCHASE ORDER', 0, 0, 'L');

// PO# block (match Screen 2: value LEFT-aligned, underline lower with extra spacing)
$poBlockW = 66;
$poFieldW = 44;
$poLabelW = $poBlockW - $poFieldW;

$poBlockX = $x0 + $contentW - $poBlockW;

// Place PO# block on the same baseline as the title area (like Screen 2)
$poBaseY  = $titleY + 1.2;

// Typography (keep as you already set)
$poLabelSize = 11;
$poValueSize = 18;

// Label (PO#:)
$pdf->SetFont('helvetica', 'B', $poLabelSize);
$pdf->SetTextColor($labelBlueGray[0], $labelBlueGray[1], $labelBlueGray[2]);
$pdf->SetXY($poBlockX, $poBaseY);
$pdf->Cell($poLabelW, 6, 'PO#:', 0, 0, 'R', false);

// Underline: LOWER than Screen 1 (creates the extra "line space" like Screen 2)
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth($ulineW);
$poLineY = $poBaseY + 6.2;   // <-- move line DOWN (was 5.0)
$pdf->Line(
    $poBlockX + $poLabelW,
    $poLineY,
    $poBlockX + $poLabelW + $poFieldW,
    $poLineY
);

// PO number: LEFT-aligned inside the underline field (like Screen 2)
$pdf->SetFont('helvetica', 'B', $poValueSize);
$pdf->SetTextColor($labelBlueGray[0], $labelBlueGray[1], $labelBlueGray[2]);

// Put text a little above the underline, and start near the LEFT edge of the field
$pdf->SetXY($poBlockX + $poLabelW + 1.0, $poBaseY - 2.6); // move PO value up (was +0.1)
$pdf->Cell($poFieldW - 1.0, 6, $poNo, 0, 0, 'L', false);

// Reset
$pdf->SetTextColor(0, 0, 0);

// Vendor / Address / Date + Terms / Crop Year (TARGET = your Screen 1)

// Position
$blockY = $titleY + 12.0;

// Fonts
$labelFont = 9;
$valueFont = 12;

// Heights (tight but readable)
$rowH  = 5.0;
$addrH = 8.2;

// Underline spacing
$textPadY  = 0.10;
$ulineDrop = 1.35;

// Column geometry (shorten left underlines so they stop well before right column)
$rightColW    = 66;
$leftStopPad  = 20;  // bigger = shorter left underlines (more white space before right column)

$rightColX    = $x0 + $contentW - $rightColW;
$leftLineEndX = $rightColX - $leftStopPad;

$xL = $x0;
$xR = $rightColX;
$rightW = $rightColW;

// label widths (fixed like your original look)
$labelWLeft  = 18;
$labelWRight = 18;

// ---------------- helpers ----------------

// LEFT: underline includes label+value, ends at fixed endpoint
$drawLeft = function($y, $label, $value) use (
    $pdf, $xL, $labelFont, $valueFont, $labelWLeft,
    $rowH, $textPadY, $ulineW, $ulineDrop, $leftLineEndX
) {
    $pdf->SetTextColor(0,0,0);

    $pdf->SetFont('helvetica', '', $labelFont);
    $pdf->SetXY($xL, $y + $textPadY);
    $pdf->Cell($labelWLeft, $rowH, $label, 0, 0, 'L');

    $pdf->SetFont('helvetica', 'B', $valueFont);
    $pdf->SetXY($xL + $labelWLeft, $y + $textPadY);
    $pdf->Cell(($leftLineEndX - $xL) - $labelWLeft, $rowH, $value, 0, 0, 'L');

    $pdf->SetDrawColor(0,0,0);
    $pdf->SetLineWidth($ulineW);
    $yLine = $y + $rowH + $ulineDrop;
    $pdf->Line($xL, $yLine, $leftLineEndX, $yLine);

    return $yLine; // underline Y
};

// RIGHT (FULL underline): underline includes label+value (for Crop Year)
$drawRightFullUnderline = function($y, $label, $value) use (
    $pdf, $xR, $rightW, $labelFont, $valueFont, $labelWRight,
    $rowH, $textPadY, $ulineW, $ulineDrop
) {
    $pdf->SetTextColor(0,0,0);

    $pdf->SetFont('helvetica', '', $labelFont);
    $pdf->SetXY($xR, $y + $textPadY);
    $pdf->Cell($labelWRight, $rowH, $label, 0, 0, 'L');

    $pdf->SetFont('helvetica', 'B', $valueFont);
    $pdf->SetXY($xR + $labelWRight, $y + $textPadY);
    $pdf->Cell($rightW - $labelWRight, $rowH, $value, 0, 0, 'L');

    // FULL underline across the entire right field (label + value)
    $pdf->SetDrawColor(0,0,0);
    $pdf->SetLineWidth($ulineW);
    $yLine = $y + $rowH + $ulineDrop;
    $pdf->Line($xR, $yLine, $xR + $rightW, $yLine);

    return $yLine;
};

// RIGHT (VALUE ONLY): underline starts after label (for Terms)
$drawRightValueOnlyUnderline = function($y, $label, $value) use (
    $pdf, $xR, $rightW, $labelFont, $valueFont, $labelWRight,
    $rowH, $textPadY, $ulineW, $ulineDrop
) {
    $pdf->SetTextColor(0,0,0);

    $pdf->SetFont('helvetica', '', $labelFont);
    $pdf->SetXY($xR, $y + $textPadY);
    $pdf->Cell($labelWRight, $rowH, $label, 0, 0, 'L');

    $pdf->SetFont('helvetica', 'B', $valueFont);
    $pdf->SetXY($xR + $labelWRight, $y + $textPadY);
    $pdf->Cell($rightW - $labelWRight, $rowH, $value, 0, 0, 'L');

    // underline ONLY under the value area (NOT under label)
    $pdf->SetDrawColor(0,0,0);
    $pdf->SetLineWidth($ulineW);
    $yLine = $y + $rowH + $ulineDrop;
    $pdf->Line($xR + $labelWRight, $yLine, $xR + $rightW, $yLine);

    return $yLine;
};

// ---------------- layout rows ----------------

// Row 1: Vendor + Terms
$y1 = $blockY;
$drawLeft($y1, 'Vendor:', $vendorName);
// Terms: label NOT underlined, value only
$drawRightValueOnlyUnderline($y1, 'Terms:', $terms);

// Row 2: Address + Crop Year (Crop Year aligned with Address row)
$y2 = $y1 + ($rowH + 4.0); // move Address/CropYear row down (was 2.2)
// Crop Year vertical tweak (must be defined BEFORE any use)
$cropTextDown = 3.0;
// Address label (use rowH, not addrH, so it aligns with the first line of MultiCell)
$pdf->SetTextColor(0,0,0);
$pdf->SetFont('helvetica', '', $labelFont);
$pdf->SetXY($xL, $y2 + $textPadY + $cropTextDown);
$pdf->Cell($labelWLeft, $rowH, 'Address:', 0, 0, 'L');

// Address value (starts at the exact same Y as label)
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY($xL + $labelWLeft, $y2 + $textPadY + $cropTextDown);
$pdf->MultiCell(
    ($leftLineEndX - $xL) - $labelWLeft,
    $addrH,
    $vendorAddress,
    0,
    'L',
    false,
    0
);

// Address underline (keep based on addrH so it sits below the full address area)
$pdf->SetDrawColor(0,0,0);
$pdf->SetLineWidth($ulineW);
$yLineAddr = $y2 + $addrH + $ulineDrop;
$pdf->Line($xL, $yLineAddr, $leftLineEndX, $yLineAddr);

// Crop Year: draw TEXT ONLY once, then ONE underline aligned with Address underline

// IMPORTANT: make sure Crop Year is NOT drawn anywhere else (no $drawRightFullUnderline($y2, ...) call)

// Move Crop Year text slightly down so it sits just above the underline
$cropTextDown = 3.0;

$pdf->SetTextColor(0,0,0);

// label
$pdf->SetFont('helvetica', '', $labelFont);
$pdf->SetXY($xR, $y2 + $textPadY + $cropTextDown);
$pdf->Cell($labelWRight, $rowH, 'Crop Year:', 0, 0, 'L');

// value
$pdf->SetFont('helvetica', 'B', $valueFont);
$pdf->SetXY($xR + $labelWRight, $y2 + $textPadY + $cropTextDown);
$pdf->Cell($rightW - $labelWRight, $rowH, $cropYearDisplay, 0, 0, 'L');

// single underline aligned to Address underline
$pdf->SetDrawColor(0,0,0);
$pdf->SetLineWidth($ulineW);
$pdf->Line($xR, $yLineAddr, $xR + $rightW, $yLineAddr);
// Row 3: Date (left only)
$y3 = $y2 + $addrH + 2.0;
$yLineDate = $drawLeft($y3, 'Date:', $poDate);

// keep leftW for downstream code
$leftW = $leftLineEndX - $x0;

// Push table BELOW Date underline so it never overlaps
// Push the table far enough below Date underline so it never overlaps
// Stronger safety gap so the table header border never overlaps Date row
// Start table safely below Date underline (prevents overlap)
$afterHeaderY = $yLineDate + 4.0;
$pdf->SetY($afterHeaderY);
$pdf->SetY($afterHeaderY);$pdf->SetY($afterHeaderY);
// Body table must start BELOW the Date underline (prevents overlap 100%)
$afterHeaderY = $yLineDate + 3.0;
$pdf->SetY($afterHeaderY);

        // ============================
        // BODY TABLE + NOTE + SIGNATURE (HTML)
        // ============================
// Column widths (TARGET like Screen 2)
$wPart  = 38;
$wMill  = 13;
$wQty   = 13;
$wPrice = 12;
$wAmt   = 24;

// Total row alignment: "Total" spans first 3 columns; "PHP" under Price; amount under Amount
$wTotalLabel = $wPart + $wMill + $wQty; // 64

// FIX: remove ONLY the top border of the FIRST NON-EMPTY BODY row cells
// so the line directly under the header won't get double-drawn (thick).
$rowsHtmlFixed = $rowsHtml;

/*$rowsHtmlFixed = preg_replace_callback(
    // first <tr> that contains a <td> with real content (not just &nbsp; / spaces)
    '/<tr\b[^>]*>(?:(?!<\/tr>).)*?<td\b[^>]*>\s*(?!&nbsp;)(?:(?!<\/td>).)+<\/td>(?:(?!<\/tr>).)*?<\/tr>/si',
    function ($m) {
        $tr = $m[0];

        // Only within THIS first non-empty row: force border-top:0 on every <td>
        $tr = preg_replace_callback('/<td\b([^>]*)>/i', function ($tdm) {
            $attrs = $tdm[1];

            if (preg_match('/\sstyle\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                $style = $sm[1];

                // remove any border-top declarations, then force border-top:0
                $style = preg_replace('/\bborder-top\s*:\s*[^;"]+;?/i', '', $style);
                $style = trim($style);
                if ($style !== '' && substr($style, -1) !== ';') $style .= ';';
                $style .= ' border-top:0;';

                $attrs = preg_replace('/\sstyle\s*=\s*"[^"]*"/i', ' style="' . $style . '"', $attrs, 1);
                return '<td' . $attrs . '>';
            }

            return '<td' . $attrs . ' style="border-top:0;">';
        }, $tr);

        return $tr;
    },
    $rowsHtml,
    1
);*/

// Decode HTML entities first so "Storage &amp; Insurance" becomes "Storage & Insurance"
$noteRaw = html_entity_decode((string)$noteH, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$noteRaw = trim($noteRaw);
$noteRaw = preg_replace('/\s+/u', ' ', $noteRaw);

$labels = [
  'storage'  => 'Storage\s*(?:&|&amp;|&amp;amp;)\s*Insurance\s*:',
  'fullpay'  => 'Full\s*payment\b[^:]*:?',
  'withdraw' => 'Withdrawal\s*:',
];

// split into chunks starting at each label (keeps labels)
$splitRe = '/(?=(' . $labels['storage'] . '|' . $labels['fullpay'] . '|' . $labels['withdraw'] . '))/i';
$chunks  = preg_split($splitRe, $noteRaw, -1, PREG_SPLIT_NO_EMPTY);

// keep first occurrence per section
$found = ['storage'=>'', 'fullpay'=>'', 'withdraw'=>''];
foreach ($chunks as $c) {
    $c = trim($c);
    if ($c === '') continue;

    if ($found['storage'] === ''  && preg_match('/^' . $labels['storage'] . '/i', $c))  { $found['storage']  = $c; continue; }
    if ($found['fullpay'] === ''  && preg_match('/^' . $labels['fullpay'] . '/i', $c))  { $found['fullpay']  = $c; continue; }
    if ($found['withdraw'] === '' && preg_match('/^' . $labels['withdraw'] . '/i', $c)) { $found['withdraw'] = $c; continue; }
}

// Screen-2 order
$lines = [];
if ($found['storage']  !== '') $lines[] = $found['storage'];
if ($found['fullpay']  !== '') $lines[] = $found['fullpay'];
if ($found['withdraw'] !== '') $lines[] = $found['withdraw'];

// Fallback: if nothing matched, just show the raw note as one line
if (!$lines) $lines = [$noteRaw];

// Render each line as its own TABLE ROW (TCPDF always respects this)
$noteLinesHtml = '';
foreach ($lines as $ln) {
    // escape only basic; if you already have HTML entities, you can remove htmlspecialchars
$lnSafe = trim(strip_tags($ln)); // keep it plain text; avoid double-encoding
$noteLinesHtml .= '<tr><td style="padding:0 0 6px 0; line-height:12px;"><font size="9">' . $lnSafe . '</font></td></tr>';
}

// Force the note text to "drop down" (TCPDF ignores padding-top sometimes)
$noteLinesHtml = '<tr><td height="10" style="font-size:0; line-height:0;">&nbsp;</td></tr>' . $noteLinesHtml;

// IMPORTANT: TCPDF uses CURRENT line width for HTML borders.
// Reset BEFORE any writeHTML to prevent thick “marker” seams.
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.18);

// IMPORTANT: TCPDF uses CURRENT line width for HTML borders.
// Reset BEFORE any writeHTML to prevent thick seams.
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.18);

// -------------------------------
// ONE TABLE ONLY: main + note + confirmed (no split writeHTML)
// NOTE has border-bottom:1px, CONFIRMED has border-top:0 -> SINGLE thin line only once
// -------------------------------
$bodyTbl = <<<EOD

<table border="0" cellpadding="2" cellspacing="0" width="100%"
       style="border:0px solid #000; border-collapse:separate; border-spacing:0;">

  <!-- HEADER ROW -->
<tr bgcolor="#DCE6F1" align="center" valign="middle">
    <td width="{$wPart}%"  height="12" style="font-weight:bold; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;"><font size="8"><b>Particulars</b></font></td>
    <td width="{$wMill}%"  height="12" style="font-weight:bold; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;"><font size="8"><b>Millmark</b></font></td>
    <td width="{$wQty}%"   height="12" style="font-weight:bold; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;"><font size="8"><b>Qty in Lkg</b></font></td>
    <td width="{$wPrice}%" height="12" style="font-weight:bold; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;"><font size="8"><b>Price/Lkg</b></font></td>
    <td width="{$wAmt}%"   height="12" style="font-weight:bold; border-top:1px solid #000; border-bottom:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;"><font size="8"><b>Amount</b></font></td>
  </tr>

  <!-- BODY ROWS -->
  {$rowsHtmlFixed}

<!-- TOTAL ROW -->
<tr bgcolor="#DCE6F1" valign="middle">
  <td width="{$wPart}%" height="14" style="font-weight:bold; border-top:1px solid #000; border-left:1px solid #000;">
    <font size="10"><b>Total</b></font>
  </td>

  <td width="{$wMill}%" height="14" style="border-top:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
    &nbsp;
  </td>

  <td width="{$wQty}%" height="14" style="border-top:1px solid #000; border-left:1px dotted #000; border-right:1px dotted #000;">
    &nbsp;
  </td>

  <td width="{$wPrice}%" height="14" align="center" style="font-weight:bold; border-top:1px solid #000; border-left:1px dotted #000;">
    <font size="10"><b>PHP</b></font>
  </td>

  <td width="{$wAmt}%" height="14" align="right" style="font-weight:bold; border-top:1px solid #000; border-right:1px solid #000;">
    <font size="10"><b>{$grandTotalF}</b></font>
  </td>
</tr>

  <!-- NOTE ROW (thin divider via border-bottom:0.0mm; attached to CONFIRMED) -->
  <tr valign="top">
    <td colspan="5" height="90"
        style="padding:0;
               border-top:0;
               border-bottom:0.0mm solid #000;
               border-left:1px solid #000;
               border-right:1px solid #000;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr valign="top">
          <td width="10%" style="padding:6px 4px 2px 4px;">
            <font size="10"><b>Note:</b></font>
          </td>
          <td width="90%" style="padding:6px 20px 2px 26px;">
            <table border="0" cellpadding="0" cellspacing="0" width="96%">
              {$noteLinesHtml}
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- CONFIRMED ROW (NO TOP BORDER = prevents double stroke, attached to NOTE) -->
  <tr valign="top">
    <td colspan="5" height="52"
        style="padding:0;">
      <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr valign="top">

          <!-- LEFT -->
          <td width="49.9%" height="52" style="padding:0;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr><td height="12" style="font-size:0; line-height:0;">&nbsp;</td></tr>
              <tr valign="middle">
                <td width="30%" style="padding-left:6px;"><font size="9"><b>Confirmed By:</b></font></td>
                <td width="70%" style="padding-left:12px;"><font size="9"><br>{$confirmedCompany}</font></td>
              </tr>
            </table>
          </td>

          <!-- CENTER DIVIDER -->
          <td width="0.2%" height="52" bgcolor="#666666" style="padding:0; font-size:0; line-height:0;">&nbsp;</td>

          <!-- RIGHT -->
          <td width="49.9%" height="52" style="padding:0;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr><td height="12" style="font-size:0; line-height:0;">&nbsp;</td></tr>
              <tr valign="middle">
                <td style="padding-left:10px;"><font size="9"><b>Conforme by Supplier</b></font></td>
              </tr>
            </table>
          </td>

        </tr>
      </table>
    </td>
  </tr>

</table>

EOD;

// single writeHTML only (prevents TCPDF block seam)
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.18);
$pdf->SetFont('helvetica', '', 9);

$pdf->writeHTML($bodyTbl, true, false, true, false, '');
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
