<?php

namespace App\Http\Controllers;

use App\Models\GeneralAccounting;
use App\Models\GeneralAccountingDetail;
use App\Models\AccountCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MyJournalVoucherPDF extends \TCPDF
{
    public $journalDate;
    public $journalTime;
    public function setDataJournalDate($d) { $this->journalDate = $d; }
    public function setDataJournalTime($t) { $this->journalTime = $t; }

    public function Header()
    {
        $candidates = [
            public_path('images/sucdenLogo.jpg'),
            public_path('images/sucdenLogo.png'),
            public_path('sucdenLogo.jpg'),
            public_path('sucdenLogo.png'),
        ];
        foreach ($candidates as $img) {
            if ($img && is_file($img)) {
                $ext = strtoupper(pathinfo($img, PATHINFO_EXTENSION));
                $this->Image($img, 15, 10, 50, '', $ext, '', 'T', false, 300, '', false, false, 0, false, false, false);
                break;
            }
        }
    }

    public function Footer()
    {
        $this->SetY(-50);
        $this->SetFont('helvetica','I',8);
        $currentDate = date('M d, Y');
        $currentTime = date('h:i:sa');

        $html = '
        <table border="0"><tr>
          <td width="70%">
            <table border="1" cellpadding="5"><tr>
              <td><font size="8">Prepared:<br><br><br><br><br></font></td>
              <td><font size="8">Checked:<br><br><br><br><br></font></td>
              <td><font size="8">Approved:<br><br><br><br><br></font></td>
              <td><font size="8">Posted by:<br><br><br><br><br></font></td>
            </tr></table>
          </td>
          <td width="5%"></td>
          <td width="25%"></td>
        </tr></table>
        <br>
        <table border="0">
          <tr>
            <td width="10%"><font size="8">Printed:</font></td>
            <td width="15%"><font size="8">'.$currentDate.'</font></td>
            <td width="15%"><font size="8">'.$currentTime.'</font></td>
            <td width="60%"></td>
          </tr>
          <tr>
            <td><font size="8">Created:</font></td>
            <td><font size="8">'.$this->journalDate.'</font></td>
            <td><font size="8">'.$this->journalTime.'</font></td>
            <td></td>
          </tr>
        </table>';
        $this->writeHTML($html, true, false, false, false, '');
    }
}

class GeneralAccountingController extends Controller
{
    
 /** 6) Show main + details (company-scoped) */
public function show($id, Request $req)
{
    $companyId = (int) $req->query('company_id');

    // ✅ main: enforce company scope
    $main = GeneralAccounting::where('id', (int)$id)
        ->where('company_id', $companyId)
        ->first();

    if (!$main) {
        return response()->json(['message' => 'Journal not found.'], 404);
    }

    // ✅ details: enforce transaction + company scope
    $details = GeneralAccountingDetail::from('general_accounting_details as d')
        ->leftJoin('account_code as a', function ($j) use ($companyId) {
            $j->on('d.acct_code', '=', 'a.acct_code')
              ->where('a.company_id', '=', $companyId);
        })
        ->where('d.transaction_id', (int)$main->id)
        ->where('d.company_id', $companyId)
        ->orderBy('d.id')
        ->get([
            'd.id',
            'd.transaction_id',
            'd.acct_code',
            DB::raw("COALESCE(a.acct_desc,'') as acct_desc"),
            'd.debit',
            'd.credit',
            'd.company_id',
        ]);

    return response()->json([
        'main'    => $main,
        'details' => $details,
    ]);
}
   
    
    
    
    /** 1) Generate next GA number */
    public function generateGaNumber(Request $req)
    {
        $companyId = $req->query('company_id');

        $last = GeneralAccounting::when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderBy('ga_no', 'desc')
            ->value('ga_no');

        $base = is_numeric($last) ? (int)$last : 100000;
        return response()->json(['ga_no' => (string)($base + 1)]);
    }

