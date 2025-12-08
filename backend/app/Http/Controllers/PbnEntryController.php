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
        'pbn_number'   => 'required|string',
        'row'          => 'required|integer',
        'company_id'   => 'required|integer',
    ]);
    $companyId = (int) $validated['company_id'];

    $record = DB::table('pbn_entry_details')
        ->where('pbn_entry_id', $validated['pbn_entry_id'])
        ->where('pbn_number', $validated['pbn_number'])
        ->where('company_id', $companyId)
        ->where('id', $validated['row'])
        ->first();

    if (!$record) {
        return response()->json(['message' => 'Record not found.'], 404);
    }

    $data = (array) $record;
    $data['nid'] = $data['id'];
    unset($data['id']);

    DB::table('pbn_entry_details_log')->insert($data);

    DB::table('pbn_entry_details')
        ->where('pbn_entry_id', $validated['pbn_entry_id'])
        ->where('pbn_number', $validated['pbn_number'])
        ->where('company_id', $companyId)
        ->where('id', $validated['row'])
        ->delete();

    return response()->json(['message' => '✅ Deleted and logged successfully.']);
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
    $id = $id ?? $request->query('id');
    if (!$id) {
        return response()->json(['message' => 'Missing PBN id.'], 422);
    }

    // strict company scoping
    $companyId = $request->query('company_id');

    $main = \App\Models\PbnEntry::where('id', (int)$id)
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->firstOrFail();

    $details = \App\Models\PbnEntryDetail::where('pbn_entry_id', $main->id)
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->orderBy('row')
        ->get();

    if (!class_exists('\TCPDF', false)) {
        $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
        if (file_exists($tcpdfPath)) require_once $tcpdfPath;
        else abort(500, 'TCPDF not installed. Run: composer require tecnickcom/tcpdf');
    }

    // Map fields
    $pbnNo      = $main->pbn_number ?? '';
    $vendorID   = $main->vend_code ?? '';
    $vendorName = $main->vendor_name ?? '';
    $pbnDate    = $main->pbn_date ? \Carbon\Carbon::parse($main->pbn_date)->format('m/d/Y') : '';
    $cropYear   = $main->crop_year ?? '';

    // Prepare column content strings
    $grandQty = 0.0; $grandGross = 0.0;
    $colMill=''; $colQty=''; $colUC=''; $colCom=''; $colGross='';

    foreach ($details as $d) {
        $qty = (float)($d->quantity ?? 0);
        $uc  = (float)($d->unit_cost ?? 0);
        $com = (float)($d->commission ?? 0);
        $gross = ($uc + $com) * $qty;

        $grandQty  += $qty;
        $grandGross += $gross;

        $mill = strtoupper($d->mill_code ?: ($d->mill ?? ''));
        $colMill  .= " {$mill}\n";
        $colQty   .= number_format($qty, 2) . "\n";
        $colUC    .= number_format($uc, 2) . "\n";
        $colCom   .= number_format($com, 2) . "\n";
        $colGross .= number_format($gross, 2) . "\n";
    }
    $grandQtyF   = number_format($grandQty, 2);
    $grandGrossF = number_format($grandGross, 2);

    // --- Build PDF ---
    $logoPath = public_path('sucdenLogo.jpg');
    $pdf = new class('P', PDF_UNIT, 'LETTER', true, 'UTF-8', false) extends \TCPDF {
        public string $logoPath = '';
        public function Header() {
            if ($this->logoPath && is_file($this->logoPath)) {
                $this->Image($this->logoPath, 15, 12, 42, '', (strtolower(pathinfo($this->logoPath, PATHINFO_EXTENSION))==='png'?'PNG':'JPG'));
            }
        }
        public function Footer() {}
    };
    $pdf->logoPath = $logoPath;

    // Margins and manual layout (mm)
    $left=12; $top=16; $right=12; $bottom=12;
    $pdf->SetMargins($left, $top, $right);
    $pdf->SetHeaderMargin(4);
    $pdf->SetFooterMargin(0);
    $pdf->SetAutoPageBreak(false, $bottom);

    $pdf->AddPage('P', 'LETTER');
    $pageW = $pdf->getPageWidth();
    $pageH = $pdf->getPageHeight();
    $innerW = $pageW - $left - $right;

    // Title
    $pdf->Ln(14);
    $pdf->SetFont('helvetica','B',12);
    $pdf->Cell(0, 6, 'PURCHASE BOOK NOTE', 0, 1, 'R');
    $pdf->SetFont('helvetica','',10);
    $pdf->writeHTML(
        '<div style="text-align:right;"><font size="10">PBN No. </font>'.
        '<font size="12"><b><u><a href="#">'.htmlspecialchars($pbnNo,ENT_QUOTES,'UTF-8').'</a></u></b></font></div>',
        true,false,false,false,''
    );

    // Info band (HTML is fine)
    $infoHtml = <<<EOD
