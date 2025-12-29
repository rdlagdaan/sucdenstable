<?php

namespace App\Http\Controllers;

use App\Models\CashPurchase;
use App\Models\CashPurchaseDetail;
use App\Models\AccountCode;
use App\Models\VendorList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;



class MyPurchaseVoucherPDF extends \TCPDF {
    public $createdDate;
    public $createdTime;

    public function setCreatedMeta($date, $time) {
        $this->createdDate = $date;
        $this->createdTime = $time;
    }

    public function Header() {
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

    public function Footer() {
        $this->SetY(-50);
        $this->SetFont('helvetica','I',8);
        $currentDate = date('M d, Y');
        $currentTime = date('h:i:sa');

        $html = '
        <table border="0"><tr>
          <td width="70%">
            <table border="1" cellpadding="5"><tr>
              <td><font size="8">Prepared:<br><br><br><br><br>administrator</font></td>
              <td><font size="8">Accted by:<br><br><br><br><br></font></td>
              <td><font size="8">Checked:<br><br><br><br><br></font></td>
              <td><font size="8">Approved:<br><br><br><br><br></font></td>
              <td><font size="8">Noted by:<br><br><br><br><br></font></td>
              <td><font size="8">Posted by:<br><br><br><br><br></font></td>
            </tr></table>
          </td>
          <td width="5%"></td>
          <td width="25%">
            <table border="1" cellpadding="5">
              <tr><td align="center"><font size="8">Received from SUCDEN PHILIPPINES, INC.</font><br><br></td></tr>
              <tr><td align="center"><font size="8">Signature Over Printed Name</font></td></tr>
            </table>
          </td>
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
            <td><font size="8">'.$this->createdDate.'</font></td>
            <td><font size="8">'.$this->createdTime.'</font></td>
            <td></td>
          </tr>
        </table>';
        $this->writeHTML($html, true, false, false, false, '');
    }
}

class PurchaseJournalController extends Controller
{

    // ----------------------------
    // Cash Receipts baseline helpers
    // ----------------------------
    private function isCancelledFlag($flag): bool
    {
        $v = strtolower((string)($flag ?? ''));
        return $v === 'y' || $v === 'c' || $v === 'd';
    }

    private function requireNotCancelledOrDeleted(int $id, ?int $companyId = null): void
    {
        $q = CashPurchase::where('id', $id);
        if ($companyId) $q->where('company_id', $companyId);

        $flag = $q->value('is_cancel');
        if ($this->isCancelledFlag($flag)) {
            abort(422, 'Transaction is cancelled/deleted and cannot be modified.');
        }
    }

private function requireApprovedEdit(int $transactionId, int $companyId): void
{
    $row = DB::table('approvals')
        ->where('module', 'purchase_journal')              // ✅ MUST match frontend MODULE
        ->where('record_id', $transactionId)
        ->where('company_id', $companyId)
        ->whereRaw('LOWER(action) = ?', ['edit'])          // ✅ case-insensitive like ApprovalController
        ->whereRaw('LOWER(status) = ?', ['approved'])
        ->whereNull('consumed_at')
        ->whereNotNull('expires_at')
        ->where('expires_at', '>', now())
        ->orderByDesc('id')
        ->first();

    if (!$row) {
        abort(403, 'No active edit approval window. Please request edit again.');
    }
}







    // 1) Generate next CP number (incremental)
    public function generateCpNumber(Request $req)
    {
        $companyId = $req->query('company_id');

        $last = CashPurchase::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->orderBy('cp_no','desc')
            ->value('cp_no');

        $base = is_numeric($last) ? (int)$last : 200000;
        return response()->json(['cp_no' => (string)($base + 1)]);
    }

