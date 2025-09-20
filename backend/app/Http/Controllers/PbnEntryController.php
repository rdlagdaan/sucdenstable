<?php 
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PbnEntry;
use App\Models\PbnEntryDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str; // at the top of the file if not present

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




public function formPdf(\Illuminate\Http\Request $request, $id = null)
{
    // Accept id either as route param or as ?id= in the query string
    $id = $id ?? $request->query('id');

    if (!$id) {
        return response()->json([
            'message' => 'Missing PBN id. Call /api/pbn/form-pdf/{id} or /api/pbn/form-pdf?id={id}',
        ], 422);
    }
    // 1) Load the main record + details (same company scoping as show())
    $companyId = $request->query('company_id');

    $main = \App\Models\PbnEntry::where('id', (int) $id)
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->firstOrFail();

    $details = \App\Models\PbnEntryDetail::where('pbn_entry_id', $main->id)
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->orderBy('row')
        ->get();

    // 2) Ensure TCPDF is available (safe under Octane workers)
    if (!class_exists('\TCPDF', false)) {
        $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
        if (file_exists($tcpdfPath)) {
            require_once $tcpdfPath;
        } else {
            abort(500, 'TCPDF not installed. Run: composer require tecnickcom/tcpdf');
        }
    }

    // 3) Map fields exactly like the legacy template expects
    $pbnNo      = $main->pbn_number ?? '';
    $vendorID   = $main->vend_code ?? '';
    $vendorName = $main->vendor_name ?? '';
    $pbnDate    = $main->pbn_date ? \Carbon\Carbon::parse($main->pbn_date)->format('m/d/Y') : '';
    $cropYear   = $main->crop_year ?? '';

    $grandTotalQuantity = 0.0;
    $grandTotalGross    = 0.0;

    $rowsMill  = '';
    $rowsQty   = '';
    $rowsUC    = '';
    $rowsCom   = '';
    $rowsGross = '';

    foreach ($details as $d) {
        $qty = (float) ($d->quantity ?? 0);
        $uc  = (float) ($d->unit_cost ?? 0);
        $com = (float) ($d->commission ?? 0);
        $gross = ($uc + $com) * $qty;

        $grandTotalQuantity += $qty;
        $grandTotalGross    += $gross;

        // Match legacy display: use mill_code if present, otherwise mill; uppercase
        $millMark = strtoupper($d->mill_code ?: ($d->mill ?? ''));
        $rowsMill  .= '<font size="10"> '.$millMark.' </font><br>';
        $rowsQty   .= '<font size="10">'.number_format($qty, 2).'</font><br>';
        $rowsUC    .= '<font size="10">'.number_format($uc, 2).'</font><br>';
        $rowsCom   .= '<font size="10">'.number_format($com, 2).'</font><br>';
        $rowsGross .= '<font size="10">'.number_format($gross, 2).'</font><br>';
    }

    $grandQty   = number_format($grandTotalQuantity, 2);
    $grandGross = number_format($grandTotalGross, 2);

    // 4) Create a TCPDF instance that draws the exact header and NO footer
    $logoPath = public_path('sucdenLogo.jpg'); // put your logo file here (backend/public)

    $pdf = new class('P', PDF_UNIT, 'LETTER', true, 'UTF-8', false) extends \TCPDF {
        public string $logoPath = '';
        protected $last_page_flag = false;
        public function Close() { $this->last_page_flag = true; parent::Close(); }
        public function Header() {
            if ($this->logoPath && is_file($this->logoPath)) {
                // x=15, y=10, width=50 — matches your legacy form
                $this->Image($this->logoPath, 15, 10, 50, '', 'JPG', '', 'T', false, 200, '', false, false, 0, false, false, false);
            }
        }
        public function Footer() { /* no TCPDF watermark/page number */ }
    };
    $pdf->logoPath = $logoPath;

    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(false);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->AddPage('P', 'LETTER');
    $pdf->SetFont('helvetica', '', 7);

    // Match the legacy fixed content box height
    $detailsHeight = 450;

    $html = <<<EOD
<br><br>

<table border="0" cellpadding="1" cellspacing="2" nobr="true" width="100%">
  <tr>
    <td colspan="5" align="right">
      <div><font size="14"><b>PURCHASE BOOK NOTE </b></font></div>
      <div><font size="14">PBN No. </font> <font size="18" color="blue"><b><u>{$pbnNo}</u></b></font></div>
    </td>
  </tr>
  <tr><td colspan="5"></td></tr>
</table>
<br>

<table border="1" cellpadding="1" cellspacing="1" nobr="true" width="100%">
  <tr>
    <td colspan="5" bgcolor="lightgrey">
      <table>
        <tr>
          <td width="15%" height="25px"><font size="10">Trader: </font></td>
          <td width="34%" height="25px"><font size="10"><u>{$vendorID}</u></font></td>
          <td width="4%"  height="25px"></td>
          <td width="15%" height="25px"></td>
          <td width="32%" height="25px"></td>
        </tr>
        <tr>
          <td width="15%" height="25px"><font size="10">Supplier: </font></td>
          <td width="45%" height="25px"><font size="10"><u><b>{$vendorName}</b></u></font></td>
          <td width="10%" height="25px"></td>
          <td width="15%" height="25px"><font size="10">PBN Date: </font></td>
          <td width="15%" height="25px"><font size="10"><u>{$pbnDate}</u></font></td>
        </tr>
        <tr>
          <td height="25px"><font size="10">Crop Year: </font></td>
          <td height="25px"><font size="10"><u>{$cropYear}</u></font></td>
          <td height="25px"></td>
          <td height="25px"></td>
          <td height="25px"></td>
        </tr>
      </table>
    </td>
  </tr>

  <tr align="center" valign="middle">
    <td width="33%" height="25"><font size="10">MillMark </font></td>
    <td width="18%" height="25"><font size="10">Qty in LKG </font></td>
    <td width="15%" height="25"><font size="10">Price/LKG </font></td>
    <td width="15%" height="25"><font size="10">Commission </font></td>
    <td width="19%" height="25"><font size="10">Gross Amount </font></td>
  </tr>

  <tr>
    <td height="{$detailsHeight}">
      {$rowsMill}
    </td>
    <td align="right">
      {$rowsQty}
    </td>
    <td align="right">
      {$rowsUC}
    </td>
    <td align="right">
      {$rowsCom}
    </td>
    <td align="right">
      {$rowsGross}
    </td>
  </tr>

  <tr>
    <td colspan="4" height="25">
      <table>
        <tr>
          <td width="47%"><font size="10">TOTAL</font></td>
          <td><font size="10">{$grandQty}</font></td>
          <td></td>
          <td></td>
        </tr>
      </table>
    </td>
    <td align="right" height="25">
      <font size="10">{$grandGross}</font>
    </td>
  </tr>

  <tr>
    <td colspan="5">
      <table width="100%">
        <tr>
          <td width="18%"></td>
          <td width="30%" align="center" valign="middle">
            <br><br><br><br>
            <font size="10">_____________________</font><br>
            <font size="10">Posted By:</font>
          </td>
          <td width="6%" height="100"></td>
          <td width="30%" align="center" valign="middle">
            <br><br><br><br>
            <font size="10">_____________________</font><br>
            <font size="10">Prepared By:</font>
          </td>
          <td width="18%"></td>
        </tr>
      </table>
    </td>
  </tr>
</table>
EOD;

    // 5) Render & return inline (so your modal iframe can display it)
    $pdf->writeHTML($html, true, false, false, false, '');
    $content = $pdf->Output('pbnForm.pdf', 'S');

    return response($content, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="pbnForm.pdf"')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
}



public function formExcel(\Illuminate\Http\Request $request, $id = null)
{
    // Accept id either as route param or as ?id=
    $id = $id ?? $request->query('id');
    if (!$id) {
        return response()->json([
            'message' => 'Missing PBN id. Call /api/pbn/form-excel/{id} or /api/pbn/form-excel?id={id}',
        ], 422);
    }

    // Load main + details (same scoping as PDF)
    $companyId = $request->query('company_id');

    $main = \App\Models\PbnEntry::where('id', (int) $id)
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->firstOrFail();

    $details = \App\Models\PbnEntryDetail::where('pbn_entry_id', $main->id)
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->orderBy('row')
        ->get();

    // Header data
    $cropYear    = $main->crop_year ?? '';
    $currentDate = now()->format('M d, Y h:i');
    $receiptNo   = '';                 // 🔧 if you have RR No., set it here

    // Build workbook (PhpSpreadsheet)
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
    $sheet->setTitle('Quedan Listing');

    // A1..A5 header block
    $sheet->setCellValue('A1', 'Shipper: SUCDEN PHILIPPINES, INC');
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A2', 'Buyer: SUCDEN AMERICAS CORP.');
    $sheet->mergeCells('A2:H2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A3', 'Quedan Listings (CY' . $cropYear . ')');
    $sheet->mergeCells('A3:H3');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A4', $currentDate);
    $sheet->mergeCells('A4:H4');
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A5', 'RR No.:' . $receiptNo);
    $sheet->mergeCells('A5:H5');
    $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(10);

    // Column widths
    $sheet->getColumnDimension('A')->setWidth(15);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(15);
    $sheet->getColumnDimension('F')->setWidth(15);
    $sheet->getColumnDimension('G')->setWidth(15);
    $sheet->getColumnDimension('H')->setWidth(20);

    // Header row labels (A7:H7)
    $sheet->setCellValue('A7', 'MillMark');
    $sheet->setCellValue('B7', 'Quedan No.');
    $sheet->setCellValue('C7', 'Quantity');
    $sheet->setCellValue('D7', 'Liens');
    $sheet->setCellValue('E7', 'Week Ending');
    $sheet->setCellValue('F7', 'Date Issued');
    $sheet->setCellValue('G7', 'TIN');
    $sheet->setCellValue('H7', 'Planter');

    // Header row styles
    $sheet->getStyle('A7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('B7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('C7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('D7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('E7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('F7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('G7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('H7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A7:H7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->freezePane('A8');

    // Counters & totals
    $row = 7;                // header row
    $pageCount = 0;
    $pcs = 0;                // page pcs
    $totalPcs = 0;
    $pageQty = 0;
    $pageLiens = 0;
    $grandQty = 0;
    $grandLiens = 0;

    if ($details->isNotEmpty()) {
        // pad “Quedan No.” as 6 digits if you later have numbers there:
        // $sheet->getStyle('B')->getNumberFormat()->setFormatCode('000000');

        foreach ($details as $d) {
            $millMark = strtoupper($d->mill_code ?: ($d->mill ?? ''));
            $qty      = (int) round((float) ($d->quantity ?? 0));
            $liens    = (int) round(((float) ($d->quantity ?? 0)) * ((float) ($d->commission ?? 0)));

            $pageCount++;
            $pcs++;
            $totalPcs++;

            $pageQty   += $qty;
            $pageLiens += $liens;
            $grandQty  += $qty;
            $grandLiens += $liens;

            $row++;
            $sheet->setCellValue('A' . $row, $millMark);
            $sheet->setCellValueExplicit('B' . $row, '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); // Quedan No. (blank)
            $sheet->setCellValue('C' . $row, $qty);
            $sheet->setCellValue('D' . $row, $liens);
            $sheet->setCellValue('E' . $row, '');   // Week Ending
            $sheet->setCellValue('F' . $row, '');   // Date Issued
            $sheet->setCellValue('G' . $row, '');   // TIN
            $sheet->setCellValue('H' . $row, '');   // Planter

            $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            // Every 50 rows → PAGE TOTAL + repeat page header block (exactly like legacy)
            if (($pageCount % 50) === 0) {
                // PAGE TOTAL
                $row++;
                $sheet->setCellValue('A' . $row, 'PAGE TOTAL:');
                $sheet->getStyle('A' . $row)->getFont()->setSize(12);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $sheet->setCellValue('B' . $row, $pcs . ' PCS.');
                $sheet->getStyle('B' . $row)->getFont()->setSize(12);
                $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $sheet->setCellValue('C' . $row, $pageQty);
                $sheet->getStyle('C' . $row)->getFont()->setSize(12);
                $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $sheet->setCellValue('D' . $row, $pageLiens);
                $sheet->getStyle('D' . $row)->getFont()->setSize(12);
                $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // spacer
                $row++;
                $sheet->setCellValue('A' . $row, '');
                $sheet->mergeCells("A{$row}:H{$row}");

                // Repeat header block like legacy
                $row++;
                $sheet->setCellValue('A' . $row, 'Shipper: SUCDEN PHILIPPINES, INC.');
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $row++;
                $sheet->setCellValue('A' . $row, 'Buyer: SUCDEN AMERICAS CORP.');
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $row++;
                $sheet->setCellValue('A' . $row, 'Quedan Listings (CY' . $cropYear . ')');
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $row++;
                $sheet->setCellValue('A' . $row, $currentDate);
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $row++;
                $sheet->setCellValue('A' . $row, 'RR No.:' . $receiptNo);
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                // widths again (legacy did it)
                foreach (range('A','H') as $col) {
                    // keep same widths; no-op if unchanged
                }

                // Column header row (MillMark..Planter)
                $row += 2;
                $sheet->setCellValue('A' . $row, 'MillMark');
                $sheet->setCellValue('B' . $row, 'Quedan No.');
                $sheet->setCellValue('C' . $row, 'Quantity');
                $sheet->setCellValue('D' . $row, 'Liens');
                $sheet->setCellValue('E' . $row, 'Week Ending');
                $sheet->setCellValue('F' . $row, 'Date Issued');
                $sheet->setCellValue('G' . $row, 'TIN');
                $sheet->setCellValue('H' . $row, 'Planter');
                $sheet->getStyle("A{$row}:H{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // Reset page counters
                $sheet->freezePane('A' . ($row + 1));
                $pageQty = 0;
                $pageLiens = 0;
                $pcs = 0;
                // next data row will be ++$row at loop top
            }
        }
    }

    // Final PAGE TOTAL
    $row++;
    $sheet->setCellValue('A' . $row, 'PAGE TOTAL:');
    $sheet->getStyle('A' . $row)->getFont()->setSize(12);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    $sheet->setCellValue('B' . $row, $pcs . ' PCS.');
    $sheet->getStyle('B' . $row)->getFont()->setSize(12);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('C' . $row, $pageQty);
    $sheet->getStyle('C' . $row)->getFont()->setSize(12);
    $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('D' . $row, $pageLiens);
    $sheet->getStyle('D' . $row)->getFont()->setSize(12);
    $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // GRAND TOTAL
    $row++;
    $sheet->setCellValue('A' . $row, 'GRAND TOTAL:');
    $sheet->getStyle('A' . $row)->getFont()->setSize(12);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    $sheet->setCellValue('B' . $row, $totalPcs . ' PCS.');
    $sheet->getStyle('B' . $row)->getFont()->setSize(12);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('C' . $row, $grandQty);
    $sheet->getStyle('C' . $row)->getFont()->setSize(12);
    $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('D' . $row, $grandLiens);
    $sheet->getStyle('D' . $row)->getFont()->setSize(12);
    $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Output as .xls (Excel5) to match your old file
    $filename = 'quedanListingExcel.xls';
    $tmpDir = storage_path('app/tmp');
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0775, true);
    }
    $path = $tmpDir . '/' . $filename;

    $writer = new Xls($spreadsheet);
    $writer->save($path);

    return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.ms-excel',
        ])
        ->deleteFileAfterSend(true);
}






}