<table border="1" cellpadding="2" cellspacing="0" width="100%">
  <tr bgcolor="#E6E6E6">
    <td width="10%"><font size="9">Trader:</font></td>
    <td width="38%"><font size="9"><u>{$vendorID}</u></font></td>
    <td width="14%"><font size="9">PBN Date:</font></td>
    <td width="16%"><font size="9"><u>{$pbnDate}</u></font></td>
    <td width="22%"></td>
  </tr>
  <tr bgcolor="#E6E6E6">
    <td><font size="9">Supplier:</font></td>
    <td><font size="9"><u><b>{$vendorName}</b></u></font></td>
    <td><font size="9">Crop Year:</font></td>
    <td><font size="9"><u>{$cropYear}</u></font></td>
    <td></td>
  </tr>
</table>
EOD;
    $pdf->writeHTML($infoHtml, true, false, false, false, '');

    // ===== Geometry so grid sticks to signature band =====
    $yAfterInfo    = $pdf->GetY();
    $usableBottomY = $pageH - $bottom;

    $SIGNATURE_H     = 18.0; // signature band height
    $GAP_ABOVE_SIGN  = 3.0;  // space between grid and signatures
    $GAP_AFTER_INFO  = 1.0;  // space after info band

    $gridTopY      = $yAfterInfo + $GAP_AFTER_INFO;
    $gridBottomY   = $usableBottomY - $SIGNATURE_H - $GAP_ABOVE_SIGN;
    $gridBoxH      = $gridBottomY - $gridTopY;     // final exact height for the WHOLE grid (header+body+total)

    // Row heights (mm)
    $HDR_H   = 10.0;
    $TOTAL_H = 10.0;
    $BODY_H  = max(60.0, $gridBoxH - $HDR_H - $TOTAL_H); // will stretch to fill

    // Column widths (percent of inner width)
    $wMill = 0.33 * $innerW;
    $wQty  = 0.18 * $innerW;
    $wUC   = 0.15 * $innerW;
    $wCom  = 0.15 * $innerW;
    $wGross= $innerW - ($wMill+$wQty+$wUC+$wCom);

    // Column X positions
    $xMill = $left;
    $xQty  = $xMill + $wMill;
    $xUC   = $xQty  + $wQty;
    $xCom  = $xUC   + $wUC;
    $xGross= $xCom  + $wCom;

    // ---- Draw grid header row with borders ----
    $pdf->SetFont('helvetica','B',9);
    $pdf->SetXY($left, $gridTopY);
    $pdf->Cell($wMill,  $HDR_H, 'MillMark',     1, 0, 'C');
    $pdf->Cell($wQty,   $HDR_H, 'Qty in LKG',   1, 0, 'C');
    $pdf->Cell($wUC,    $HDR_H, 'Price/LKG',    1, 0, 'C');
    $pdf->Cell($wCom,   $HDR_H, 'Commission',   1, 0, 'C');
    $pdf->Cell($wGross, $HDR_H, 'Gross Amount', 1, 1, 'C');

    // ---- Draw body rectangle to an exact height + vertical lines ----
    $bodyTopY = $gridTopY + $HDR_H;
    $pdf->Rect($left, $bodyTopY, $innerW, $BODY_H); // outer body rectangle
    // vertical splits inside the body rect
    $pdf->Line($xQty,   $bodyTopY, $xQty,   $bodyTopY + $BODY_H);
    $pdf->Line($xUC,    $bodyTopY, $xUC,    $bodyTopY + $BODY_H);
    $pdf->Line($xCom,   $bodyTopY, $xCom,   $bodyTopY + $BODY_H);
    $pdf->Line($xGross, $bodyTopY, $xGross, $bodyTopY + $BODY_H);

    // ---- Write body text (no borders) inside the rectangle ----
    $pdf->SetFont('helvetica','',9);
    // small inner padding
    $pad = 1.5;

    // Mill
    $pdf->SetXY($xMill + $pad, $bodyTopY + $pad);
    $pdf->MultiCell($wMill - 2*$pad, $BODY_H - 2*$pad, $colMill, 0, 'L', false, 1);
    // Qty (right)
    $pdf->SetXY($xQty + $pad, $bodyTopY + $pad);
    $pdf->MultiCell($wQty - 2*$pad, $BODY_H - 2*$pad, $colQty, 0, 'R', false, 1);
    // Price
    $pdf->SetXY($xUC + $pad, $bodyTopY + $pad);
    $pdf->MultiCell($wUC - 2*$pad, $BODY_H - 2*$pad, $colUC, 0, 'R', false, 1);
    // Commission
    $pdf->SetXY($xCom + $pad, $bodyTopY + $pad);
    $pdf->MultiCell($wCom - 2*$pad, $BODY_H - 2*$pad, $colCom, 0, 'R', false, 1);
    // Gross
    $pdf->SetXY($xGross + $pad, $bodyTopY + $pad);
    $pdf->MultiCell($wGross - 2*$pad, $BODY_H - 2*$pad, $colGross, 0, 'R', false, 1);

    // ---- Totals row (exactly under the body) ----
    $totTopY = $bodyTopY + $BODY_H;
    $pdf->SetFont('helvetica','B',9);
    $pdf->SetXY($left, $totTopY);
    $pdf->Cell($wMill + $wQty + $wUC, $TOTAL_H, 'TOTAL', 1, 0, 'L');
    $pdf->Cell($wCom,   $TOTAL_H, $grandQtyF,   1, 0, 'R');
    $pdf->Cell($wGross, $TOTAL_H, $grandGrossF, 1, 1, 'R');

    // ---- Signature band at fixed bottom ----
    $pdf->SetY($usableBottomY - $SIGNATURE_H);
    $signHtml = <<<EOD
