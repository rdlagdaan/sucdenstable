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
    'terms'        => 'nullable|string',   // ✅ ADD (ex: CAD)
    'note'         => 'nullable|string',
    'posted_flag'  => 'required|boolean',
    'company_id'   => 'required|integer',
]);


    return DB::transaction(function () use ($validated) {
        $companyId = (int) $validated['company_id'];

        // 🔐 Lock the setting row to avoid race conditions
        $setting = DB::table('application_settings')
            ->where('apset_code', 'PONoImpExp')
            ->lockForUpdate()
            ->first();

        if (!$setting) {
            abort(500, 'PONoImpExp setting not found.');
        }

        // Current numeric value
        $current = (int) $setting->value;
        $next    = $current + 1;

        // Zero-pad to match existing format (e.g. 00859)
        $poNumber = str_pad((string) $next, strlen($setting->value), '0', STR_PAD_LEFT);

        // Persist increment
        DB::table('application_settings')
            ->where('apset_code', 'PONoImpExp')
            ->update([
                'value'      => $poNumber,
                'updated_at' => now(),
            ]);

        // Create main PBN entry
        // ✅ Build payload safely (avoid 500 if "terms" column doesn't exist)
        $payload = [
            'po_number'    => $poNumber,
            'pbn_number'   => $poNumber, // backward compatibility
            'pbn_date'     => $validated['pbn_date'],
            'sugar_type'   => $validated['sugar_type'],
            'crop_year'    => $validated['crop_year'],
            'vend_code'    => $validated['vend_code'],
            'vendor_name'  => $validated['vendor_name'],
            'note'         => $validated['note'] ?? null,
            'posted_flag'  => $validated['posted_flag'] ? 1 : 0,
            'company_id'   => $companyId,
            'visible_flag' => 1,
            'user_id'      => auth()->id(),
        ];

        // ✅ Only set terms if the column exists in pbn_entry
        if (\Illuminate\Support\Facades\Schema::hasColumn('pbn_entry', 'terms')) {
            $payload['terms'] = $validated['terms'] ?? null;
        }

        $entry = PbnEntry::create($payload);


        return response()->json([
            'id'        => $entry->id,
            'po_number' => $poNumber,
        ]);
    });
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
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
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

        // Terms
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
                // safe reads (won't error if null)
                $terms = $decode($vendor->terms ?? ($vendor->term ?? ($vendor->currency ?? '')));
            }
        }

        // Company-specific logo + confirmed-by label
        // NOTE: your public folder shows "ameropLogo.jpg" (NOT ameropLog.jpg)
        $logoPath = ($companyId === 2)
            ? public_path('ameropLogo.jpg')
            : public_path('sucdenLogo.jpg');

        // Fallback: if file missing, try png
        if (!is_file($logoPath)) {
            $logoPath = ($companyId === 2)
                ? public_path('ameropLogo.png')
                : public_path('sucdenLogo.png');
        }

        $confirmedCompany = ($companyId === 2) ? 'Amerop Philippines' : 'Sucden Philippines';

        // Escape HTML
        $poNoH          = htmlspecialchars($poNo, ENT_QUOTES, 'UTF-8');
        $vendorNameH    = htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8');
        $vendorAddressH = htmlspecialchars($vendorAddress, ENT_QUOTES, 'UTF-8');
        $poDateH        = htmlspecialchars($poDate, ENT_QUOTES, 'UTF-8');
        $cropYearH      = htmlspecialchars($cropYearDisplay, ENT_QUOTES, 'UTF-8');
        $termsH         = htmlspecialchars($terms, ENT_QUOTES, 'UTF-8');

        // Note: keep line breaks but move text just under Note:
        $notePlain = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
        $notePlain = str_replace(["\r\n","\r"], "\n", $notePlain);
        $noteH = nl2br($notePlain, false);

        // Build rows + totals
        $rowsHtml = '';
        $grandTotal = 0.0;

        foreach ($details as $d) {
            $partRaw = $decode($d->particulars ?? '');
            $part = htmlspecialchars($partRaw, ENT_QUOTES, 'UTF-8');

            $mill = htmlspecialchars($decode($d->mill ?? ($d->mill_code ?? '')), ENT_QUOTES, 'UTF-8');

            $qty   = (float)($d->quantity ?? 0);
            $price = (float)($d->price ?? 0);
            $hf    = (float)($d->handling_fee ?? 0);

            $amount = (float)(isset($d->cost) ? $d->cost : ($qty * $price));

            $isTextOnly = (trim($partRaw) !== '') && ($qty == 0.0) && ($price == 0.0) && ($amount == 0.0);

            if ($isTextOnly) {
                $rowsHtml .= '
                  <tr>
                    <td height="22"><font size="10"> '.$part.' </font></td>
                    <td align="center"><font size="10"> '.$mill.' </font></td>
                    <td align="right"><font size="10">&nbsp;</font></td>
                    <td align="right"><font size="10">&nbsp;</font></td>
                    <td align="right"><font size="10">-</font></td>
                  </tr>
                ';
                continue;
            }

            $grandTotal += $amount;

            $rowsHtml .= '
              <tr>
                <td height="22"><font size="10"> '.$part.' </font></td>
                <td align="center"><font size="10"> '.$mill.' </font></td>
                <td align="right"><font size="10"> '.number_format($qty, 2).' </font></td>
                <td align="right"><font size="10"> '.number_format($price, 2).' </font></td>
                <td align="right"><font size="10"> '.number_format($amount, 2).' </font></td>
              </tr>
            ';

            // Derived "Handling Fee ..." display line
            if ($hf > 0) {
                $hfText = htmlspecialchars('Handling Fee P' . number_format($hf, 2) . ' per bag', ENT_QUOTES, 'UTF-8');
                $rowsHtml .= '
                  <tr>
                    <td height="22"><font size="10"> '.$hfText.' </font></td>
                    <td align="center"><font size="10">&nbsp;</font></td>
                    <td align="right"><font size="10">&nbsp;</font></td>
                    <td align="right"><font size="10">&nbsp;</font></td>
                    <td align="right"><font size="10">-</font></td>
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
                <td height="22">&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
              </tr>
            ';
        }

        $grandTotalF = number_format($grandTotal, 2);

        // PDF class w/ logo
        $pdf = new class('P', PDF_UNIT, 'LETTER', true, 'UTF-8', false) extends \TCPDF {
            public string $logoPath = '';
            public function Header() {
                if ($this->logoPath && is_file($this->logoPath)) {
                    $ext = strtoupper(pathinfo($this->logoPath, PATHINFO_EXTENSION));
                    $this->Image(
                        $this->logoPath,
                        12, 8,       // x,y
                        42, 15,      // width,height (keeps header compact)
                        ($ext === 'PNG' ? 'PNG' : 'JPG')
                    );
                }
            }
            public function Footer() {}
        };
        $pdf->logoPath = $logoPath;

        // ✅ Reduce top margin so logo-title gap is not too big,
        // but prevent Vendor overlap by adding spacer BELOW title (not by huge margins).
        $pdf->SetMargins(12, 30, 12);
        $pdf->SetHeaderMargin(6);
        $pdf->SetFooterMargin(0);
        $pdf->SetAutoPageBreak(false, 12);
        $pdf->AddPage('P', 'LETTER');
        $pdf->SetFont('helvetica', '', 9);

        // Layout:
        // - Add a fixed-height spacer row AFTER the title so PURCHASE ORDER never covers Vendor row
        // - Widen Amount column (16%) already
        // - TOTAL row: remove inner vertical lines around "PHP" by rendering TOTAL as a single colspan=5 cell with border
        $tbl = <<<EOD
<table border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td width="70%" align="left" valign="bottom">
      <font size="24"><b>PURCHASE ORDER</b></font>
    </td>
    <td width="30%" align="right" valign="bottom">
      <font size="16" color="#1f4e79"><b>PO#:</b></font>
      <font size="18" color="#1f4e79"><b>{$poNoH}</b></font>
    </td>
  </tr>
  <tr><td colspan="2" height="10">&nbsp;</td></tr>
</table>

<table border="0" cellpadding="2" cellspacing="0" width="100%">
  <tr>
    <td width="70%" valign="top">
      <table border="0" cellpadding="2" cellspacing="0" width="100%">
        <tr>
          <td width="18%"><font size="11">Vendor:</font></td>
          <td width="82%"><font size="12"><b>{$vendorNameH}</b></font></td>
        </tr>
        <tr>
          <td><font size="11">Address:</font></td>
          <td><font size="10">{$vendorAddressH}</font></td>
        </tr>
        <tr>
          <td><font size="11">Date:</font></td>
          <td><font size="12"><b>{$poDateH}</b></font></td>
        </tr>
      </table>
    </td>

    <td width="30%" valign="top">
      <table border="0" cellpadding="2" cellspacing="0" width="100%">
        <tr>
          <td width="40%"><font size="11">Terms:</font></td>
          <td width="60%"><font size="11"><u>{$termsH}</u></font></td>
        </tr>
        <tr>
          <td><font size="11">Crop Year:</font></td>
          <td width="60%"><font size="12"><u>{$cropYearH}</u></font></td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<br>

<table border="1" cellpadding="2" cellspacing="0" width="100%">

<tr bgcolor="#d9e1f2" align="center" valign="middle">
  <td width="43%" height="26"><font size="12"><b>Particulars</b></font></td>
  <td width="14%" height="26"><font size="12"><b>Millmark</b></font></td>
  <td width="14%" height="26"><font size="12"><b>Qty in Lkg</b></font></td>
  <td width="13%" height="26"><font size="12"><b>Price/Lkg</b></font></td>
  <td width="16%" height="26"><font size="12"><b>Amount</b></font></td>
</tr>

{$rowsHtml}

<!-- ✅ TOTAL row without inner vertical lines around PHP -->
<tr>
  <td colspan="5" height="30" valign="middle">
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
      <tr>
        <td width="70%" valign="middle"><font size="12"><b>Total</b></font></td>
        <td width="13%" align="center" valign="middle"><font size="12"><b>PHP</b></font></td>
        <td width="17%" align="right" valign="middle" style="white-space:nowrap;">
        <nobr><font size="12"><b>{$grandTotalF}</b></font></nobr>
        </td>
      </tr>
    </table>
  </td>
</tr>

<!-- ✅ Note text immediately below Note: -->
<tr>
  <td colspan="5" height="110" valign="top" style="padding-top:2px;">
    <font size="12"><b>Note:</b></font><br>
    <font size="11">{$noteH}</font>
  </td>
</tr>

<!-- ✅ Make Confirmed/Conforme area wide like your Screen 3 (2 big halves) -->
<tr>
  <td colspan="5" height="60" valign="middle">
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
      <tr>
        <td width="50%" valign="middle">
          <font size="11"><b>Confirmed By:</b></font>
          &nbsp;&nbsp;&nbsp;&nbsp;
          <font size="11">{$confirmedCompany}</font>
        </td>
        <td width="50%" valign="middle">
          <font size="11"><b>Conforme by Supplier</b></font>
        </td>
      </tr>
    </table>
  </td>
</tr>

</table>
EOD;

        $pdf->writeHTML($tbl, true, false, false, false, '');

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

    // Header row at 7
    $sheet->setCellValue('A7', 'Particulars');
    $sheet->setCellValue('B7', 'Mill');
    $sheet->setCellValue('C7', 'Quantity (LKG)');
    $sheet->setCellValue('D7', 'Price');
    $sheet->setCellValue('E7', 'Commission');
    $sheet->setCellValue('F7', 'Handling Fee');
    $sheet->setCellValue('G7', 'Handling');
    $sheet->setCellValue('H7', 'Total Commission');
    $sheet->setCellValue('I7', 'Total Cost');

    $sheet->getStyle('A7:I7')->getFont()->setBold(true);
    $sheet->getStyle('A7:I7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->freezePane('A8');

    $row = 7;

    // Totals
    $totalQty = 0.0;
    $totalCommission = 0.0;
    $totalCost = 0.0;

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