    // 2) Create main header

public function storeMain(Request $req)
{
    $vendId = $req->input('vend_id', $req->input('vend_id'));

    $data = $req->validate([
        'cp_no'          => ['nullable','string','max:25'],
        'purchase_date'  => ['required','date'],
        'explanation'    => ['required','string','max:1000'],
        'sugar_type'     => ['nullable','string','max:10'],
        'crop_year'      => ['nullable','string','max:10'],
        'mill_id'        => ['nullable','string','max:25'],
        'booking_no'     => ['nullable','string','max:25'],
        'company_id'     => ['required','integer'],
        'workstation_id' => ['nullable','string','max:25'],
        'user_id'        => ['nullable','integer'],
    ]);

    if (!$vendId) {
        return response()->json(['message' => 'Vendor is required.'], 422);
    }
    $data['vend_id'] = $vendId;

    if (empty($data['cp_no'])) {
        $next = $this->generateCpNumber(new Request(['company_id' => $data['company_id']]));
        $data['cp_no'] = $next->getData()->cp_no ?? null;
    }

    $data['purchase_amount'] = 0;
    $data['is_cancel']       = 'N';   // <-- UPPERCASE

    $main = CashPurchase::create($data);

    return response()->json(['id' => $main->id, 'cp_no' => $main->cp_no]);
}