<table border="1" cellpadding="4" cellspacing="0" width="100%">
  <tr>
    <td width="50%" align="center">___________ Posted By: ___________</td>
    <td width="50%" align="center">___________ Prepared By: ___________</td>
  </tr>
</table>
EOD;
    $pdf->writeHTML($signHtml, true, false, false, false, '');

    // Output inline
    $content = $pdf->Output('pbnForm.pdf', 'S');
    return response($content, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="pbnForm.pdf"')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
}


/**
 * Small number formatter for local use.
 * If you prefer, move this to a shared helper.
 */
private function numFmt($n): string
{
    return number_format((float)$n, 2);
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
    $receiptNo   = '';

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

    // (same body as your current Excel builder; unchanged below this comment)
    // --- header block ---
    $sheet->setCellValue('A1', 'Shipper: SUCDEN PHILIPPINES, INC'); $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A2', 'Buyer: SUCDEN AMERICAS CORP.');     $sheet->mergeCells('A2:H2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A3', 'Quedan Listings (CY' . $cropYear . ')'); $sheet->mergeCells('A3:H3');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A4', $currentDate);                       $sheet->mergeCells('A4:H4');
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10);

    $sheet->setCellValue('A5', 'RR No.:' . $receiptNo);             $sheet->mergeCells('A5:H5');
    $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(10);

    $sheet->getColumnDimension('A')->setWidth(15);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(15);
    $sheet->getColumnDimension('F')->setWidth(15);
    $sheet->getColumnDimension('G')->setWidth(15);
    $sheet->getColumnDimension('H')->setWidth(20);

    $sheet->setCellValue('A7', 'MillMark');
    $sheet->setCellValue('B7', 'Quedan No.');
    $sheet->setCellValue('C7', 'Quantity');
    $sheet->setCellValue('D7', 'Liens');
    $sheet->setCellValue('E7', 'Week Ending');
    $sheet->setCellValue('F7', 'Date Issued');
    $sheet->setCellValue('G7', 'TIN');
    $sheet->setCellValue('H7', 'Planter');

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

    $row = 7; $pageCount = 0; $pcs = 0; $totalPcs = 0;
    $pageQty = 0; $pageLiens = 0; $grandQty = 0; $grandLiens = 0;

    if ($details->isNotEmpty()) {
        foreach ($details as $d) {
            $millMark = strtoupper($d->mill_code ?: ($d->mill ?? ''));
            $qty      = (int) round((float) ($d->quantity ?? 0));
            $liens    = (int) round(((float) ($d->quantity ?? 0)) * ((float) ($d->commission ?? 0)));

            $pageCount++; $pcs++; $totalPcs++;
            $pageQty += $qty; $pageLiens += $liens; $grandQty += $qty; $grandLiens += $liens;

            $row++;
            $sheet->setCellValue('A' . $row, $millMark);
            $sheet->setCellValueExplicit('B' . $row, '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $row, $qty);
            $sheet->setCellValue('D' . $row, $liens);
            $sheet->setCellValue('E' . $row, '');
            $sheet->setCellValue('F' . $row, '');
            $sheet->setCellValue('G' . $row, '');
            $sheet->setCellValue('H' . $row, '');
            $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            if (($pageCount % 50) === 0) {
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

                $row++;
                $sheet->setCellValue('A' . $row, ''); $sheet->mergeCells("A{$row}:H{$row}");

                $row++;
                $sheet->setCellValue('A' . $row, 'Shipper: SUCDEN PHILIPPINES, INC.'); $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $row++;
                $sheet->setCellValue('A' . $row, 'Buyer: SUCDEN AMERICAS CORP.'); $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $row++;
                $sheet->setCellValue('A' . $row, 'Quedan Listings (CY' . $cropYear . ')'); $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $row++;
                $sheet->setCellValue('A' . $row, $currentDate); $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $row++;
                $sheet->setCellValue('A' . $row, 'RR No.:' . $receiptNo); $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

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

                $sheet->freezePane('A' . ($row + 1));
                $pageQty = 0; $pageLiens = 0; $pcs = 0;
            }
        }
    }

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

    $filename = 'quedanListingExcel.xls';
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
