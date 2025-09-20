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
    public $salesDate;
    public $salesTime;

    public function setDataSalesDate($date) { $this->salesDate = $date; }
    public function setDataSalesTime($time) { $this->salesTime = $time; }

    public function Header() {
        // Try common locations and support JPG/PNG
        $candidates = [
            public_path('images/sucdenLogo.jpg'),
            public_path('images/sucdenLogo.png'),
            public_path('sucdenLogo.jpg'),
            public_path('sucdenLogo.png'),
        ];
        foreach ($candidates as $img) {
            if ($img && is_file($img)) {
                $ext = strtoupper(pathinfo($img, PATHINFO_EXTENSION)); // JPG or PNG
                // x=15, y=10, width=50 (same spot you use elsewhere)
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
            <td><font size="8">'.$this->salesDate.'</font></td>
            <td><font size="8">'.$this->salesTime.'</font></td>
            <td></td>
          </tr>
        </table>';
        $this->writeHTML($html, true, false, false, false, '');
    }
}





class SalesJournalController extends Controller
{
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

    // 3) Insert a detail row (prevents duplicate acct_code per transaction)
    public function saveDetail(Request $req)
    {
        $payload = $req->validate([
            'transaction_id' => ['required','integer','exists:cash_sales,id'],
            'acct_code'      => ['required','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
            'company_id'     => ['required','integer'],
            'user_id'        => ['nullable','integer'],
            'workstation_id' => ['nullable','string','max:25'],
        ]);

        // allow only one of debit/credit positive
        $debit  = (float)($payload['debit'] ?? 0);
        $credit = (float)($payload['credit'] ?? 0);
        if ($debit > 0 && $credit > 0) {
            return response()->json(['message' => 'Provide either debit or credit, not both.'], 422);
        }
        if ($debit <= 0 && $credit <= 0) {
            return response()->json(['message' => 'Debit or credit is required.'], 422);
        }

        // Valid account?
        $exists = AccountCode::where('acct_code', $payload['acct_code'])
            ->where('active_flag', 1)
            ->exists();
        if (!$exists) return response()->json(['message' => 'Invalid or inactive account.'], 422);

        // Duplicate acct_code in same transaction?
        $dup = CashSalesDetail::where('transaction_id',$payload['transaction_id'])
            ->where('acct_code',$payload['acct_code'])->exists();
        if ($dup) return response()->json(['message' => 'Duplicate account code for this transaction.'], 422);

        $detail = CashSalesDetail::create($payload);

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
            'id'             => ['required','integer','exists:cash_sales_details,id'],
            'transaction_id' => ['required','integer','exists:cash_sales,id'],
            'acct_code'      => ['nullable','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
        ]);

        $detail = CashSalesDetail::find($payload['id']);
        if (!$detail) return response()->json(['message' => 'Detail not found.'], 404);

        $apply = [];
        if (isset($payload['acct_code'])) $apply['acct_code'] = $payload['acct_code'];
        if (isset($payload['debit']))     $apply['debit']     = $payload['debit'];
        if (isset($payload['credit']))    $apply['credit']    = $payload['credit'];

        // enforce either debit or credit on update if both present
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
            'id'             => ['required','integer','exists:cash_sales_details,id'],
            'transaction_id' => ['required','integer','exists:cash_sales,id'],
        ]);
        CashSalesDetail::where('id',$payload['id'])->delete();
        $totals = $this->recalcTotals($payload['transaction_id']);
        return response()->json(['ok'=>true,'totals'=>$totals]);
    }

    // 6) Show main+details (for Search Transaction)
    public function show($id, Request $req)
    {
        $companyId = $req->query('company_id');

        $main = CashSales::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->findOrFail($id);

        $details = CashSalesDetail::where('transaction_id',$main->id)
            ->leftJoin('account_code','cash_sales_details.acct_code','=','account_code.acct_code')
            ->orderBy('cash_sales_details.id')
            ->get([
                'cash_sales_details.id',
                'cash_sales_details.transaction_id',
                'cash_sales_details.acct_code',
                DB::raw('COALESCE(account_code.acct_desc, \'\') as acct_desc'),
                'cash_sales_details.debit',
                'cash_sales_details.credit',
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
            ->limit(200)
            ->get(['acct_code','acct_desc']);

        return response()->json($rows);
    }

    // 9) Cancel / Uncancel
    public function updateCancel(Request $req)
    {
        $data = $req->validate([
            'id' => ['required','integer','exists:cash_sales,id'],
            'flag' => ['required','in:0,1'],
        ]);
        $val = $data['flag'] == '1' ? 'y' : 'n';
        CashSales::where('id',$data['id'])->update(['is_cancel'=>$val]);
        return response()->json(['ok'=>true,'is_cancel'=>$val]);
    }

    // 10) Print/Download placeholders
public function formPdf(Request $request, $id)
{
       
    // --- Header info
    $header = DB::table('cash_sales as cs')
        ->join('customer_list as c', 'cs.cust_id', '=', 'c.cust_id') // varchar join
        ->select(
            'cs.id','cs.cs_no','cs.cust_id','cs.sales_amount','cs.pay_method',
            'cs.bank_id','cs.explanation','cs.is_cancel','cs.si_no',
            DB::raw("to_char(cs.sales_date, 'MM/DD/YYYY') as sales_date"),
            'c.cust_name','cs.check_ref_no','cs.amount_in_words',
            'cs.workstation_id','cs.user_id','cs.created_at'
        )
        ->where('cs.id', $id)
        ->first();

    if (!$header || $header->is_cancel === 'y') {
        abort(404, 'Sales Voucher not found or cancelled');
    }

    // --- Details
    $details = DB::table('cash_sales_details as d')
        ->join('account_code as a', 'd.acct_code', '=', 'a.acct_code')
        ->where('d.transaction_id', $id)
        ->orderBy('d.workstation_id','desc')
        ->orderBy('d.credit','desc')
        ->select('d.acct_code','a.acct_desc','d.debit','d.credit')
        ->get();

    $totalDebit  = $details->sum('debit');
    $totalCredit = $details->sum('credit');

    // --- TCPDF with custom header/footer
    $pdf = new MySalesVoucherPDF('P','mm','LETTER',true,'UTF-8',false);

        // 🔽 ADD THESE TWO LINES
    $pdf->setPrintHeader(true);   // ensure Header() (logo) runs
    $pdf->SetHeaderMargin(8);     // avoid overlap with content
    
    $pdf->SetMargins(15,30,15);
    $pdf->AddPage();
    $pdf->SetFont('helvetica','',7);

    $pdf->setDataSalesDate(\Carbon\Carbon::parse($header->created_at)->format('M d, Y'));
    $pdf->setDataSalesTime(\Carbon\Carbon::parse($header->created_at)->format('h:i:sa'));

    $formattedDebit  = number_format($totalDebit, 2);
    $formattedCredit = number_format($totalCredit, 2);

    // --- Build body (legacy layout)
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
  <td width="15%" align="left"><font size="14"><b><u>{$header->cs_no}</u></b></font></td>
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


    // --- Stream PDF to browser
    $pdfContent = $pdf->Output('salesVoucher.pdf', 'S');

    return response($pdfContent, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="salesVoucher.pdf"');
}

  
   
   
    public function checkPdf($id)       { return response('Check PDF stub – implement renderer', 200); }
    public function formExcel($id)      { return response('Excel stub – implement renderer', 200); }

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

}