    /** 2) Create main header */
    public function storeMain(Request $req)
    {
        $data = $req->validate([
            'ga_no'         => ['nullable','string','max:25'],
            'gen_acct_date' => ['required','date'],
            'explanation'   => ['required','string','max:1000'],
            'company_id'    => ['required','integer'],
            'workstation_id'=> ['nullable','string','max:25'],
            'user_id'       => ['nullable','integer'],
            'type'          => ['nullable','string','max:2'], // e.g. 'g' (legacy style)
        ]);

        if (empty($data['ga_no'])) {
            $next = $this->generateGaNumber(new Request(['company_id' => $data['company_id']]));
            $data['ga_no'] = $next->getData()->ga_no ?? null;
        }


        // ✅ Default workstation_id to client IP if not provided
        // (Details will inherit this value if they don't send workstation_id)
        if (empty($data['workstation_id'])) {
            $data['workstation_id'] = (string) $req->ip();
        }


        $data['gen_acct_amount'] = 0;
        $data['sum_debit'] = 0;
        $data['sum_credit'] = 0;
        $data['is_balanced'] = false;
        $data['is_cancel'] = 'n';

        $main = GeneralAccounting::create($data);

        return response()->json([
            'id'    => $main->id,
            'ga_no' => $main->ga_no,
        ]);
    }