    // 2b) Update main header (approval protected)
    public function updateMain(Request $req)
    {
        $data = $req->validate([
            'id'            => ['required','integer','exists:cash_purchase,id'],
            'vend_id'        => ['required','string','max:50'],
            'purchase_date'  => ['required','date'],
            'explanation' => ['nullable','string','max:1000'],
            'sugar_type'     => ['nullable','string','max:10'],
            'crop_year'      => ['nullable','string','max:10'],
            'mill_id'        => ['nullable','string','max:25'],
            'booking_no'     => ['nullable','string','max:25'],
            'company_id'     => ['required','integer'],
        ]);

        $tx = CashPurchase::findOrFail($data['id']);

        $this->requireNotCancelledOrDeleted((int)$tx->id, (int)$data['company_id']);
        $this->requireApprovedEdit((int)$tx->id, (int)$data['company_id']);

        $tx->update([
            'vend_id'       => $data['vend_id'],
            'purchase_date' => $data['purchase_date'],
            'explanation'   => $data['explanation'],
            'sugar_type'    => $data['sugar_type'] ?? null,
            'crop_year'     => $data['crop_year'] ?? null,
            'mill_id'       => $data['mill_id'] ?? null,
            'booking_no'    => $data['booking_no'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }


    // 3) Insert a detail row (prevents duplicate acct_code per transaction)
    public function saveDetail(Request $req)
    {
        $payload = $req->validate([
            'transaction_id' => ['required','integer','exists:cash_purchase,id'],
            'acct_code'      => ['required','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
            'company_id'     => ['required','integer'],
            'user_id'        => ['nullable','integer'],
            'workstation_id' => ['nullable','string','max:25'],
        ]);

        $this->requireNotCancelledOrDeleted((int)$payload['transaction_id'], (int)($payload['company_id'] ?? 0));
        $this->requireApprovedEdit((int)$payload['transaction_id'], (int)($payload['company_id'] ?? 0));



        $debit  = (float)($payload['debit'] ?? 0);
        $credit = (float)($payload['credit'] ?? 0);
        if ($debit > 0 && $credit > 0) {
            return response()->json(['message' => 'Provide either debit or credit, not both.'], 422);
        }
        if ($debit <= 0 && $credit <= 0) {
            return response()->json(['message' => 'Debit or credit is required.'], 422);
        }

        $exists = AccountCode::where('acct_code', $payload['acct_code'])
            ->where('active_flag', 1)
            ->exists();
        if (!$exists) return response()->json(['message' => 'Invalid or inactive account.'], 422);

        $dup = CashPurchaseDetail::where('transaction_id',$payload['transaction_id'])
            ->where('acct_code',$payload['acct_code'])->exists();
        if ($dup) return response()->json(['message' => 'Duplicate account code for this transaction.'], 422);

        $detail = CashPurchaseDetail::create($payload);

        $totals = $this->recalcTotals($payload['transaction_id']);

        return response()->json([
            'detail_id' => $detail->id,
            'totals'    => $totals,
        ]);
    }

    // 4) Update a detail row
    public function updateDetail(Request $req)
    {
        $payload = $req->validate([
            'id'             => ['required','integer','exists:cash_purchase_details,id'],
            'transaction_id' => ['required','integer','exists:cash_purchase,id'],
            'acct_code'      => ['nullable','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
        ]);

        $tx = CashPurchase::find($payload['transaction_id']);
        $companyId = $tx ? (int)($tx->company_id ?? 0) : 0;

        $this->requireNotCancelledOrDeleted((int)$payload['transaction_id'], $companyId);
        $this->requireApprovedEdit((int)$payload['transaction_id'], $companyId);


        $detail = CashPurchaseDetail::find($payload['id']);
        if (!$detail) return response()->json(['message' => 'Detail not found.'], 404);

        $apply = [];
        if (isset($payload['acct_code'])) $apply['acct_code'] = $payload['acct_code'];
        if (isset($payload['debit']))     $apply['debit']     = $payload['debit'];
        if (isset($payload['credit']))    $apply['credit']    = $payload['credit'];

        if (array_key_exists('debit',$apply) && array_key_exists('credit',$apply)) {
            $d = (float)$apply['debit']; $c = (float)$apply['credit'];
            if ($d > 0 && $c > 0) return response()->json(['message'=>'Provide either debit OR credit.'], 422);
        }

        $detail->update($apply);

        $totals = $this->recalcTotals($payload['transaction_id']);
        return response()->json(['ok'=>true,'totals'=>$totals]);
    }

    // 5) Delete a detail
    public function deleteDetail(Request $req)
    {
        $payload = $req->validate([
            'id'             => ['required','integer','exists:cash_purchase_details,id'],
            'transaction_id' => ['required','integer','exists:cash_purchase,id'],
        ]);

        $tx = CashPurchase::find($payload['transaction_id']);
        $companyId = $tx ? (int)($tx->company_id ?? 0) : 0;

        $this->requireNotCancelledOrDeleted((int)$payload['transaction_id'], $companyId);
        $this->requireApprovedEdit((int)$payload['transaction_id'], $companyId);


        CashPurchaseDetail::where('id',$payload['id'])->delete();
        $totals = $this->recalcTotals($payload['transaction_id']);
        return response()->json(['ok'=>true,'totals'=>$totals]);
    }

    // Optional main delete
    public function destroy($id)
    {
        $main = CashPurchase::find($id);
        if (!$main) return response()->json(['message'=>'Not found'], 404);
        CashPurchaseDetail::where('transaction_id',$id)->delete();
        $main->delete();
        return response()->json(['ok'=>true]);
    }

    // 6) Show main+details (Search Transaction)
    public function show($id, Request $req)
    {
        $companyId = $req->query('company_id');

        $main = CashPurchase::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->findOrFail($id);

        $details = CashPurchaseDetail::where('transaction_id',$main->id)
            ->leftJoin('account_code','cash_purchase_details.acct_code','=','account_code.acct_code')
            ->orderBy('cash_purchase_details.id')
            ->get([
                'cash_purchase_details.id',
                'cash_purchase_details.transaction_id',
                'cash_purchase_details.acct_code',
                DB::raw('COALESCE(account_code.acct_desc, \'\') as acct_desc'),
                'cash_purchase_details.debit',
                'cash_purchase_details.credit',
            ]);

        return response()->json(['main'=>$main,'details'=>$details]);
    }

    // 7) Search list for combobox
public function list(Request $req)
{
    $companyId = $req->query('company_id');
    $q  = trim((string) $req->query('q', ''));
    $qq = strtolower($q);

    $rows = CashPurchase::from('cash_purchase as p')
        ->whereRaw("LOWER(COALESCE(p.is_cancel,'')) != 'd'")
        ->when($companyId, fn ($qr) => $qr->where('p.company_id', $companyId))
        ->leftJoin('vendor_list as v', function ($j) use ($companyId) {
            // ✅ correct join: vendor_list.vend_code ↔ cash_purchase.vend_id
            $j->on('v.vend_code', '=', 'p.vend_id');
            if ($companyId) {
                $j->where('v.company_id', $companyId);
            }
        })
->when($q !== '', function ($qr) use ($qq) {
    $qr->where(function ($w) use ($qq) {
        // existing
        $w->whereRaw('LOWER(COALESCE(p.cp_no, \'\')) LIKE ?',      ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(p.vend_id, \'\')) LIKE ?',   ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(v.vend_name, \'\')) LIKE ?', ["%{$qq}%"])

          // NEW: allow searching by ID, date, amount, booking, mill, sugar/crop, invoice
          ->orWhereRaw('CAST(p.id AS TEXT) LIKE ?',                 ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(CAST(p.purchase_date AS TEXT), \'\')) LIKE ?', ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(CAST(p.purchase_amount AS TEXT), \'\')) LIKE ?', ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(p.booking_no, \'\')) LIKE ?', ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(p.mill_id, \'\')) LIKE ?',    ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(p.sugar_type, \'\')) LIKE ?', ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(p.crop_year, \'\')) LIKE ?',  ["%{$qq}%"])
          ->orWhereRaw('LOWER(COALESCE(p.rr_no, \'\')) LIKE ?', ["%{$qq}%"]);
    });
})

        ->orderByDesc('p.purchase_date')
        ->orderByDesc('p.id')
        ->limit(50)
        ->get([
            'p.id',
            'p.cp_no',
            'p.vend_id',
            'p.purchase_date',
            'p.purchase_amount',
            'p.rr_no',                // ✅ ADD THIS
            'p.is_cancel',
            'p.sugar_type',
            'p.crop_year',
            'p.mill_id',
            'p.booking_no',
            DB::raw("COALESCE(v.vend_name,'') as vend_name"),
        ]);

    return response()->json($rows);
}


    // 8) Dropdowns
    public function vendors(Request $req)
    {
        $companyId = $req->query('company_id');
        $rows = VendorList::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->orderBy('vend_name')
            ->get(['vend_code','vend_name']);

        // Shape specifically for your DropdownWithHeaders (code/label/description)
        $items = $rows->map(fn($r)=>[
            'code'        => $r->vend_code,
            'label'       => $r->vend_code,
            'description' => $r->vend_name,
            'vend_code'   => $r->vend_code,
            'vend_name'   => $r->vend_name,
        ]);
        return response()->json($items);
    }


public function mills(Request $req)
{
    $companyId = $req->query('company_id');

    $rows = DB::table('mill_list')
        ->when($companyId, fn($q) => $q->where('company_id', $companyId))
        ->orderBy('mill_id')
        ->get(['mill_id','mill_name','prefix']);

    // Shape for DropdownWithHeaders: code/label/description
    $items = $rows->map(fn($r) => [
        'code'        => (string) $r->mill_id,
        'label'       => (string) $r->mill_id,
        'description' => (string) ($r->mill_name ?? ''),
        'mill_id'     => (string) $r->mill_id,
        'mill_name'   => (string) ($r->mill_name ?? ''),
        'prefix'      => (string) ($r->prefix ?? ''),
    ]);

    return response()->json($items);
}

// 2c) Update main header (NO approval) — for Save Main button
public function updateMainNoApproval(Request $req)
{
    $data = $req->validate([
        'id'            => ['required','integer','exists:cash_purchase,id'],
        'company_id'    => ['required','integer'],
        'purchase_date' => ['required','date'],
        'explanation'   => ['nullable','string','max:1000'],

        // optional: allow these if you want Save Main to update them too
        'vend_id'       => ['nullable','string','max:50'],
        'sugar_type'    => ['nullable','string','max:10'],
        'crop_year'     => ['nullable','string','max:10'],
        'mill_id'       => ['nullable','string','max:25'],
        'booking_no'    => ['nullable','string','max:25'],
    ]);

    $tx = CashPurchase::findOrFail($data['id']);

    // still block cancelled/deleted
    $this->requireNotCancelledOrDeleted((int)$tx->id, (int)$data['company_id']);

    // IMPORTANT: no requireApprovedEdit() here

    $tx->update([
        // If you want only Date + Explanation to be editable without approval,
        // just keep these two lines and remove the others.
        'purchase_date' => $data['purchase_date'],
        'explanation'   => $data['explanation'],

        'vend_id'    => $data['vend_id']    ?? $tx->vend_id,
        'sugar_type' => $data['sugar_type'] ?? $tx->sugar_type,
        'crop_year'  => $data['crop_year']  ?? $tx->crop_year,
        'mill_id'    => $data['mill_id']    ?? $tx->mill_id,
        'booking_no' => $data['booking_no'] ?? $tx->booking_no,
    ]);

    return response()->json(['ok' => true]);
}



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
            ->limit(200)
            ->get(['acct_code','acct_desc']);

        return response()->json($rows);
    }

    // 9) Cancel / Uncancel
public function updateCancel(Request $req)
{
    $data = $req->validate([
        'id'   => ['required','integer','exists:cash_purchase,id'],
        'flag' => ['required','in:0,1'],
    ]);
    $val = $data['flag'] === '1' ? 'Y' : 'N';   // <-- UPPERCASE
    CashPurchase::where('id',$data['id'])->update(['is_cancel' => $val]);
    return response()->json(['ok'=>true,'is_cancel'=>$val]);
}

    // 10) Print/Download PDF (unchanged)
public function formPdf(Request $request, $id)
{
    $header = DB::table('cash_purchase as cp')
        ->leftJoin('vendor_list as v', 'cp.vend_id', '=', 'v.vend_code') // correct mapping
        ->select(
            'cp.id','cp.cp_no','cp.vend_id','cp.purchase_amount',
            'cp.explanation','cp.is_cancel',
            DB::raw("to_char(cp.purchase_date, 'MM/DD/YYYY') as purchase_date"),
            'v.vend_name','cp.workstation_id','cp.user_id','cp.created_at'
        )
        ->where('cp.id', $id)
        ->first();

    if (!$header || strtoupper((string)$header->is_cancel) === 'Y') {
        abort(404, 'Purchase Voucher not found or cancelled');
    }

    $details = DB::table('cash_purchase_details as d')
        ->join('account_code as a', 'd.acct_code', '=', 'a.acct_code')
        ->where('d.transaction_id', $id)
        ->orderBy('d.workstation_id','desc')
        ->orderBy('d.credit','desc')
        ->select('d.acct_code','a.acct_desc','d.debit','d.credit')
        ->get();

    $totalDebit  = (float)$details->sum('debit');
    $totalCredit = (float)$details->sum('credit');

    // If unbalanced, return an HTML explanation (consistent with other modules)
    if (abs($totalDebit - $totalCredit) > 0.005) {
        $html = sprintf(
            '<!doctype html><meta charset="utf-8">
             <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
             <h2>Cannot print Purchase Voucher</h2>
             <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before printing.</p>
             <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
            number_format($totalDebit, 2),
            number_format($totalCredit, 2)
        );
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    $pdf = new MyPurchaseVoucherPDF('P','mm','LETTER',true,'UTF-8',false);
    $pdf->setPrintHeader(true);
    $pdf->SetHeaderMargin(8);
    $pdf->SetMargins(15,30,15);
    $pdf->AddPage();
    $pdf->SetFont('helvetica','',7);

    $pdf->setCreatedMeta(
        \Carbon\Carbon::parse($header->created_at)->format('M d, Y'),
        \Carbon\Carbon::parse($header->created_at)->format('h:i:sa')
    );

    $formattedDebit  = number_format($totalDebit, 2);
    $formattedCredit = number_format($totalCredit, 2);

    $tbl = <<<EOD
<br><br>
<table border="0" cellpadding="1" cellspacing="0" nobr="true" width="100%">
<tr>
  <td width="15%"></td>
  <td width="30%"></td>
  <td width="20%"></td>
  <td width="40%" colspan="2"><div><font size="16"><b>PURCHASE VOUCHER</b></font></div></td>
</tr>
<tr><td colspan="5"></td></tr>
<tr>
  <td width="65%"></td>
  <td width="20%" align="left"><font size="10"><b>RR Number:</b></font></td>
  <td width="15%" align="left"><font size="14"><b><u>{$header->cp_no}</u></b></font></td>
</tr>
<tr>
  <td width="65%"></td>
  <td width="20%" align="left"><font size="10"><b>Receipt Date:</b></font></td>
  <td width="15%"><font size="10"><u>{$header->purchase_date}</u></font></td>
</tr>
<tr>
  <td width="15%"><font size="10"><b>VENDOR:</b></font></td>
  <td width="80%" colspan="4"><font size="10"><u>{$header->vend_name}</u></font></td>
</tr>
</table>

<table><tr><td><br><br></td></tr></table>
<table border="1" cellspacing="0" cellpadding="5">
  <tr><td width="100%" align="left"><font size="10"><b>EXPLANATION</b></font></td></tr>
  <tr><td height="50"><font size="10">{$header->explanation}</font></td></tr>
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
        $tbl .= <<<EOD
  <tr>
    <td align="left"><font size="10">{$d->acct_code}</font></td>
    <td align="left"><font size="10">{$d->acct_desc}</font></td>
    <td align="right"><font size="10">{$debit}</font></td>
    <td align="right"><font size="10">{$credit}</font></td>
  </tr>
EOD;
    }

    $tbl .= <<<EOD
  <tr>
    <td align="left"></td>
    <td align="left"><font size="10">TOTAL</font></td>
    <td align="right"><font size="10">$formattedDebit</font></td>
    <td align="right"><font size="10">$formattedCredit</font></td>
  </tr>
</table>
EOD;

    $pdf->writeHTML($tbl, true, false, false, false, '');

    $pdfContent = $pdf->Output('purchaseVoucher.pdf', 'S');

    return response($pdfContent, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="PurchaseVoucher_'.$header->cp_no.'.pdf"');
}


// ✅ ADD THIS METHOD inside PurchaseJournalController
public function checkPdf(Request $request, $id)
{
    $companyId = $request->query('company_id');

    // --- Header info (scope by company, join vendor name) ---
    $header = DB::table('cash_purchase as cp')
        ->leftJoin('vendor_list as v', function ($j) use ($companyId) {
            $j->on('cp.vend_id', '=', 'v.vend_code');
            if ($companyId) {
                $j->where('v.company_id', '=', $companyId);
            }
        })
        ->select(
            'cp.id',
            'cp.cp_no',
            'cp.vend_id',
            'cp.is_cancel',
            DB::raw("cp.purchase_date as raw_purchase_date"),
            DB::raw("to_char(cp.purchase_date, 'MM/DD/YYYY') as purchase_date"),
            DB::raw("COALESCE(v.vend_name,'') as vend_name")
        )
        ->where('cp.id', $id)
        ->when($companyId, fn ($q) => $q->where('cp.company_id', $companyId))
        ->first();

    if (
        !$header ||
        $this->isCancelledFlag($header->is_cancel) // y/c/d (case-insensitive via your helper)
    ) {
        abort(404, 'Purchase Voucher not found or cancelled');
    }

    // --- Details (ensure balanced) ---
    $details = DB::table('cash_purchase_details as d')
        ->where('d.transaction_id', $id)
        ->when($companyId, fn ($q) => $q->where('d.company_id', $companyId))
        ->get(['d.debit', 'd.credit']);

    $totalDebit  = (float) $details->sum('debit');
    $totalCredit = (float) $details->sum('credit');

    if (abs($totalDebit - $totalCredit) > 0.005) {
        $html = sprintf(
            '<!doctype html><meta charset="utf-8">
            <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
            <h2>Cannot print Check</h2>
            <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before printing the check.</p>
            <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
            number_format($totalDebit, 2),
            number_format($totalCredit, 2)
        );
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // --- Amount in figures + derived words ---
    $amountNumeric    = round($totalDebit, 2);
    $amountNumericStr = number_format($amountNumeric, 2);

    // ✅ Always derive from figure
    $amountWords = $this->numberToPesoWords($amountNumeric);

    // Payee = vendor name (fallback to vend_id)
    $payeeName = trim((string)($header->vend_name ?? ''));
    if ($payeeName === '') {
        $payeeName = (string)($header->vend_id ?? '');
    }

    // Use purchase_date as check date
    $date = $header->raw_purchase_date
        ? \Carbon\Carbon::parse($header->raw_purchase_date)
        : \Carbon\Carbon::now();

    $mm   = $date->format('m');
    $dd   = $date->format('d');
    $yyyy = $date->format('Y');

    // --- TCPDF check size (LANDSCAPE) ---
    $checkWidthMm  = 8.0 * 25.4;  // 203.2 mm
    $checkHeightMm = 3.0 * 25.4;  // 76.2 mm

    $pdf = new \TCPDF('L', 'mm', [$checkWidthMm, $checkHeightMm], true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(false, 0);

    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);

    // 1) Date (MM  DD  YYYY) – upper-right
    $pdf->SetXY(140, 10);
    $pdf->Cell(0, 5, $mm . '   ' . $dd . '   ' . $yyyy, 0, 1, 'L');

    // 2) Payee
    $pdf->SetXY(20, 25);
    $pdf->Cell(120, 6, $payeeName, 0, 1, 'L');

    // 3) Amount in figures
    $pdf->SetXY(145, 25);
    $pdf->Cell(50, 6, $amountNumericStr, 0, 1, 'R');

    // 4) Amount in words
    $pdf->SetXY(20, 35);
    $pdf->MultiCell(160, 6, $amountWords, 0, 'L', false, 1);

    $fileName   = 'check_' . (($header->cp_no ?? '') !== '' ? $header->cp_no : $id) . '.pdf';
    $pdfContent = $pdf->Output($fileName, 'S');

    return response($pdfContent, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="'.$fileName.'"');
}



// ✅ ADD THESE HELPERS inside PurchaseJournalController (near the bottom, like Sales)
protected function numberToPesoWords(float $amount): string
{
    $amount = round($amount, 2);
    $integerPart = (int) floor($amount);
    $cents = (int) round(($amount - $integerPart) * 100);

    $words = ($integerPart === 0) ? 'zero' : $this->numberToWords($integerPart);
    $words = ucfirst($words) . ' pesos';

    if ($cents > 0) {
        $words .= ' and ' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT) . '/100';
    } else {
        $words .= ' only';
    }

    return $words;
}

protected function numberToWords(int $num): string
{
    $ones = ['', 'one','two','three','four','five','six','seven','eight','nine','ten',
             'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    $tens = ['', '', 'twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    $scales = ['', 'thousand', 'million', 'billion'];

    if ($num === 0) return 'zero';

    $words = [];
    $scaleIndex = 0;

    while ($num > 0) {
        $chunk = $num % 1000;
        if ($chunk > 0) {
            $chunkWords = [];

            $hundreds  = intdiv($chunk, 100);
            $remainder = $chunk % 100;

            if ($hundreds > 0) $chunkWords[] = $ones[$hundreds] . ' hundred';

            if ($remainder > 0) {
                if ($remainder < 20) {
                    $chunkWords[] = $ones[$remainder];
                } else {
                    $t = intdiv($remainder, 10);
                    $u = $remainder % 10;
                    $chunkWords[] = $tens[$t] . ($u ? '-' . $ones[$u] : '');
                }
            }

            if ($scales[$scaleIndex] !== '') $chunkWords[] = $scales[$scaleIndex];

            array_unshift($words, implode(' ', $chunkWords));
        }

        $num = intdiv($num, 1000);
        $scaleIndex++;
    }

    return implode(' ', $words);
}



public function formExcel(Request $request, $id)
{
    // 1) Header (same join logic as PDF — note vend_id ↔ vend_code)
    $header = DB::table('cash_purchase as cp')
        ->leftJoin('vendor_list as v', 'cp.vend_id', '=', 'v.vend_code')
        ->select(
            'cp.id','cp.cp_no','cp.vend_id','cp.purchase_amount',
            'cp.explanation','cp.is_cancel',
            DB::raw("to_char(cp.purchase_date, 'MM/DD/YYYY') as purchase_date"),
            'v.vend_name','cp.created_at'
        )
        ->where('cp.id', $id)
        ->first();

    if (!$header) {
        return response()->json(['message' => 'Purchase voucher not found'], 404);
    }
    // Your app uses uppercase flags ('Y'/'N'); avoid the old 'y' lowercase check
    if (strtoupper((string)($header->is_cancel ?? 'N')) === 'Y') {
        return response()->json(['message' => 'Cancelled voucher cannot be exported'], 422);
    }

    // 2) Details
    $details = DB::table('cash_purchase_details as d')
        ->join('account_code as a', 'd.acct_code', '=', 'a.acct_code')
        ->where('d.transaction_id', $id)
        ->orderBy('d.id')
        ->select('d.acct_code','a.acct_desc','d.debit','d.credit')
        ->get();

    $totalDebit  = (float) $details->sum('debit');
    $totalCredit = (float) $details->sum('credit');

    if (abs($totalDebit - $totalCredit) > 0.005) {
        // Return a *proper* error so the frontend toast shows it
        return response()->json([
            'message' => sprintf(
                'Cannot export: details are not balanced. Debit=%0.2f, Credit=%0.2f',
                $totalDebit, $totalCredit
            )
        ], 422);
    }

    // 3) Build spreadsheet
    $s = new Spreadsheet();
    $sheet = $s->getActiveSheet();
    $row = 1;

    // Title
    $sheet->setCellValue("A{$row}", 'PURCHASE VOUCHER');
    $sheet->mergeCells("A{$row}:D{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
    $row += 2;

    // Header block
    $sheet->setCellValue("A{$row}", 'RR Number:');
    $sheet->setCellValue("B{$row}", (string)$header->cp_no);
    $sheet->setCellValue("C{$row}", 'Receipt Date:');
    $sheet->setCellValue("D{$row}", (string)$header->purchase_date);
    $row++;

    $sheet->setCellValue("A{$row}", 'Vendor:');
    $sheet->setCellValue("B{$row}", (string)($header->vend_name ?? $header->vend_id));
    $row++;

    $sheet->setCellValue("A{$row}", 'Explanation:');
    $sheet->setCellValue("B{$row}", (string)$header->explanation);
    $sheet->mergeCells("B{$row}:D{$row}");
    $row += 2;

    // Table header
    $sheet->setCellValue("A{$row}", 'ACCOUNT');
    $sheet->setCellValue("B{$row}", 'GL ACCOUNT');
    $sheet->setCellValue("C{$row}", 'DEBIT');
    $sheet->setCellValue("D{$row}", 'CREDIT');
    $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
    $row++;

    // Rows
    foreach ($details as $d) {
        $sheet->setCellValue("A{$row}", (string)$d->acct_code);
        $sheet->setCellValue("B{$row}", (string)$d->acct_desc);
        if ($d->debit !== null)  $sheet->setCellValue("C{$row}", (float)$d->debit);
        if ($d->credit !== null) $sheet->setCellValue("D{$row}", (float)$d->credit);
        $row++;
    }

    // Totals
    $sheet->setCellValue("B{$row}", 'TOTAL');
    $sheet->setCellValue("C{$row}", $totalDebit);
    $sheet->setCellValue("D{$row}", $totalCredit);
    $sheet->getStyle("B{$row}:D{$row}")->getFont()->setBold(true);

    // Formats & sizing
    $sheet->getStyle("C1:D{$row}")
        ->getNumberFormat()->setFormatCode('#,##0.00');
    foreach (['A'=>18,'B'=>48,'C'=>16,'D'=>16] as $col=>$w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

    // 4) Stream as XLSX
    $filename = 'PurchaseVoucher_' . preg_replace('/[^\w\-]/','', (string)$header->cp_no) . '.xlsx';
    $writer = new XlsxWriter($s);

    $response = new StreamedResponse(function() use ($writer) {
        $writer->save('php://output');
    });

    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
    $response->headers->set('Cache-Control', 'max-age=0');

    return $response;
}


    // ---- helpers ----
    protected function recalcTotals(int $transactionId): array
    {
        $tot = CashPurchaseDetail::where('transaction_id',$transactionId)
            ->selectRaw('COALESCE(SUM(debit),0) as sum_debit, COALESCE(SUM(credit),0) as sum_credit')
            ->first();

        $sumDebit  = round((float)$tot->sum_debit, 2);
        $sumCredit = round((float)$tot->sum_credit, 2);
        $balanced  = abs($sumDebit - $sumCredit) < 0.005;

        CashPurchase::where('id',$transactionId)->update([
            'purchase_amount' => $sumDebit,
            'sum_debit'       => $sumDebit,
            'sum_credit'      => $sumCredit,
            'is_balanced'     => $balanced,
        ]);

        return ['debit'=>$sumDebit,'credit'=>$sumCredit,'balanced'=>$balanced];
    }

    public function unbalancedExists(Request $req)
    {
        $companyId = $req->query('company_id');
        $exists = CashPurchase::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->where('is_balanced', false)
            ->exists();

        return response()->json(['exists' => $exists]);
    }

    public function unbalanced(Request $req)
    {
        $companyId = $req->query('company_id');
        $limit = (int) $req->query('limit', 20);

        $rows = CashPurchase::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->where('is_balanced', false)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get([
                'id',
                'cp_no',
                'vend_id',
                DB::raw('COALESCE(sum_debit,0)  as sum_debit'),
                DB::raw('COALESCE(sum_credit,0) as sum_credit'),
            ]);

        return response()->json(['items' => $rows]);
    }
}
