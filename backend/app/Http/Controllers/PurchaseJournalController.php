<?php

namespace App\Http\Controllers;

use App\Models\CashPurchase;
use App\Models\CashPurchaseDetail;
use App\Models\AccountCode;
use App\Models\VendorList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $data = $req->validate([
            'cp_no'          => ['nullable','string','max:25'],
            'vendor_id'      => ['required','string','max:50'],
            'purchase_date'  => ['required','date'],
            'explanation'    => ['required','string','max:1000'],
            'invoice_no'     => ['required','string','max:25'],
            'company_id'     => ['required','integer'],
            'workstation_id' => ['nullable','string','max:25'],
            'user_id'        => ['nullable','integer'],

            // 🔹 extra header fields
            'sugar_type'     => ['nullable','string','max:25'],
            'crop_year'      => ['nullable','string','max:9'],
            'mill_id'      => ['nullable','string','max:25'],
            'booking_no'     => ['nullable','string','max:50'],
        ]);

        if (empty($data['cp_no'])) {
            $next = $this->generateCpNumber(new Request(['company_id' => $data['company_id']]));
            $data['cp_no'] = $next->getData()->cp_no ?? null;
        }

        $data['purchase_amount'] = 0;
        $data['is_cancel'] = 'n';

        $main = CashPurchase::create($data);

        return response()->json([
            'id'    => $main->id,
            'cp_no' => $main->cp_no,
        ]);
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
            ->when($companyId, fn ($qr) => $qr->where('p.company_id', $companyId))
            ->leftJoin('vendor_list as v', function ($j) use ($companyId) {
                $j->on('v.vendor_id', '=', 'p.vendor_id'); // keep your existing join key
                if ($companyId) $j->where('v.company_id', $companyId);
            })
            ->when($q !== '', function ($qr) use ($qq) {
                $qr->where(function ($w) use ($qq) {
                    $w->whereRaw('LOWER(p.cp_no) LIKE ?',         ["%{$qq}%"])
                      ->orWhereRaw('LOWER(p.vendor_id) LIKE ?',   ["%{$qq}%"])
                      ->orWhereRaw('LOWER(p.invoice_no) LIKE ?',  ["%{$qq}%"])
                      ->orWhereRaw('LOWER(v.vendor_name) LIKE ?', ["%{$qq}%"]);
                });
            })
            ->orderByDesc('p.cp_no')
            ->limit(50)
            ->get([
                'p.id','p.cp_no','p.vendor_id','p.purchase_date','p.purchase_amount','p.invoice_no',
                'p.is_cancel',
                // extra header fields are persisted on the main but not required in the list dropdown
                'p.sugar_type','p.crop_year','p.mill_id','p.booking_no',
                DB::raw("COALESCE(v.vendor_name,'') as vendor_name"),
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
            'id' => ['required','integer','exists:cash_purchase,id'],
            'flag' => ['required','in:0,1'],
        ]);
        $val = $data['flag'] == '1' ? 'y' : 'n';
        CashPurchase::where('id',$data['id'])->update(['is_cancel'=>$val]);
        return response()->json(['ok'=>true,'is_cancel'=>$val]);
    }

    // 10) Print/Download PDF (unchanged)
    public function formPdf(Request $request, $id)
    {
        $header = DB::table('cash_purchase as cp')
            ->join('vendor_list as v', 'cp.vendor_id', '=', 'v.vendor_id')
            ->select(
                'cp.id','cp.cp_no','cp.vendor_id','cp.purchase_amount',
                'cp.explanation','cp.is_cancel','cp.invoice_no',
                DB::raw("to_char(cp.purchase_date, 'MM/DD/YYYY') as purchase_date"),
                'v.vendor_name','cp.workstation_id','cp.user_id','cp.created_at'
            )
            ->where('cp.id', $id)
            ->first();

        if (!$header || $header->is_cancel === 'y') {
            abort(404, 'Purchase Voucher not found or cancelled');
        }

        $details = DB::table('cash_purchase_details as d')
            ->join('account_code as a', 'd.acct_code', '=', 'a.acct_code')
            ->where('d.transaction_id', $id)
            ->orderBy('d.workstation_id','desc')
            ->orderBy('d.credit','desc')
            ->select('d.acct_code','a.acct_desc','d.debit','d.credit')
            ->get();

        $totalDebit  = $details->sum('debit');
        $totalCredit = $details->sum('credit');

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
  <td width="20%" align="left"><font size="10"><b>PV Number:</b></font></td>
  <td width="15%" align="left"><font size="14"><b><u>{$header->cp_no}</u></b></font></td>
</tr>
<tr>
  <td width="65%"></td>
  <td width="20%" align="left"><font size="10"><b>Invoice Date:</b></font></td>
  <td width="15%"><font size="10"><u>{$header->purchase_date}</u></font></td>
</tr>
<tr>
  <td width="65%"></td>
  <td width="20%" align="left"><font size="10"><b>Vendor Invoice #:</b></font></td>
  <td width="15%"><font size="10"><u>{$header->invoice_no}</u></font></td>
</tr>
<tr>
  <td width="15%"><font size="10"><b>VENDOR:</b></font></td>
  <td width="80%" colspan="4"><font size="10"><u>{$header->vendor_name}</u></font></td>
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
                <h2>Cannot print Purchase Voucher</h2>
                <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before printing.</p>
                <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
                number_format($totalDebit, 2),
                number_format($totalCredit, 2)
            );
            return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $pdfContent = $pdf->Output('purchaseVoucher.pdf', 'S');

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="purchaseVoucher.pdf"');
    }

    public function checkPdf($id)  { return response('Check PDF stub – implement renderer', 200); }
    public function formExcel($id) { return response('Excel stub – implement renderer', 200); }

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
                'vendor_id',
                DB::raw('COALESCE(sum_debit,0)  as sum_debit'),
                DB::raw('COALESCE(sum_credit,0) as sum_credit'),
            ]);

        return response()->json(['items' => $rows]);
    }
}