    /** 3) Insert detail line (enforce validations) */
/**
 * 3) Insert detail line (company-scoped + not-cancelled guard)
 * NOTE: saveDetail does NOT require approval (matches your current behavior).
 * If you want approval here too, uncomment the requireEditApproval line.
 */
public function saveDetail(Request $req)
{
    $payload = $req->validate([
        'transaction_id' => ['required','integer','exists:general_accounting,id'],
        'acct_code'      => ['required','string','max:75'],
        'debit'          => ['nullable','numeric'],
        'credit'         => ['nullable','numeric'],
        'company_id'     => ['required','integer'],
        'user_id'        => ['nullable','integer'],
        'workstation_id' => ['nullable','string','max:25'],
    ]);

    // 1) Load main + enforce tenant (company) safety
    $main = GeneralAccounting::findOrFail((int)$payload['transaction_id']);

    if ((int)$main->company_id !== (int)$payload['company_id']) {
        abort(403, 'Company mismatch.');
    }

    // 2) Block edits if cancelled/deleted
    if (in_array((string)$main->is_cancel, ['c','d','y'], true)) {
        abort(403, 'Cancelled/deleted journal cannot be modified.');
    }

    // Optional: require approval for adding details too
    // $this->requireEditApproval((int)$main->id);


// 3) Ensure workstation_id (DB NOT NULL)
// Prefer payload -> header -> client IP
if (empty($payload['workstation_id'])) {
    if (!empty($main->workstation_id)) {
        $payload['workstation_id'] = $main->workstation_id;
    } else {
        $payload['workstation_id'] = (string) $req->ip();
    }
}


    // 4) Validate debit/credit XOR and >0
    $d = (float)($payload['debit'] ?? 0);
    $c = (float)($payload['credit'] ?? 0);
    if (($d > 0 && $c > 0) || ($d <= 0 && $c <= 0)) {
        return response()->json(['message' => 'Provide either debit OR credit (not both/zero).'], 422);
    }

    // 5) Validate account is active FOR THIS COMPANY
    $acctOk = AccountCode::where('acct_code', $payload['acct_code'])
        ->where('company_id', (int)$payload['company_id'])
        ->where('active_flag', 1)
        ->exists();

    if (!$acctOk) {
        return response()->json(['message' => 'Invalid or inactive account.'], 422);
    }

// 6) Allow duplicate acct_code lines (legacy-style journals often repeat accounts)
// (No duplicate blocking)


    // 7) Set FK field (your table uses both naming styles)
    $payload['general_accounting_id'] = (int)$payload['transaction_id'];

    $detail = GeneralAccountingDetail::create($payload);
    $totals = $this->recalcTotals((int)$payload['transaction_id']);

    return response()->json(['detail_id' => $detail->id, 'totals' => $totals]);
}


/**
 * 4) Update detail (company-safe detail lookup + company-scoped acct validation + not-cancelled guard)
 */
public function updateDetail(Request $req)
{
    $payload = $req->validate([
        'id'             => ['required','integer','exists:general_accounting_details,id'],
        'transaction_id' => ['required','integer','exists:general_accounting,id'],
        'company_id'     => ['required','integer'],
        'acct_code'      => ['nullable','string','max:75'],
        'debit'          => ['nullable','numeric'],
        'credit'         => ['nullable','numeric'],
    ]);

    // 1) Load main + enforce tenant safety
    $main = GeneralAccounting::findOrFail((int)$payload['transaction_id']);
    $companyId = (int)$main->company_id;

    if ($companyId !== (int)$payload['company_id']) {
        abort(403, 'Company mismatch.');
    }

    // 2) Block edits if cancelled/deleted
    if (in_array((string)$main->is_cancel, ['c','d','y'], true)) {
        abort(403, 'Cancelled/deleted journal cannot be modified.');
    }

    // 3) Approval gate (your existing behavior)
    $this->requireEditApproval((int)$main->id);

    // 4) Tenant-safe detail lookup: id + transaction_id + company_id
    $row = GeneralAccountingDetail::where('id', (int)$payload['id'])
        ->where('transaction_id', (int)$main->id)
        ->where('company_id', $companyId)
        ->first();

    if (!$row) {
        return response()->json(['message' => 'Detail not found.'], 404);
    }

    // 5) Build apply set
    $apply = [];
    if (array_key_exists('acct_code', $payload)) $apply['acct_code'] = $payload['acct_code'];
    if (array_key_exists('debit', $payload))     $apply['debit']     = $payload['debit'];
    if (array_key_exists('credit', $payload))    $apply['credit']    = $payload['credit'];

    // 6) Validate debit/credit rules (consider BOTH existing + incoming values)
    $newDebit  = array_key_exists('debit',  $apply) ? (float)($apply['debit'] ?? 0) : (float)($row->debit ?? 0);
    $newCredit = array_key_exists('credit', $apply) ? (float)($apply['credit'] ?? 0) : (float)($row->credit ?? 0);

    if (($newDebit > 0 && $newCredit > 0) || ($newDebit <= 0 && $newCredit <= 0)) {
        return response()->json(['message' => 'Provide either debit OR credit (not both/zero).'], 422);
    }

    // 7) If changing acct_code: validate account active for THIS COMPANY + prevent duplicates
    if (array_key_exists('acct_code', $apply) && $apply['acct_code'] !== $row->acct_code) {
        $acctOk = AccountCode::where('acct_code', $apply['acct_code'])
            ->where('company_id', $companyId)
            ->where('active_flag', 1)
            ->exists();

        if (!$acctOk) {
            return response()->json(['message' => 'Invalid or inactive account.'], 422);
        }

// Allow duplicate acct_code lines (no duplicate blocking)

    }

    $row->update($apply);

    $totals = $this->recalcTotals((int)$main->id);
    return response()->json(['ok' => true, 'totals' => $totals]);
}


/**
 * 5) Delete detail (company-safe delete + not-cancelled guard)
 */
public function deleteDetail(Request $req)
{
    $payload = $req->validate([
        'id'             => ['required','integer','exists:general_accounting_details,id'],
        'transaction_id' => ['required','integer','exists:general_accounting,id'],
        'company_id'     => ['required','integer'],
    ]);

    // 1) Load main + enforce tenant safety
    $main = GeneralAccounting::findOrFail((int)$payload['transaction_id']);
    $companyId = (int)$main->company_id;

    if ($companyId !== (int)$payload['company_id']) {
        abort(403, 'Company mismatch.');
    }

    // 2) Block edits if cancelled/deleted
    if (in_array((string)$main->is_cancel, ['c','d','y'], true)) {
        abort(403, 'Cancelled/deleted journal cannot be modified.');
    }

    // 3) Approval gate (your existing behavior)
    $this->requireEditApproval((int)$main->id);

    // 4) Tenant-safe delete: scope by id + transaction_id + company_id
    $deleted = GeneralAccountingDetail::where('id', (int)$payload['id'])
        ->where('transaction_id', (int)$main->id)
        ->where('company_id', $companyId)
        ->delete();

    if (!$deleted) {
        return response()->json(['message' => 'Detail not found.'], 404);
    }

    $totals = $this->recalcTotals((int)$main->id);
    return response()->json(['ok' => true, 'totals' => $totals]);
}

