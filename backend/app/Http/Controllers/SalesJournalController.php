<?php

namespace App\Http\Controllers;

use App\Models\CashSales;
use App\Models\CashSalesDetail;
use App\Models\AccountCode;
use App\Models\CustomerList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MySalesVoucherPDF extends \TCPDF {

    public $companyId = null;

    public function setCompanyId(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public $salesDate;
    public $salesTime;

    public $preparedByInitials = '';

    public function setPreparedByInitials(string $initials): void
    {
        $this->preparedByInitials = $initials;
    }

    public function setDataSalesDate($date) { $this->salesDate = $date; }
    public function setDataSalesTime($time) { $this->salesTime = $time; }

    public function Header()
    {
        $companyId = (int)($this->companyId ?? 0);

        // =========================
        // COMPANY 2 — AMEROP
        // =========================
        if ($companyId === 2) {

            // NOTE: your screenshot shows logos in /public (NOT /public/images)
            $logoCandidates = [
                public_path('ameropLogo.jpg'),
                public_path('ameropLogo.png'),
            ];

            foreach ($logoCandidates as $logo) {
                if ($logo && is_file($logo)) {
                    // smaller printed-like logo
                    $this->Image($logo, 15, 12, 22, '', '', '', 'T', false, 300);
                    break;
                }
            }

            // Text beside the logo (same style you wanted)
            $this->SetTextColor(40, 85, 160); // amerop-ish blue
            $this->SetFont('helvetica', 'B', 12);
            $this->Text(40, 14, 'AMEROP');

            $this->SetFont('helvetica', '', 8);
            $this->Text(40, 19, 'PHILIPPINES, INC.');

            $this->SetTextColor(0, 0, 0);
            return;
        }

        // =========================
        // DEFAULT — SUCDEN (company_id 1)
        // =========================
        $candidates = [
            public_path('SucdenLogo.jpg'),
            public_path('SucdenLogo.png'),
            public_path('sucdenLogo.jpg'),
            public_path('sucdenLogo.png'),
            public_path('images/sucdenLogo.jpg'),
            public_path('images/sucdenLogo.png'),
        ];

        foreach ($candidates as $img) {
            if ($img && is_file($img)) {
                $this->Image($img, 15, 10, 50, '', '', '', 'T', false, 300);
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
        // ✅ Company label in footer (company_id 1 vs 2)
        $companyId = (int)($this->companyId ?? 0);
        $receivedFrom = ($companyId === 2)
            ? 'AMEROP PHILIPPINES, INC.'
            : 'SUCDEN PHILIPPINES, INC.';

        $html = '
        <table border="0"><tr>
          <td width="70%">
            <table border="1" cellpadding="5"><tr>

<td width="16%">
  <table border="0" cellpadding="0" cellspacing="0" width="100%" height="65">
    <tr>
      <td valign="top" align="left"><font size="8">Prepared:</font></td>
    </tr>

    <tr>
      <td height="42"></td>
    </tr>

    <tr>
      <td height="12" valign="bottom" align="left" style="padding-left:4px; padding-bottom:0px; white-space:nowrap;">
        <font size="7"><b>'.htmlspecialchars((string)$this->preparedByInitials).'</b></font>
      </td>
    </tr>
  </table>
</td>

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
              <tr><td align="center"><font size="8">Received from '.$receivedFrom.'</font><br><br></td></tr>
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
            <td width="15%"><font size="8">'.$this->salesDate.'</font></td>
            <td width="15%"><font size="8">'.$this->salesTime.'</font></td>
            <td></td>
          </tr>
        </table>';

        $this->writeHTML($html, true, false, false, false, '');
    }
}


class SalesJournalController extends Controller
{
    
protected function userInitials(?int $userId): string
{
    if (empty($userId)) return '';

    if (\Illuminate\Support\Facades\Schema::hasTable('users_employees')) {
        $u = (string) DB::table('users_employees')
            ->where('id', (int)$userId)
            ->value('username');

        $u = strtoupper(trim((string)$u));
        if ($u !== '') return $u;
    }

    return 'U' . (int)$userId;
}
    
    
    // 1) Generate next CS number (incremental)
    public function generateCsNumber(Request $req)
    {
        $companyId = $req->query('company_id');

        $last = CashSales::when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderBy('cs_no','desc')
            ->value('cs_no');

        $base = is_numeric($last) ? (int)$last : 100000;
        return response()->json(['cs_no' => (string)($base + 1)]);
    }

    // 2) Create main header
    public function storeMain(Request $req)
    {
        $data = $req->validate([
            'cs_no'       => ['nullable','string','max:25'],
            'cust_id'     => ['required','string','max:50'],
            'sales_date'  => ['required','date'],
            'explanation' => ['required','string','max:1000'],
            'si_no'       => ['required','string','max:25'],
            'company_id'  => ['required','integer'],
            'workstation_id' => ['nullable','string','max:25'],
            'user_id'     => ['nullable','integer'],
        ]);

        // If frontend didn’t generate, we’ll do it here
        if (empty($data['cs_no'])) {
            $next = $this->generateCsNumber(new Request(['company_id' => $data['company_id']]));
            $data['cs_no'] = $next->getData()->cs_no ?? null;
        }

        $data['sales_amount'] = 0;
        $data['is_cancel'] = 'n';

        $main = CashSales::create($data);

        return response()->json([
            'id'    => $main->id,
            'cs_no' => $main->cs_no,
        ]);
    }


// 🔓 Save Main (NO approval) — header only
public function updateMainNoApproval(Request $req)
{
    abort(403, 'Direct header editing without approval is disabled for Sales Journal.');
}


    // 2b) *** NEW: Update main header (requires approval) ***
public function updateMain(Request $req)
{
    $data = $req->validate([
        'id'          => ['required','integer','exists:cash_sales,id'],
        'cust_id'     => ['required','string','max:50'],
        'sales_date'  => ['required','date'],
        'explanation' => ['required','string','max:1000'],
        'si_no'       => ['required','string','max:25'],
    ]);

    $main = CashSales::findOrFail((int) $data['id']);

    if (in_array($main->is_cancel, ['c', 'd', 'y'], true)) {
        abort(403, 'Cancelled or deleted transaction cannot be modified.');
    }

    // Before export: editable directly
    // After export: requires approved edit request
    if (!empty($main->exported_at)) {
        $this->requireEditApproval((int) $main->id);
    }

    $main->cust_id     = $data['cust_id'];
    $main->sales_date  = $data['sales_date'];
    $main->explanation = $data['explanation'];
    $main->si_no       = $data['si_no'];
    $main->save();

    return response()->json([
        'ok'          => true,
        'id'          => $main->id,
        'cust_id'     => $main->cust_id,
        'sales_date'  => $main->sales_date,
        'explanation' => $main->explanation,
        'si_no'       => $main->si_no,
    ]);
}


    // 3) Create a new detail row
public function saveDetail(Request $req)
{
    $payload = $req->validate([
        'transaction_id' => ['required','integer','exists:cash_sales,id'],
        'acct_code'      => ['required','string','max:75'],
        'debit'          => ['nullable','numeric'],
        'credit'         => ['nullable','numeric'],
        'company_id'     => ['required','integer'],
        'user_id'        => ['nullable','integer'],
        'initial_create' => ['nullable','in:0,1'],
    ]);

    $main = CashSales::findOrFail((int) $payload['transaction_id']);

    if (in_array($main->is_cancel, ['c', 'd', 'y'], true)) {
        abort(403, 'Cancelled or deleted transaction cannot be modified.');
    }

    // Before export: editable directly
    // After export: requires approved edit request
    if (!empty($main->exported_at)) {
        $this->requireEditApproval((int) $main->id);
    }

    $debit  = (float) ($payload['debit'] ?? 0);
    $credit = (float) ($payload['credit'] ?? 0);

    if ($debit > 0 && $credit > 0) {
        return response()->json(['message' => 'Provide either debit OR credit, not both.'], 422);
    }

    if ($debit <= 0 && $credit <= 0) {
        return response()->json(['message' => 'Debit or credit is required.'], 422);
    }

    $exists = AccountCode::where('acct_code', $payload['acct_code'])
        ->where('company_id', (int) $payload['company_id'])
        ->where('active_flag', 1)
        ->exists();

    if (!$exists) {
        return response()->json(['message' => 'Invalid or inactive account.'], 422);
    }

    $detail = CashSalesDetail::create([
        'transaction_id' => (int) $payload['transaction_id'],
        'acct_code'      => $payload['acct_code'],
        'debit'          => $debit,
        'credit'         => $credit,
        'company_id'     => (int) $payload['company_id'],
        'user_id'        => $payload['user_id'] ?? null,
    ]);

    $totals = $this->recalcTotals((int) $payload['transaction_id']);

    return response()->json([
        'detail_id' => $detail->id,
        'totals'    => $totals,
    ]);
}

    // 4) Update a detail row
public function updateDetail(Request $req)
{
    $payload = $req->validate([
        'id'             => ['required','integer','exists:cash_sales_details,id'],
        'transaction_id' => ['required','integer','exists:cash_sales,id'],
        'acct_code'      => ['nullable','string','max:75'],
        'debit'          => ['nullable','numeric'],
        'credit'         => ['nullable','numeric'],
        'initial_create' => ['nullable','in:0,1'],
    ]);

    $mainCheck = CashSales::findOrFail((int)$payload['transaction_id']);

    if (in_array($mainCheck->is_cancel, ['c', 'd', 'y'], true)) {
        abort(403, 'Cancelled or deleted transaction cannot be modified.');
    }

    // Before export: editable directly
    // After export: requires approved edit request
    if (!empty($mainCheck->exported_at)) {
        $this->requireEditApproval((int)$payload['transaction_id']);
    }

    $detail = CashSalesDetail::find($payload['id']);
    if (!$detail) {
        return response()->json(['message' => 'Detail not found.'], 404);
    }

    $apply = [];
    if (isset($payload['acct_code'])) $apply['acct_code'] = $payload['acct_code'];
    if (isset($payload['debit']))     $apply['debit']     = $payload['debit'];
    if (isset($payload['credit']))    $apply['credit']    = $payload['credit'];

    if (array_key_exists('debit', $apply) && array_key_exists('credit', $apply)) {
        $d = (float) $apply['debit'];
        $c = (float) $apply['credit'];
        if ($d > 0 && $c > 0) {
            return response()->json(['message' => 'Provide either debit OR credit.'], 422);
        }
    }

    if (array_key_exists('acct_code', $apply) && $apply['acct_code'] !== $detail->acct_code) {
        $companyId = (int) $mainCheck->company_id;

        $exists = AccountCode::where('acct_code', $apply['acct_code'])
            ->where('company_id', $companyId)
            ->where('active_flag', 1)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Invalid or inactive account.'], 422);
        }
    }

    $detail->update($apply);

    $totals = $this->recalcTotals($payload['transaction_id']);
    return response()->json(['ok' => true, 'totals' => $totals]);
}

    // 5) Delete a detail
public function deleteDetail(Request $req)
{
    $payload = $req->validate([
        'id'             => ['required','integer','exists:cash_sales_details,id'],
        'transaction_id' => ['required','integer','exists:cash_sales,id'],
        'initial_create' => ['nullable','in:0,1'],
    ]);

    $mainCheck = CashSales::findOrFail((int)$payload['transaction_id']);

    if (in_array($mainCheck->is_cancel, ['c', 'd', 'y'], true)) {
        abort(403, 'Cancelled or deleted transaction cannot be modified.');
    }

    // Before export: editable directly
    // After export: requires approved edit request
    if (!empty($mainCheck->exported_at)) {
        $this->requireEditApproval((int)$payload['transaction_id']);
    }

    CashSalesDetail::where('id', $payload['id'])->delete();

    $totals = $this->recalcTotals($payload['transaction_id']);
    return response()->json(['ok' => true, 'totals' => $totals]);
}

    // 6) Show main+details (for Search Transaction)
public function show($id, Request $req)
{
    $companyId = $req->query('company_id');

    $main = CashSales::when($companyId, fn($q)=>$q->where('company_id',$companyId))
        ->findOrFail($id);

    $details = CashSalesDetail::from('cash_sales_details as d')
        ->where('d.transaction_id', $main->id)
        ->when($companyId, fn($q)=>$q->where('d.company_id', $companyId))
        ->leftJoin('account_code as a', function ($j) use ($companyId) {
            $j->on('d.acct_code', '=', 'a.acct_code');
            if ($companyId) {
                $j->where('a.company_id', '=', $companyId);
            }
        })
        ->orderBy('d.id')
        ->get([
            'd.id',
            'd.transaction_id',
            'd.acct_code',
            DB::raw("COALESCE(a.acct_desc, '') as acct_desc"),
            'd.debit',
            'd.credit',
        ]);

    return response()->json(['main'=>$main,'details'=>$details]);
}


    // 7) Search list for combobox
public function list(Request $req)
{
    $companyId = $req->query('company_id');
    $q  = trim((string) $req->query('q', ''));
    $qq = strtolower($q); // normalize for case-insensitive search

    $rows = CashSales::from('cash_sales as s')
        ->when($companyId, fn ($qr) => $qr->where('s.company_id', $companyId))
        // hide soft-deleted rows (is_cancel = 'd'), keep others
        ->where(function ($qr) {
            $qr->whereNull('s.is_cancel')
               ->orWhere('s.is_cancel', '!=', 'd');
        })
        // optional: join ...
        // optional: join to allow searching/displaying customer name
        ->leftJoin('customer_list as c', function ($j) use ($companyId) {
            $j->on('c.cust_id', '=', 's.cust_id');
            if ($companyId) $j->where('c.company_id', $companyId);
        })
        ->when($q !== '', function ($qr) use ($qq) {
            // Use LOWER(..) LIKE ? so it’s case-insensitive on Postgres
            $qr->where(function ($w) use ($qq) {
                $w->whereRaw('LOWER(s.cs_no) LIKE ?',      ["%{$qq}%"])
                  ->orWhereRaw('LOWER(s.cust_id) LIKE ?',  ["%{$qq}%"])
                  ->orWhereRaw('LOWER(s.si_no) LIKE ?',    ["%{$qq}%"])
                  ->orWhereRaw('LOWER(c.cust_name) LIKE ?',["%{$qq}%"]); // search by name too
            });
        })
        ->orderByDesc('s.cs_no')
        ->limit(50)
        ->get([
            's.id','s.cs_no','s.cust_id','s.sales_date','s.sales_amount','s.si_no',
            's.check_ref_no','s.is_cancel',
            DB::raw("COALESCE(c.cust_name,'') as cust_name"),
        ]);

    return response()->json($rows);
}


    // 8) Dropdowns
    public function customers(Request $req)
    {
        $companyId = $req->query('company_id');
        $rows = CustomerList::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->orderBy('cust_name')
            ->get(['cust_id','cust_name']);

        // shape for DropdownWithHeaders
        $items = $rows->map(fn($r)=>[
            'code' => $r->cust_id,
            'label' => $r->cust_id,
            'description' => $r->cust_name,
            'cust_id' => $r->cust_id,
            'cust_name' => $r->cust_name,
        ]);
        return response()->json($items);
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
            //->limit(200)
            ->get(['acct_code','acct_desc']);

        return response()->json($rows);
    }

    // 9) Cancel / Uncancel
public function updateCancel(Request $req)
{
    $data = $req->validate([
        'id'         => ['required','integer','exists:cash_sales,id'],
        'company_id' => ['required','integer'],
        'flag'       => ['required','in:0,1'],
    ]);

    $val = $data['flag'] === '1' ? 'c' : 'n';

    $updated = CashSales::where('id', (int)$data['id'])
        ->where('company_id', (int)$data['company_id'])
        ->update(['is_cancel' => $val]);

    if (!$updated) abort(404, 'Not found.');

    return response()->json(['ok'=>true,'is_cancel'=>$val]);
}



public function softDeleteMain(Request $req)
{
    $data = $req->validate([
        'id'         => ['required','integer','exists:cash_sales,id'],
        'company_id' => ['required','integer'],
    ]);

    $row = CashSales::where('id', (int)$data['id'])
        ->where('company_id', (int)$data['company_id'])
        ->first();

    if (!$row) abort(404, 'Not found.');

    if ($row->is_cancel === 'd') {
        return response()->json(['ok'=>true,'is_cancel'=>'d']);
    }

    $row->is_cancel = 'd';
    $row->save();

    return response()->json(['ok'=>true,'is_cancel'=>'d']);
}




    // 10) Print/Download placeholders
    public function formPdf(Request $request, $id)
    {
        $companyId = $request->query('company_id');
$exportedBy = $request->query('user_id');

        // --- Header info (scoped by company)
        $header = DB::table('cash_sales as cs')
            ->join('customer_list as c', function ($j) use ($companyId) {
                $j->on('cs.cust_id', '=', 'c.cust_id');
                if ($companyId) {
                    $j->where('c.company_id', '=', $companyId);
                }
            })
            ->select(
                'cs.id','cs.cs_no','cs.cust_id','cs.sales_amount','cs.pay_method',
                'cs.bank_id','cs.explanation','cs.is_cancel','cs.si_no',
                DB::raw("to_char(cs.sales_date, 'MM/DD/YYYY') as sales_date"),
                'c.cust_name','cs.check_ref_no','cs.amount_in_words',
                'cs.workstation_id','cs.user_id','cs.created_at'
            )
            ->where('cs.id', $id)
            ->when($companyId, fn($q) => $q->where('cs.company_id', $companyId))
            ->first();

            if (
                !$header ||
                in_array($header->is_cancel, ['y', 'c', 'd'], true)
            ) {
                abort(404, 'Sales Voucher not found or cancelled');
            }

        // --- Details (scoped by company)
$details = DB::table('cash_sales_details as d')
    ->join('account_code as a', function ($j) use ($companyId) {
        $j->on('d.acct_code', '=', 'a.acct_code');
        if ($companyId) $j->where('a.company_id', '=', $companyId);
    })
    ->where('d.transaction_id', $id)
    ->when($companyId, fn($q) => $q->where('d.company_id', $companyId))
    ->orderBy('d.workstation_id','desc')
    ->orderBy('d.credit','desc')
    ->select('d.acct_code','a.acct_desc','d.debit','d.credit')
    ->get();


        $totalDebit  = $details->sum('debit');
        $totalCredit = $details->sum('credit');

        // --- Early guard: if not balanced, return the existing HTML notice
        if (abs($totalDebit - $totalCredit) > 0.005) {
            $html = sprintf(
                '<!doctype html><meta charset="utf-8">
                <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
                <h2>Cannot print Sales Voucher</h2>
                <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before printing.</p>
                <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
                number_format($totalDebit, 2),
                number_format($totalCredit, 2)
            );
            return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        // --- TCPDF with custom header/footer
$pdf = new MySalesVoucherPDF('P','mm','LETTER',true,'UTF-8',false);

// ✅ pass company_id into PDF so Header() can pick the correct logo
$pdf->setCompanyId($companyId ? (int)$companyId : null);

// ✅ prepared initials (priority: URL user_id, else header user_id)
$preparedUserId = (int) ($request->query('user_id') ?: 0);
if ($preparedUserId <= 0) {
    $preparedUserId = (int) ($header->user_id ?? 0);
}
$pdf->setPreparedByInitials($this->userInitials($preparedUserId));

$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

$pdf->SetMargins(15, 30, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetFooterMargin(10);

// ✅ Reserve footer space so body never overlaps footer
$pdf->SetAutoPageBreak(true, 55);

$pdf->AddPage();

        $pdf->SetFont('helvetica','',7);

        $pdf->setDataSalesDate(\Carbon\Carbon::parse($header->created_at)->format('M d, Y'));
        $pdf->setDataSalesTime(\Carbon\Carbon::parse($header->created_at)->format('h:i:sa'));

        $formattedDebit  = number_format($totalDebit, 2);
        $formattedCredit = number_format($totalCredit, 2);

        // --- Build body (existing layout)
// ✅ SV number 6 digits (pad only if purely numeric)
$rawSvNo = (string) ($header->cs_no ?? '');
$svNumber = ctype_digit($rawSvNo) ? str_pad($rawSvNo, 6, '0', STR_PAD_LEFT) : $rawSvNo;

        $tbl = <<<EOD
<br><br>
<table border="0" cellpadding="1" cellspacing="0" nobr="true" width="100%">
<tr>
  <td width="15%"></td>
  <td width="30%"></td>
  <td width="20%"></td>
  <td width="40%" colspan="2"><div><font size="16"><b>SALES VOUCHER</b></font></div></td>
</tr>
<tr><td colspan="5"></td></tr>
<tr>
  <td width="65%"></td>
  <td width="20%" align="left"><font size="10"><b>SV Number:</b></font></td>
  <td width="15%" align="left"><font size="14"><b><u>{$svNumber}</u></b></font></td>
</tr>
<tr>
  <td width="65%"></td>
  <td width="20%" align="left"><font size="10"><b>Invoice Date:</b></font></td>
  <td width="15%"><font size="10"><u>{$header->sales_date}</u></font></td>
</tr>
<tr>
  <td width="65%"></td>
  <td width="20%" align="left"><font size="10"><b>Sales Invoice #:</b></font></td>
  <td width="15%"><font size="10"><u>{$header->si_no}</u></font></td>
</tr>
<tr>
  <td width="15%"><font size="10"><b>CUSTOMER:</b></font></td>
  <td width="80%" colspan="4"><font size="10"><u>{$header->cust_name}</u></font></td>
</tr>
<tr>
  <td width="15%"><font size="10"><b>PESOS:</b></font></td>
  <td width="80%" colspan="4"><font size="10"><u>$formattedDebit</u></font></td>
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
$debitVal  = (float)($d->debit ?? 0);
$creditVal = (float)($d->credit ?? 0);

$debit  = ($debitVal  > 0) ? number_format($debitVal, 2) : '';
$credit = ($creditVal > 0) ? number_format($creditVal, 2) : '';

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

// ✅ mark exported once (printing locks the transaction)
$this->markExportedOnce((int)$id, (int)$companyId, $exportedBy ? (int)$exportedBy : null);

        // --- Stream PDF to browser
        $pdfContent = $pdf->Output('salesVoucher.pdf', 'S');

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="salesVoucher.pdf"');
    }

public function salesInvoicePdf(Request $request, $id)
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    try { while (ob_get_level() > 0) { @ob_end_clean(); } } catch (\Throwable $e) {}
    @set_time_limit(20);
    @ini_set('max_execution_time', '20');

    if (!class_exists('\TCPDF', false)) {
        $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
        if (file_exists($tcpdfPath)) {
            require_once $tcpdfPath;
        }
    }

    $companyId  = $request->query('company_id');
    $exportedBy = $request->query('user_id');

    $header = DB::table('cash_sales as cs')
        ->leftJoin('customer_list as c', function ($j) use ($companyId) {
            $j->on('cs.cust_id', '=', 'c.cust_id');
            if ($companyId) {
                $j->where('c.company_id', '=', $companyId);
            }
        })
        ->select(
            'cs.id',
            'cs.cs_no',
            'cs.cust_id',
            'cs.si_no',
            'cs.sales_amount',
            'cs.explanation',
            'cs.is_cancel',
            'cs.created_at',
            DB::raw("to_char(cs.sales_date, 'MM/DD/YYYY') as sales_date"),
            DB::raw("COALESCE(c.cust_name, '') as cust_name")
        )
        ->where('cs.id', $id)
        ->when($companyId, fn ($q) => $q->where('cs.company_id', $companyId))
        ->first();

    if (
        !$header ||
        in_array($header->is_cancel, ['y', 'c', 'd'], true)
    ) {
        abort(404, 'Sales Invoice not found or cancelled');
    }

    $details = DB::table('cash_sales_details as d')
        ->where('d.transaction_id', $id)
        ->when($companyId, fn ($q) => $q->where('d.company_id', $companyId))
        ->get(['d.debit', 'd.credit']);

    $totalDebit  = (float) $details->sum('debit');
    $totalCredit = (float) $details->sum('credit');

    if (abs($totalDebit - $totalCredit) > 0.005) {
        $html = sprintf(
            '<!doctype html><meta charset="utf-8">
            <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
            <h2>Cannot print Sales Invoice</h2>
            <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before printing.</p>
            <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
            number_format($totalDebit, 2),
            number_format($totalCredit, 2)
        );
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    $parsed = $this->parseInvoiceExplanation((string) ($header->explanation ?? ''));

    $soldTo            = strtoupper(trim((string) ($header->cust_name ?? '')));
    $registeredName    = $soldTo;
    $tin               = '';
    $businessAddress   = '';
    $description       = trim((string) ($header->explanation ?? ''));
    $quantityDisplay   = $parsed['quantity_display'];
    $unitPriceDisplay  = $parsed['unit_price'] > 0 ? number_format($parsed['unit_price'], 2) : '';
    $lineAmount        = $parsed['amount'] > 0 ? $parsed['amount'] : (float) ($header->sales_amount ?? 0);
    $lineAmountDisplay = $lineAmount > 0 ? number_format($lineAmount, 2) : '';

    $vatableSales     = '';
    $vat              = '';
    $zeroRatedSales   = '';
    $vatExemptSales   = '';
    $totalSalesVatInc = $lineAmountDisplay;
    $lessVat          = '';
    $netOfVat         = '';
    $addVat           = '';
    $lessWhTax        = '';
    $totalAmountDue   = $lineAmountDisplay;

    $siNoDisplay = trim((string) ($header->si_no ?? ''));
    $salesDate   = trim((string) ($header->sales_date ?? ''));

$pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);
$pdf->AddPage();
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.25);

// ===== Helpers =====
$txt = static function (\TCPDF $pdf, float $x, float $y, string $text, float $size = 8.5, string $style = '', string $font = 'helvetica') {
    $pdf->SetFont($font, $style, $size);
    $pdf->Text($x, $y, $text);
};

$cell = static function (\TCPDF $pdf, float $x, float $y, float $w, float $h, string $text, string $align = 'L', float $size = 8.5, string $style = '', string $font = 'helvetica') {
    $pdf->SetXY($x, $y);
    $pdf->SetFont($font, $style, $size);
    $pdf->Cell($w, $h, $text, 0, 0, $align, false, '', 0, false, 'T', 'M');
};

$fit = static function (\TCPDF $pdf, string $text, float $maxWidth, float $start = 8.5, float $min = 6.0, string $style = '', string $font = 'helvetica') {
    $size = $start;
    $pdf->SetFont($font, $style, $size);
    while ($size > $min && $pdf->GetStringWidth($text) > $maxWidth) {
        $size -= 0.2;
        $pdf->SetFont($font, $style, $size);
    }
    return $size;
};

$line = static function (\TCPDF $pdf, float $x1, float $y1, float $x2, float $y2) {
    $pdf->Line($x1, $y1, $x2, $y2);
};

// ===== Data =====
$soldTo            = strtoupper(trim((string) ($header->cust_name ?? '')));
$registeredName    = $soldTo;
$tin               = '';
$businessAddress   = '';

$description       = strtoupper(trim((string) ($header->explanation ?? '')));
$quantityDisplay   = $parsed['quantity_display'] ?: '';
$unitPriceDisplay  = $parsed['unit_price'] > 0 ? number_format($parsed['unit_price'], 2) : '';
$lineAmount        = $parsed['amount'] > 0 ? $parsed['amount'] : (float) ($header->sales_amount ?? 0);
$lineAmountDisplay = $lineAmount > 0 ? number_format($lineAmount, 2) : '';

$vatableSales     = '';
$vat              = '';
$zeroRatedSales   = '';
$vatExemptSales   = '';
$totalSalesVatInc = $lineAmountDisplay;
$lessVat          = '';
$netOfVat         = '';
$addVat           = '';
$lessWhTax        = '';
$totalAmountDue   = $lineAmountDisplay;

$siNoDisplay = trim((string) ($header->si_no ?? ''));
$salesDate   = trim((string) ($header->sales_date ?? ''));

// ===== Logo =====
$logoPath = null;
$logoCandidates = [
    public_path('boxed_sd_logo.png'),
    public_path('boxed_sd_logo.jpg'),
    public_path('S&D.png'),
    public_path('S&D.jpg'),
    public_path('images/boxed_sd_logo.png'),
    public_path('images/boxed_sd_logo.jpg'),
    public_path('SucdenLogo.jpg'),
    public_path('SucdenLogo.png'),
    public_path('sucdenLogo.jpg'),
    public_path('sucdenLogo.png'),
    public_path('images/sucdenLogo.jpg'),
    public_path('images/sucdenLogo.png'),
];
foreach ($logoCandidates as $img) {
    if ($img && is_file($img)) {
        $logoPath = $img;
        break;
    }
}
if ($logoPath) {
    $pdf->Image($logoPath, 9, 7, 22, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
}

// ======================================================
// HEADER
// ======================================================
$txt($pdf, 33, 8.0,  'SUCDEN PHILIPPINES, INC.', 11.5, 'B');
$txt($pdf, 33, 13.0, 'VAT Reg. TIN: 000-105-267-00000', 6.4, '');
$txt($pdf, 33, 16.3, 'UNIT 2202 THE PODIUM WEST TOWER 12 ADB AVENUE,', 6.2, '');
$txt($pdf, 33, 19.4, 'ORTIGAS CENTER, WACK-WACK GREENHILLS,', 6.2, '');
$txt($pdf, 33, 22.5, '1555 CITY OF MANDALUYONG NCR, SECOND DISTRICT PHILIPPINES', 6.0, '');

$txt($pdf, 160.5, 13.0, 'SALES INVOICE', 11.5, 'B', 'times');

$txt($pdf, 162.0, 23.3, 'No.', 10.5, 'B', 'times');
$cell($pdf, 173.5, 20.9, 24, 8, $siNoDisplay, 'L', 16, '', 'courier');

$txt($pdf, 150.5, 33.0, 'Date:', 8.5, 'B');
$line($pdf, 161.5, 36.2, 197.5, 36.2);
if ($salesDate !== '') {
    $dateSize = $fit($pdf, $salesDate, 30, 7.2, 5.5);
    $cell($pdf, 162.5, 32.0, 28, 5, $salesDate, 'L', $dateSize);
}

// ======================================================
// SALES TYPE CHECKBOXES
// ======================================================
$boxSize = 3.6;
$pdf->Rect(10, 31.0, $boxSize, $boxSize);
$txt($pdf, 14.8, 31.2, 'CASH SALES', 5.8, 'B');

$pdf->Rect(34.0, 31.0, $boxSize, $boxSize);
$txt($pdf, 38.7, 31.2, 'CHARGE SALES', 5.8, 'B');

// If you want charge sales checked, uncomment:
// $txt($pdf, 27.6, 31.1, 'X', 7.0, 'B');

// ======================================================
// CUSTOMER BOX
// ======================================================
$left  = 9.5;
$right = 199.0;

/*
|--------------------------------------------------------------------------
| Customer box
| - moved slightly upward to narrow the gap below Date
| - still keeps safe clearance from the Date underline
| - Business Address row remains taller like the actual form
|--------------------------------------------------------------------------
*/
$customerTop    = 39.5;
$customerBottom = 62.8;

// outer border
$pdf->RoundedRect($left, $customerTop, $right - $left, $customerBottom - $customerTop, 1.8, '1111', 'D');

// row lines
$line($pdf, $left, 44.7, $right, 44.7); // after SOLD TO
$line($pdf, $left, 49.5, $right, 49.5); // after Registered Name
$line($pdf, $left, 54.3, $right, 54.3); // after TIN

// labels
$txt($pdf, 11.0, 41.3, 'SOLD TO:', 6.8, 'B');
$txt($pdf, 11.0, 46.1, 'Registered Name:', 6.2, 'B');
$txt($pdf, 11.0, 50.9, 'TIN:', 6.2, 'B');
$txt($pdf, 11.0, 56.0, 'Business Address:', 6.2, 'B');

// values
if ($soldTo !== '') {
    $size = $fit($pdf, $soldTo, 145, 6.4, 5.2);
    $cell($pdf, 34.0, 40.4, 150, 4, $soldTo, 'L', $size);
}
if ($registeredName !== '') {
    $size = $fit($pdf, $registeredName, 145, 6.1, 5.2);
    $cell($pdf, 34.0, 45.2, 150, 4, $registeredName, 'L', $size);
}
if ($tin !== '') {
    $size = $fit($pdf, $tin, 145, 6.1, 5.2);
    $cell($pdf, 34.0, 50.0, 150, 4, $tin, 'L', $size);
}
if ($businessAddress !== '') {
    $size = $fit($pdf, $businessAddress, 145, 6.1, 5.2);
    $cell($pdf, 34.0, 55.2, 150, 4, $businessAddress, 'L', $size);
}

// ======================================================
// MAIN DETAIL TABLE
// ======================================================
$tableLeft   = 9.5;
$tableRight  = 199.0;
$tableTop    = 63.2;
$tableBottom = 157.0;

// column positions based on the actual form
$colDesc = 133.5;
$colQty  = 156.5;
$colUnit = 173.5;
$colAmt  = 199.0;

// outer border
$pdf->Rect($tableLeft, $tableTop, $tableRight - $tableLeft, $tableBottom - $tableTop);

// verticals
$line($pdf, $colDesc, $tableTop, $colDesc, $tableBottom);
$line($pdf, $colQty,  $tableTop, $colQty,  $tableBottom);
$line($pdf, $colUnit, $tableTop, $colUnit, $tableBottom);

// header bottom
$headerBottom = 72.2;
$line($pdf, $tableLeft, $headerBottom, $tableRight, $headerBottom);

// header text
$cell($pdf, 47, 64.1, 72, 7, "ITEM DESCRIPTION /\nNATURE OF SERVICE", 'C', 6.2, 'B');
$cell($pdf, 134.5, 65.5, 21, 5, 'QUANTITY', 'C', 6.0, 'B');
$cell($pdf, 157.0, 64.3, 16, 7, "UNIT\nPRICE", 'C', 5.8, 'B');
$cell($pdf, 175.0, 65.5, 20, 5, 'AMOUNT', 'C', 6.0, 'B');

// body rows
$rowHeight = 6.2;
$y = $headerBottom;

for ($i = 0; $i < 14; $i++) {
    $y += $rowHeight;
    if ($y < $tableBottom) {
        $line($pdf, $tableLeft, $y, $tableRight, $y);
    }
}

// first row values only
$descY = 73.6;
if ($description !== '') {
    $descSize = $fit($pdf, $description, 118, 6.1, 4.8);
    $cell($pdf, 11.5, $descY, 120, 5, $description, 'L', $descSize);
}
if ($quantityDisplay !== '') {
    $qtySize = $fit($pdf, $quantityDisplay, 18, 6.0, 4.8);
    $cell($pdf, 135.0, $descY, 20, 5, $quantityDisplay, 'C', $qtySize);
}
if ($unitPriceDisplay !== '') {
    $priceSize = $fit($pdf, $unitPriceDisplay, 14, 6.0, 4.8);
    $cell($pdf, 157.5, $descY, 14, 5, $unitPriceDisplay, 'R', $priceSize);
}
if ($lineAmountDisplay !== '') {
    $amtSize = $fit($pdf, $lineAmountDisplay, 18, 6.0, 4.8);
    $cell($pdf, 176.0, $descY, 20, 5, $lineAmountDisplay, 'R', $amtSize);
}

// ======================================================
// BOTTOM LEFT BOX
// ======================================================
$leftBoxTop    = 159.2;
$leftBoxBottom = 182.2;
$leftBoxRight  = 133.5;

$pdf->Rect($tableLeft, $leftBoxTop, $leftBoxRight - $tableLeft, $leftBoxBottom - $leftBoxTop);

// inner horizontal lines
$line($pdf, $tableLeft, 164.95, $leftBoxRight, 164.95);
$line($pdf, $tableLeft, 170.70, $leftBoxRight, 170.70);
$line($pdf, $tableLeft, 176.45, $leftBoxRight, 176.45);

// inner vertical split
$line($pdf, 31.8, $leftBoxTop, 31.8, 176.45);

// labels
$txt($pdf, 11.8, 160.9, 'VATable Sales', 5.8);
$txt($pdf, 11.8, 166.65, 'VAT', 5.8);
$txt($pdf, 11.8, 172.40, 'Zero Rated Sales', 5.8);
$txt($pdf, 11.8, 178.15, 'VAT-Exempt Sales', 5.8);

// values
if ($vatableSales !== '')   $cell($pdf, 33.2, 160.0, 35, 5, $vatableSales, 'R', 5.8);
if ($vat !== '')            $cell($pdf, 33.2, 165.75, 35, 5, $vat, 'R', 5.8);
if ($zeroRatedSales !== '') $cell($pdf, 33.2, 171.50, 35, 5, $zeroRatedSales, 'R', 5.8);
if ($vatExemptSales !== '') $cell($pdf, 33.2, 177.25, 35, 5, $vatExemptSales, 'R', 5.8);

// checkbox + signature text
$pdf->Rect(11.0, 186.3, 3.6, 3.6);
$txt($pdf, 16.8, 186.8, 'Received the amount of', 5.7);
$line($pdf, 47.0, 190.0, 81.0, 190.0);

$txt($pdf, 10.8, 196.0, 'By:', 5.8, 'B');
$line($pdf, 16.8, 199.3, 58.5, 199.3);
$txt($pdf, 22.0, 200.6, "Cashier's / Authorized Signature", 5.7, 'B');

// ======================================================
// BOTTOM RIGHT TOTALS BOX
// ======================================================
$sumLeft   = 133.5;
$sumTop    = 159.2;
$sumRight  = 199.0;
$sumBottom = 193.7;

$pdf->Rect($sumLeft, $sumTop, $sumRight - $sumLeft, $sumBottom - $sumTop);

// split into label/value columns
$sumSplit = 184.5;
$line($pdf, $sumSplit, $sumTop, $sumSplit, $sumBottom);

// horizontal rows
$sumRows = [164.95, 170.70, 176.45, 182.20, 187.95];
foreach ($sumRows as $yy) {
    $line($pdf, $sumLeft, $yy, $sumRight, $yy);
}

// labels
$txt($pdf, 151.0, 160.9, 'Total Sales (VAT Inclusive)', 5.7, 'B');
$txt($pdf, 167.0, 166.65, 'Less: VAT', 5.7, 'B');
$txt($pdf, 159.7, 172.40, 'Amount: Net of VAT', 5.7, 'B');
$txt($pdf, 168.2, 178.15, 'Add: VAT', 5.7, 'B');
$txt($pdf, 155.7, 183.90, 'Less: Withholding Tax', 5.7, 'B');
$txt($pdf, 156.6, 189.65, 'TOTAL AMOUNT DUE', 6.1, 'B');

// values
if ($totalSalesVatInc !== '') $cell($pdf, 185.3, 160.0, 12.8, 5, $totalSalesVatInc, 'R', 5.8);
if ($lessVat !== '')          $cell($pdf, 185.3, 165.75, 12.8, 5, $lessVat, 'R', 5.8);
if ($netOfVat !== '')         $cell($pdf, 185.3, 171.50, 12.8, 5, $netOfVat, 'R', 5.8);
if ($addVat !== '')           $cell($pdf, 185.3, 177.25, 12.8, 5, $addVat, 'R', 5.8);
if ($lessWhTax !== '')        $cell($pdf, 185.3, 183.00, 12.8, 5, $lessWhTax, 'R', 5.8);
if ($totalAmountDue !== '')   $cell($pdf, 185.3, 188.75, 12.8, 5, $totalAmountDue, 'R', 6.2, 'B');

// ======================================================
// FOOTER / PRINTER INFO
// ======================================================
$txt($pdf, 10.0, 202.0, '10 BKS x 3  0601A-10001A', 4.8);
$txt($pdf, 10.0, 205.0, 'BIR Authority to Print No.: OCN O4JAIJ-252-002013', 4.5);
$txt($pdf, 10.0, 208.0, 'Date Issued: 11-16-16    Expiry Date: 11-15-21', 4.5);
$txt($pdf, 10.0, 211.0, 'MIN: 000C REG TIN: 217-760-084-00000', 4.5);

$txt($pdf, 145.0, 201.5, '275-A Sitio Rosario St., Brgy. Plainview, Mandaluyong City', 4.5);
$txt($pdf, 145.0, 204.5, 'Printers Accreditation No.: 041MPZ030000000005', 4.5);
$txt($pdf, 145.0, 207.5, 'Date No.: 0000 0 C 0 1 7 0 2 2 3', 4.5);
$txt($pdf, 145.0, 210.5, 'Mobile Nos.: 0915 426 7520    0947 397 1877', 4.5);

// optional BIR logo circle area, only if you have image
// $pdf->Image(public_path('bir_logo.png'), 80, 201, 10, 10);

$this->markExportedOnce((int) $id, (int) $companyId, $exportedBy ? (int) $exportedBy : null);

$fileName = 'SalesInvoice_' . ($header->si_no ?: $header->cs_no ?: $id) . '.pdf';
$pdfContent = $pdf->Output($fileName, 'S');

return response($pdfContent, 200)
    ->header('Content-Type', 'application/pdf')
    ->header('Content-Disposition', 'inline; filename="' . $fileName . '"');
}


public function checkPdf(Request $request, $id)
{
    $companyId = $request->query('company_id');
$exportedBy = $request->query('user_id');

    // --- Header info (reuse pattern from formPdf) ---
    $header = DB::table('cash_sales as cs')
        ->join('customer_list as c', function ($j) use ($companyId) {
            $j->on('cs.cust_id', '=', 'c.cust_id');
            if ($companyId) {
                $j->where('c.company_id', '=', $companyId);
            }
        })
        ->select(
            'cs.id',
            'cs.cs_no',
            'cs.cust_id',
            'cs.sales_amount',
            'cs.is_cancel',
            DB::raw("cs.sales_date as raw_sales_date"),
            DB::raw("to_char(cs.sales_date, 'MM/DD/YYYY') as sales_date"),
            'cs.amount_in_words',
            'cs.check_ref_no',
            'c.cust_name'
        )
        ->where('cs.id', $id)
        ->when($companyId, fn ($q) => $q->where('cs.company_id', $companyId))
        ->first();

    // Not found or cancelled/deleted
    if (
        !$header ||
        in_array($header->is_cancel, ['y', 'c', 'd'], true)
    ) {
        abort(404, 'Sales Voucher not found or cancelled');
    }

    // --- Details (to make sure it is balanced) ---
    $details = DB::table('cash_sales_details as d')
        ->where('d.transaction_id', $id)
        ->when($companyId, fn ($q) => $q->where('d.company_id', $companyId))
        ->get(['d.debit', 'd.credit']);

    $totalDebit  = $details->sum('debit');
    $totalCredit = $details->sum('credit');

    // Guard: only balanced transactions can print a check
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

    // --- Map values for the check layout ---
    $amountNumeric    = round((float) $totalDebit, 2);
    $amountNumericStr = number_format($amountNumeric, 2);

    $amountWords = trim((string) ($header->amount_in_words ?? ''));
    if ($amountWords === '') {
        $amountWords = $this->numberToPesoWords($amountNumeric);
    }

    $payeeName = (string) $header->cust_name;

    // Use sales_date as check date (you can change this to a dedicated field later)
    $date = $header->raw_sales_date
        ? \Carbon\Carbon::parse($header->raw_sales_date)
        : \Carbon\Carbon::now();

    $mm   = $date->format('m');
    $dd   = $date->format('d');
    $yyyy = $date->format('Y');

    // --- TCPDF: custom page size matching a physical check (LANDSCAPE) ---
    // Approx. Philippine commercial check size: 8.0" x 3.0"
    // 1 inch = 25.4 mm
    $checkWidthMm  = 8.0 * 25.4;   // 203.2 mm (width)
    $checkHeightMm = 3.0 * 25.4;   // 76.2 mm (height)

    // IMPORTANT: use 'L' orientation and [width, height] to avoid vertical page
    $pdf = new \TCPDF('L', 'mm', [$checkWidthMm, $checkHeightMm], true, 'UTF-8', false);

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Small margins; we want to use almost the full check area
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(false, 0);

    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);

    /*
     * Coordinates below assume an ~8x3 inch check in landscape.
     * You will likely tweak these SetXY positions a bit
     * after test prints on your actual bank stock.
     */

    // 1) Date (MM  DD  YYYY) – upper-right
    $pdf->SetXY(140, 10);
    $pdf->Cell(0, 5, $mm . '   ' . $dd . '   ' . $yyyy, 0, 1, 'L');

    // 2) Payee: "PAY TO THE ORDER OF" line
    $pdf->SetXY(20, 25);
    $pdf->Cell(120, 6, $payeeName, 0, 1, 'L');

    // 3) Amount in figures (P ###,###.##) near right-hand "P" box
    $pdf->SetXY(145, 25);
    $pdf->Cell(50, 6, $amountNumericStr, 0, 1, 'R');

    // 4) Amount in words – PESOS line
    $pdf->SetXY(20, 35);
    $pdf->MultiCell(160, 6, $amountWords, 0, 'L', false, 1);

    $fileName   = 'check_' . ($header->cs_no ?? $id) . '.pdf';

// ✅ mark exported once (printing check locks the transaction)
$this->markExportedOnce((int)$id, (int)$companyId, $exportedBy ? (int)$exportedBy : null);

    $pdfContent = $pdf->Output($fileName, 'S');

    return response($pdfContent, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="'.$fileName.'"');
}


    public function formExcel(Request $request, $id)
    {
        $companyId = $request->query('company_id');
$exportedBy = $request->query('user_id');

        // Header (scoped)
        $header = DB::table('cash_sales as cs')
            ->join('customer_list as c', function ($j) use ($companyId) {
                $j->on('cs.cust_id', '=', 'c.cust_id');
                if ($companyId) $j->where('c.company_id', '=', $companyId);
            })
            ->select(
                'cs.id','cs.cs_no','cs.cust_id','cs.sales_amount',
                DB::raw("to_char(cs.sales_date, 'YYYY-MM-DD') as sales_date"),
                'cs.si_no','cs.explanation','cs.is_cancel','c.cust_name'
            )
            ->where('cs.id', $id)
            ->when($companyId, fn($q) => $q->where('cs.company_id', $companyId))
            ->first();

            if (
                !$header ||
                in_array($header->is_cancel, ['y', 'c', 'd'], true)
            ) {
                abort(404, 'Sales Voucher not found or cancelled');
            }

        // Details (scoped)
$details = DB::table('cash_sales_details as d')
    ->leftJoin('account_code as a', function ($j) use ($companyId) {
        $j->on('d.acct_code', '=', 'a.acct_code');
        if ($companyId) $j->where('a.company_id', '=', $companyId);
    })
    ->where('d.transaction_id', $id)
    ->when($companyId, fn($q) => $q->where('d.company_id', $companyId))
    ->orderBy('d.id')
    ->get([
        'd.acct_code',
        DB::raw("COALESCE(a.acct_desc,'') as acct_desc"),
        'd.debit','d.credit'
    ]);


        $totalDebit  = $details->sum('debit');
        $totalCredit = $details->sum('credit');

        // Build XLSX
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales Voucher');

        // Header block
        $r = 1;
        $sheet->setCellValue("A{$r}", 'SALES VOUCHER'); $r += 2;

        $sheet->setCellValue("A{$r}", 'SV Number:');  $sheet->setCellValue("B{$r}", $header->cs_no);       $r++;
        $sheet->setCellValue("A{$r}", 'Invoice Date:');$sheet->setCellValue("B{$r}", $header->sales_date);  $r++;
        $sheet->setCellValue("A{$r}", 'Sales Invoice #:'); $sheet->setCellValue("B{$r}", $header->si_no);   $r++;
        $sheet->setCellValue("A{$r}", 'Customer:');   $sheet->setCellValue("B{$r}", $header->cust_name);    $r++;
        $sheet->setCellValue("A{$r}", 'Explanation:');$sheet->setCellValue("B{$r}", $header->explanation);  $r += 2;

        // Table headers
        $sheet->setCellValue("A{$r}", 'ACCOUNT');
        $sheet->setCellValue("B{$r}", 'GL ACCOUNT');
        $sheet->setCellValue("C{$r}", 'DEBIT');
        $sheet->setCellValue("D{$r}", 'CREDIT');
        $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
        $r++;

        // Rows
        foreach ($details as $d) {
            $sheet->setCellValue("A{$r}", $d->acct_code);
            $sheet->setCellValue("B{$r}", $d->acct_desc);
            
$debitVal  = (float)($d->debit ?? 0);
$creditVal = (float)($d->credit ?? 0);

if ($debitVal > 0) {
    $sheet->setCellValueExplicit("C{$r}", $debitVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
} // else leave blank

if ($creditVal > 0) {
    $sheet->setCellValueExplicit("D{$r}", $creditVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
} // else leave blank
            
            $r++;
        }

        // Totals
        $sheet->setCellValue("B{$r}", 'TOTAL');
        $sheet->setCellValue("C{$r}", (float)$totalDebit);
        $sheet->setCellValue("D{$r}", (float)$totalCredit);
        $sheet->getStyle("B{$r}:D{$r}")->getFont()->setBold(true);

        // Formats & widths
        $sheet->getStyle("C1:D{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (['A'=>18,'B'=>45,'C'=>16,'D'=>16] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Stream to memory
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $xlsData = ob_get_clean();

// ✅ mark exported once (download locks the transaction)
$this->markExportedOnce((int)$id, (int)$companyId, $exportedBy ? (int)$exportedBy : null);


        $fileName = 'SalesVoucher_' . ($header->cs_no ?? $id) . '.xlsx';

        return response($xlsData, 200)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$fileName.'"')
            ->header('Content-Length', (string)strlen($xlsData));
    }

// =================== BEGIN ADD: exported lock helpers ===================
protected function markExportedOnce(int $id, ?int $companyId, ?int $userId): void
{
    if (!$companyId) return;

    // Do not overwrite exported_at/exported_by once set
    DB::table('cash_sales')
        ->where('id', $id)
        ->where('company_id', (int)$companyId)
        ->whereNull('exported_at')
        ->update([
            'exported_at' => now(),
            'exported_by' => $userId,
            'updated_at'  => now(),
        ]);
}

protected function requireNotExported(int $transactionId, ?int $companyId): void
{
    if (!$companyId) return;

    $row = DB::table('cash_sales')
        ->where('id', $transactionId)
        ->where('company_id', (int)$companyId)
        ->first(['exported_at']);

    if ($row && !empty($row->exported_at)) {
        abort(403, 'This transaction is already EXPORTED and cannot be modified.');
    }
}
// =================== END ADD: exported lock helpers ===================




    // ---- helpers ----
    protected function recalcTotals(int $transactionId): array
    {
        $tot = CashSalesDetail::where('transaction_id',$transactionId)
            ->selectRaw('COALESCE(SUM(debit),0) as sum_debit, COALESCE(SUM(credit),0) as sum_credit')
            ->first();

        $sumDebit  = round((float)$tot->sum_debit, 2);
        $sumCredit = round((float)$tot->sum_credit, 2);
        $balanced  = abs($sumDebit - $sumCredit) < 0.005;

        CashSales::where('id',$transactionId)->update([
            'sales_amount' => $sumDebit,   // legacy
            'sum_debit'    => $sumDebit,
            'sum_credit'   => $sumCredit,
            'is_balanced'  => $balanced,
        ]);

        return ['debit'=>$sumDebit,'credit'=>$sumCredit,'balanced'=>$balanced];
    }

    // *** NEW: central approval gate for Sales Journal edits ***
protected function requireEditApproval(int $transactionId): \stdClass
{
    $main = CashSales::findOrFail($transactionId);

    $module    = 'sales_journal';
    $companyId = (int) $main->company_id;

    $row = DB::table('approvals')
        ->where('module', $module)
        ->where('record_id', $transactionId)
        ->where('company_id', $companyId)
        ->whereRaw('LOWER(action) = ?', ['edit'])
        ->where('status', 'approved')
        ->whereNull('consumed_at')
        ->orderByDesc('id')
        ->first();

    if (!$row) {
        abort(403, 'Supervisor approval required for editing this sales journal entry.');
    }

    $now = now();

    // ✅ If expires_at is null, approval stays active indefinitely until released/consumed
    if (!empty($row->expires_at)) {
        $expires = \Carbon\Carbon::parse($row->expires_at);
        if ($now->gte($expires)) {
            abort(403, 'Edit approval has expired. Please request a new approval.');
        }
    }

    if (empty($row->first_edit_at)) {
        DB::table('approvals')->where('id', $row->id)->update([
            'first_edit_at' => $now,
            'updated_at'    => $now,
        ]);
        $row->first_edit_at = $now;
    }

    return $row;
}

    public function unbalancedExists(Request $req)
    {
        $companyId = $req->query('company_id');
        $exists = CashSales::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->where('is_balanced', false)
            ->exists();

        return response()->json(['exists' => $exists]);
    }

    public function unbalanced(Request $req)
    {
        $companyId = $req->query('company_id');
        $limit = (int) $req->query('limit', 20);

        $rows = CashSales::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->where('is_balanced', false)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get([
                'id',
                'cs_no',
                'cust_id',
                DB::raw('COALESCE(sum_debit,0)  as sum_debit'),
                DB::raw('COALESCE(sum_credit,0) as sum_credit'),
            ]);

        return response()->json(['items' => $rows]);
    }


protected function parseInvoiceExplanation(string $text): array
{
    $raw = trim($text);

    $result = [
        'quantity_value'   => 0.0,
        'quantity_unit'    => '',
        'quantity_display' => '',
        'unit_price'       => 0.0,
        'amount'           => 0.0,
    ];

    if ($raw === '') {
        return $result;
    }

    $pattern = '/^\s*([\d,]+(?:\.\d+)?)\s*([A-Za-z]+(?:\s+[A-Za-z]+)*)?\s*@\s*([\d,]+(?:\.\d+)?)\s*$/u';

    if (!preg_match($pattern, $raw, $m)) {
        return $result;
    }

    $qtyValue  = (float) str_replace(',', '', (string) ($m[1] ?? '0'));
    $qtyUnit   = trim((string) ($m[2] ?? ''));
    $unitPrice = (float) str_replace(',', '', (string) ($m[3] ?? '0'));

    $qtyDisplay = rtrim(rtrim(number_format($qtyValue, 2, '.', ''), '0'), '.');
    if ($qtyUnit !== '') {
        $qtyDisplay .= ' ' . $qtyUnit;
    }

    $result['quantity_value']   = $qtyValue;
    $result['quantity_unit']    = $qtyUnit;
    $result['quantity_display'] = trim($qtyDisplay);
    $result['unit_price']       = $unitPrice;
    $result['amount']           = round($qtyValue * $unitPrice, 2);

    return $result;
}




/**
 * Simple converter: 1234.56 → "One thousand two hundred thirty-four pesos and 56/100"
 */
protected function numberToPesoWords(float $amount): string
{
    $amount = round($amount, 2);
    $integerPart = (int) floor($amount);
    $cents = (int) round(($amount - $integerPart) * 100);

    if ($integerPart === 0) {
        $words = 'zero';
    } else {
        $words = $this->numberToWords($integerPart);
    }

    $words = ucfirst($words) . ' pesos';

    if ($cents > 0) {
        $words .= ' and ' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT) . '/100';
    } else {
        $words .= ' only';
    }

    return $words;
}

/**
 * Basic English number words up to the billions (enough for checks).
 */
protected function numberToWords(int $num): string
{
    $ones = [
        '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven',
        'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
        'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'
    ];
    $tens = [
        '', '', 'twenty', 'thirty', 'forty', 'fifty',
        'sixty', 'seventy', 'eighty', 'ninety'
    ];
    $scales = ['', 'thousand', 'million', 'billion'];

    if ($num === 0) {
        return 'zero';
    }

    $words = [];
    $scaleIndex = 0;

    while ($num > 0) {
        $chunk = $num % 1000;
        if ($chunk > 0) {
            $chunkWords = [];

            $hundreds = intdiv($chunk, 100);
            $remainder = $chunk % 100;

            if ($hundreds > 0) {
                $chunkWords[] = $ones[$hundreds] . ' hundred';
            }

            if ($remainder > 0) {
                if ($remainder < 20) {
                    $chunkWords[] = $ones[$remainder];
                } else {
                    $t = intdiv($remainder, 10);
                    $u = $remainder % 10;
                    $chunkWords[] = $tens[$t] . ($u ? '-' . $ones[$u] : '');
                }
            }

            if ($scales[$scaleIndex] !== '') {
                $chunkWords[] = $scales[$scaleIndex];
            }

            array_unshift($words, implode(' ', $chunkWords));
        }

        $num = intdiv($num, 1000);
        $scaleIndex++;
    }

    return implode(' ', $words);
}


}