    /** 7) List for JE dropdown */
public function list(Request $req)
{
    $companyId = $req->query('company_id');
    $q  = trim((string)$req->query('q',''));
    $qq = strtolower($q);

    $rows = GeneralAccounting::when($companyId, fn($qr)=>$qr->where('company_id',$companyId))
        // 🔹 hide soft-deleted rows (is_cancel = 'd'), keep others
        ->where(function ($qr) {
            $qr->whereNull('is_cancel')
               ->orWhere('is_cancel', '!=', 'd');
        })
        ->when($q !== '', function($qr) use ($qq) {
            $qr->where(function($w) use ($qq) {
                $w->whereRaw('LOWER(ga_no) LIKE ?', ["%{$qq}%"])
                  ->orWhereRaw('LOWER(explanation) LIKE ?', ["%{$qq}%"]);
            });
        })
        ->orderByDesc('ga_no')
        ->limit(50)
        ->get([
            'id','ga_no','gen_acct_date','explanation',
            'sum_debit','sum_credit','is_cancel',
        ]);

    return response()->json($rows);
}


    /** 8) Accounts (active) for autocomplete */
    public function accounts(Request $req)
    {
        $companyId = $req->query('company_id');
        $q = trim((string)$req->query('q',''));

        $rows = AccountCode::where('active_flag',1)
            ->when($companyId, fn($w)=>$w->where('company_id',$companyId))
            ->when($q, fn($w)=>$w->where(function($k) use ($q){
                $k->where('acct_code','like',"%$q%")->orWhere('acct_desc','like',"%$q%");
            }))
            ->orderBy('acct_desc')
            ->limit(2000)
            ->get(['acct_code','acct_desc']);

        return response()->json($rows);
    }

    /** 9) Cancel / Uncancel */
/** 9) Cancel / Uncancel (matches Sales Journal behavior) */
public function updateCancel(Request $req)
{
    $data = $req->validate([
        'id'         => ['required','integer','exists:general_accounting,id'],
        'company_id' => ['required','integer'],
        'flag'       => ['required','in:0,1'],
    ]);

    $val = $data['flag'] === '1' ? 'c' : 'n';

    $updated = GeneralAccounting::where('id', (int)$data['id'])
        ->where('company_id', (int)$data['company_id'])
        ->update(['is_cancel' => $val]);

    if (!$updated) abort(404, 'Not found.');

    return response()->json(['ok'=>true,'is_cancel'=>$val]);
}


    /** 10) Delete main (cascade details) */
/** 10) Soft-delete main JE (like Sales Journal) */
public function destroy(Request $req, $id)
{
    $data = $req->validate([
        'company_id' => ['required','integer'],
    ]);

    $row = GeneralAccounting::where('id', (int)$id)
        ->where('company_id', (int)$data['company_id'])
        ->first();

    if (!$row) return response()->json(['message'=>'Journal Voucher not found.'], 404);

    if ($row->is_cancel === 'd') return response()->json(['ok'=>true,'is_cancel'=>'d']);

    $row->is_cancel = 'd';
    $row->save();

    return response()->json(['ok'=>true,'is_cancel'=>'d']);
}



    /** 11) Print Journal Voucher PDF */
public function formPdf(Request $request, $id)
{
    $companyId = $request->query('company_id');

    // 1) Header (company-scoped)
    $header = DB::table('general_accounting as g')
        ->select(
            'g.id',
            'g.ga_no',
            'g.explanation',
            'g.is_cancel',
            DB::raw("to_char(g.gen_acct_date, 'MM/DD/YYYY') as gen_acct_date"),
            'g.created_at'
        )
        ->when($companyId, fn($q) => $q->where('g.company_id', $companyId))
        ->where('g.id', $id)
        ->first();

        if (
            !$header ||
            in_array($header->is_cancel, ['y', 'c', 'd'], true) // 'y' (legacy), 'c' (cancelled), 'd' (deleted)
        ) {
            abort(404, 'Journal Voucher not found or cancelled');
        }


    // 2) Details + live totals (don’t trust stored flags)
$details = DB::table('general_accounting_details as d')
    ->join('account_code as a', function ($j) use ($companyId) {
        $j->on('d.acct_code', '=', 'a.acct_code');
        if ($companyId) {
            $j->where('a.company_id', $companyId);
        }
    })
    ->where('d.transaction_id', $id)
    ->when($companyId, fn($q) => $q->where('d.company_id', $companyId))
    ->orderBy('d.id')
    ->get(['d.acct_code','a.acct_desc','d.debit','d.credit']);


    $totalDebit  = (float)$details->sum('debit');
    $totalCredit = (float)$details->sum('credit');

    // 3) If unbalanced, return HTML (same behavior as your working Purchase PDF)
    if (abs($totalDebit - $totalCredit) > 0.005) {
        $html = sprintf(
            '<!doctype html><meta charset="utf-8">
             <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
             <h2>Cannot print Journal Voucher</h2>
             <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before printing.</p>
             <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
            number_format($totalDebit, 2),
            number_format($totalCredit, 2)
        );
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // 4) Build PDF exactly like the working Purchase flow (no streamDownload)
    $pdf = new MyJournalVoucherPDF('P','mm','LETTER', true, 'UTF-8', false);
    $pdf->setPrintHeader(true);
    $pdf->SetHeaderMargin(8);
    $pdf->SetMargins(15, 30, 15);
    $pdf->AddPage();
    $pdf->SetFont('helvetica','',7);

    // footer metadata (your subclass already exposes these setters)
    $pdf->setDataJournalDate(\Carbon\Carbon::parse($header->created_at)->format('M d, Y'));
    $pdf->setDataJournalTime(\Carbon\Carbon::parse($header->created_at)->format('h:i:sa'));

    $formattedDebit  = number_format($totalDebit, 2);
    $formattedCredit = number_format($totalCredit, 2);

    $tbl = <<<EOD
<br><br>
<table border="0" cellpadding="1" cellspacing="0" nobr="true" width="100%">
<tr>
  <td width="30%"></td>
  <td width="40%" align="center"><div><font size="16"><b>JOURNAL VOUCHER</b></font></div></td>
  <td width="30%"></td>
</tr>
<tr><td colspan="3"></td></tr>
<tr>
  <td width="40%"><font size="10"><b>Date:</b> {$header->gen_acct_date}</font></td>
  <td width="20%"></td>
  <td width="40%" align="left"><font size="14"><b><u>JE - {$header->ga_no}</u></b></font></td>
</tr>
</table>

<table><tr><td><br><br></td></tr></table>
<table border="1" cellspacing="0" cellpadding="5">
  <tr><td align="center"><font size="10"><b>EXPLANATION</b></font></td></tr>
  <tr><td height="80" valign="top"><font size="10">{$header->explanation}</font></td></tr>
</table>

<table><tr><td><br><br></td></tr></table>
<table border="1" cellpadding="3" cellspacing="0" nobr="true" width="100%">
 <tr>
  <td width="30%" align="center"><font size="10"><b>ACCOUNT</b></font></td>
  <td width="40%" align="center"><font size="10"><b>GL ACCOUNT</b></font></td>
  <td width="15%" align="center"><font size="10"><b>DEBIT</b></font></td>
  <td width="15%" align="center"><font size="10"><b>CREDIT</b></font></td>
 </tr>
EOD;

    foreach ($details as $d) {
        $debit  = $d->debit  ? number_format($d->debit, 2) : '';
        $credit = $d->credit ? number_format($d->credit, 2) : '';
        $acct   = htmlspecialchars($d->acct_code ?? '', ENT_QUOTES, 'UTF-8');
        $desc   = htmlspecialchars($d->acct_desc ?? '', ENT_QUOTES, 'UTF-8');
        $tbl .= <<<EOD
  <tr>
    <td align="left"><font size="10">{$acct}</font></td>
    <td align="left"><font size="10">{$desc}</font></td>
    <td align="right"><font size="10">{$debit}</font></td>
    <td align="right"><font size="10">{$credit}</font></td>
  </tr>
EOD;
    }

    $tbl .= <<<EOD
  <tr>
    <td align="left"></td>
    <td align="left"><font size="10">TOTAL</font></td>
    <td align="right"><font size="10">{$formattedDebit}</font></td>
    <td align="right"><font size="10">{$formattedCredit}</font></td>
  </tr>
</table>
EOD;

    $pdf->writeHTML($tbl, true, false, false, false, '');

    // 5) Return bytes (no streaming); inline so your iframe shows it
    $pdfContent = $pdf->Output('journalVoucher.pdf', 'S');

    return response($pdfContent, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="JournalVoucher_'.$header->ga_no.'.pdf"');
}



    /** 12) Excel stub (optional) */
public function formExcel($id, Request $req)
{
    $companyId = $req->query('company_id');

    // Header
    $header = DB::table('general_accounting as g')
        ->select(
            'g.id','g.ga_no','g.explanation','g.is_cancel',
            DB::raw("to_char(g.gen_acct_date, 'YYYY-MM-DD') as gen_acct_date"),
            'g.created_at'
        )
        ->when($companyId, fn($q)=>$q->where('g.company_id',$companyId))
        ->where('g.id',$id)
        ->first();

        if (
            !$header ||
            in_array($header->is_cancel, ['y', 'c', 'd'], true)
        ) {
            return response()->json(['message' => 'Journal Voucher not found or cancelled'], 404);
        }


    // 🔴 Live totals
    $totals = DB::table('general_accounting_details')
        ->where('transaction_id', $id)
        ->selectRaw('COALESCE(SUM(debit),0) AS sum_debit, COALESCE(SUM(credit),0) AS sum_credit')
        ->first();

    $totalDebit  = round((float)($totals->sum_debit ?? 0), 2);
    $totalCredit = round((float)($totals->sum_credit ?? 0), 2);
    $balanced    = abs($totalDebit - $totalCredit) < 0.005;

    if (!$balanced) {
        return response()->json([
            'message' => 'Cannot export: details are not balanced.',
            'debit'   => $totalDebit,
            'credit'  => $totalCredit,
        ], 422);
    }

    // Details
$details = DB::table('general_accounting_details as d')
    ->join('account_code as a', function ($j) use ($companyId) {
        $j->on('d.acct_code', '=', 'a.acct_code');
        if ($companyId) {
            $j->where('a.company_id', $companyId);
        }
    })
    ->where('d.transaction_id', $id)
    ->when($companyId, fn($q) => $q->where('d.company_id', $companyId))
    ->orderBy('d.id')
    ->get(['d.acct_code','a.acct_desc','d.debit','d.credit']);


    // Spreadsheet
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Journal Voucher');

    $row = 1;
    $sheet->setCellValue("A{$row}", 'JOURNAL VOUCHER'); $sheet->mergeCells("A{$row}:D{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14); $row += 2;

    $sheet->setCellValue("A{$row}", 'JE No:');   $sheet->setCellValue("B{$row}", 'JE - '.$header->ga_no);
    $sheet->setCellValue("C{$row}", 'Date:');    $sheet->setCellValue("D{$row}", $header->gen_acct_date);
    $row++;

    $sheet->setCellValue("A{$row}", 'Explanation:'); $sheet->mergeCells("A{$row}:D{$row}"); $row++;
    $sheet->setCellValue("A{$row}", (string)$header->explanation); $sheet->mergeCells("A{$row}:D{$row}");
    $row += 2;

    // Table header
    $sheet->fromArray(['Account','GL Account','Debit','Credit'], NULL, "A{$row}");
    $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:D{$row}")
          ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    $row++;

    // Rows
    foreach ($details as $d) {
        $sheet->setCellValueExplicit("A{$row}", $d->acct_code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue("B{$row}", $d->acct_desc);
        $sheet->setCellValue("C{$row}", (float)($d->debit ?? 0));
        $sheet->setCellValue("D{$row}", (float)($d->credit ?? 0));
        $row++;
    }

    // Totals row
    $sheet->setCellValue("B{$row}", 'TOTAL');
    $sheet->setCellValue("C{$row}", $totalDebit);
    $sheet->setCellValue("D{$row}", $totalCredit);
    $sheet->getStyle("B{$row}:D{$row}")->getFont()->setBold(true);

    // Formats/borders/widths
    $firstDetailRow = $row - max(1, count($details));
    $sheet->getStyle("C1:D{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("A{$firstDetailRow}:D{$row}")
          ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    foreach (['A'=>14,'B'=>44,'C'=>16,'D'=>16] as $col=>$w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

    // Stream
    $fileName = 'JournalVoucher_'.$header->ga_no.'.xlsx';
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

    if (ob_get_length()) { ob_end_clean(); }
    return response()->streamDownload(function() use ($writer) {
        $writer->save('php://output');
    }, $fileName, [
        'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Cache-Control'       => 'max-age=0, private',
        'Pragma'              => 'public',
        'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        'X-Accel-Buffering'   => 'no',
    ]);
}


/**
 * Update JE header (date + explanation).
 * Requires a valid "edit" approval.
 */
public function updateMain(Request $req)
{
    $data = $req->validate([
        'id'           => ['required','integer','exists:general_accounting,id'],
        'gen_acct_date'=> ['required','date'],
        'explanation'  => ['required','string','max:1000'],
    ]);

    // 🔐 Enforce supervisor approval
    $this->requireEditApproval((int) $data['id']);

    $main = GeneralAccounting::findOrFail($data['id']);

    $main->gen_acct_date = $data['gen_acct_date'];
    $main->explanation   = $data['explanation'];
    $main->save();

    return response()->json([
        'ok'            => true,
        'id'            => $main->id,
        'gen_acct_date' => $main->gen_acct_date,
        'explanation'   => $main->explanation,
    ]);
}


/**
 * Update JE header (date + explanation) WITHOUT approval.
 * Used by "Save Main" button.
 */
public function updateMainNoApproval(Request $req)
{
    $data = $req->validate([
        'id'           => ['required','integer','exists:general_accounting,id'],
        'gen_acct_date'=> ['required','date'],
        'explanation'  => ['required','string','max:1000'],
    ]);

    $main = GeneralAccounting::findOrFail($data['id']);

    // ❗ safety rule (same intent as Cash Disbursement):
    // cannot update cancelled or deleted
    if (in_array($main->is_cancel, ['c','d','y'], true)) {
        abort(403, 'Cancelled or deleted journal cannot be updated.');
    }

$update = [
    'gen_acct_date' => $data['gen_acct_date'],
    'explanation'   => $data['explanation'],
];

// ✅ If workstation_id is still empty (older records), set it to client IP once
if (empty($main->workstation_id)) {
    $update['workstation_id'] = (string) $req->ip();
}

$main->update($update);


    return response()->json([
        'ok'            => true,
        'id'            => $main->id,
        'gen_acct_date' => $main->gen_acct_date,
        'explanation'   => $main->explanation,
    ]);
}



/**
 * Require a valid "edit" approval for this General Accounting transaction.
 *
 * Returns the approval row (stdClass) if OK, otherwise aborts 403.
 */
protected function requireEditApproval(int $transactionId): \stdClass
{
    $main = GeneralAccounting::findOrFail($transactionId);

    $module    = 'general_accounting';
    $companyId = (int) $main->company_id;

    $row = DB::table('approvals')
        ->where('module', $module)
        ->where('record_id', $transactionId)
        ->where('company_id', $companyId)
        ->where('action', 'edit')
        ->where('status', 'approved')
        ->whereNull('consumed_at')
        ->orderByDesc('id')
        ->first();

    if (!$row) {
        abort(403, 'Supervisor approval required for editing this journal entry.');
    }

    // Check expiry
    $now = now();
    if ($row->expires_at) {
        $expires = \Carbon\Carbon::parse($row->expires_at);
        if ($now->gte($expires)) {
            abort(403, 'Edit approval has expired. Please request a new approval.');
        }
    }

    // First time used? mark first_edit_at
    if (empty($row->first_edit_at)) {
        DB::table('approvals')->where('id', $row->id)->update([
            'first_edit_at' => $now,
            'updated_at'    => $now,
        ]);
        $row->first_edit_at = $now;
    }

    return $row;
}





    /** ---- helper ---- */
protected function recalcTotals(int $transactionId): array
{
    $tot = GeneralAccountingDetail::where('transaction_id', $transactionId)
        ->selectRaw('COALESCE(SUM(debit),0) as sum_debit, COALESCE(SUM(credit),0) as sum_credit')
        ->first();

    $sumDebit  = round((float)($tot->sum_debit ?? 0), 2);
    $sumCredit = round((float)($tot->sum_credit ?? 0), 2);
    $balanced  = abs($sumDebit - $sumCredit) < 0.005;

    GeneralAccounting::where('id', $transactionId)->update([
        'gen_acct_amount' => $sumDebit,
        'sum_debit'       => $sumDebit,
        'sum_credit'      => $sumCredit,
        'is_balanced'     => $balanced,
    ]);

    return ['debit'=>$sumDebit,'credit'=>$sumCredit,'balanced'=>$balanced];
}


}
