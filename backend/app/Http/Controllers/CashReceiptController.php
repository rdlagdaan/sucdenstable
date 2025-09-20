<?php

namespace App\Http\Controllers;

use App\Models\CashReceipts;
use App\Models\CashReceiptDetails;
use App\Models\AccountCode;
use App\Models\CustomerList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * PDF class (Receipt Voucher) – matches your SalesJournal TCPDF style and
 * the legacy RV layout: title, RV number, date, collection receipt, payor,
 * amount (in words), details, and GL lines.
 */
/**
 * PDF class (Receipt Voucher)
 * - Draws logo from public/images/sucdenLogo.jpg OR public/sucdenLogo.jpg
 * - Exposes setters for created date/time text (used in the footer)
 */
class MyReceiptVoucherPDF extends \TCPDF {
    public $receiptDate;
    public $receiptTime;

    public function setDataReceiptDate($date) { $this->receiptDate = $date; }
    public function setDataReceiptTime($time) { $this->receiptTime = $time; }

    public function Header() {
        // Try both common locations
        $candidates = [
            public_path('images/sucdenLogo.jpg'),
            public_path('sucdenLogo.jpg'),
        ];
        foreach ($candidates as $image) {
            if ($image && is_file($image)) {
                // x=15, y=10, width=50 matches your legacy layout
                $this->Image(
                    $image, 15, 10, 50, '', 'JPG', '', 'T',
                    false, 300, '', false, false, 0, false, false, false
                );
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
              <td><font size="8">Checked:<br><br><br><br><br></font></td>
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
            <td><font size="8">'.($this->receiptDate ?? '').'</font></td>
            <td><font size="8">'.($this->receiptTime ?? '').'</font></td>
            <td></td>
          </tr>
        </table>';
        $this->writeHTML($html, true, false, false, false, '');
    }
}


class CashReceiptController extends Controller
{
    // === 1) Generate next CR number (incremental, numeric-like) ===
    public function generateCrNumber(Request $req)
    {
        $companyId = $req->query('company_id');

        $last = CashReceipts::when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderBy('cr_no', 'desc')
            ->value('cr_no');

        $base = is_numeric($last) ? (int)$last : 800000;
        return response()->json(['cr_no' => (string)($base + 1)]);
    }

    // === 2) Create main header (and auto-insert the BANK row if possible) ===
    public function storeMain(Request $req)
    {
        $data = $req->validate([
            'cr_no'              => ['nullable','string','max:25'],
            'cust_id'            => ['required','string','max:50'],
            'receipt_date'       => ['required','date'],
            'pay_method'         => ['required','string','max:15'],
            'bank_id'            => ['required','string','max:15'],
            'collection_receipt' => ['required','string','max:25'],
            'details'            => ['nullable','string','max:1000'],
            'amount_in_words'    => ['nullable','string','max:255'],
            'company_id'         => ['required','integer'],
            'workstation_id'     => ['nullable','string','max:25'],
            'user_id'            => ['nullable','integer'],
        ]);

        // Generate CR number if missing
        if (empty($data['cr_no'])) {
            $next = $this->generateCrNumber(new Request(['company_id' => $data['company_id']]));
            $data['cr_no'] = $next->getData()->cr_no ?? null;
        }

        $data['is_cancel']      = 'n';
        $data['receipt_amount'] = 0;
        $data['sum_debit']      = 0;
        $data['sum_credit']     = 0;
        $data['is_balanced']    = false;

        $main = CashReceipts::create($data);

        // Auto-insert first detail = BANK GL (if resolvable from bank_id -> account_code.bank_id)
        $bankAcct = AccountCode::where('bank_id', $data['bank_id'])
            ->where('active_flag', 1)
            ->first(['acct_code']);
        if ($bankAcct) {
            CashReceiptDetails::create([
                'transaction_id' => $main->id,
                'acct_code'      => $bankAcct->acct_code,
                'debit'          => 0,
                'credit'         => 0,
                'workstation_id' => 'BANK',     // marker to identify the bank line
                'company_id'     => $data['company_id'], 
                'user_id'    => $data['user_id'] ?? null,
            ]);
        }

        return response()->json([
            'id'    => $main->id,
            'cr_no' => $main->cr_no,
        ]);
    }

    // === 3) Insert a detail row (enforce one-sided amount, valid acct, no duplicates) ===
    public function saveDetail(Request $req)
    {
        $payload = $req->validate([
            'transaction_id' => ['required','integer','exists:cash_receipts,id'],
            'acct_code'      => ['required','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
            'workstation_id' => ['nullable','string','max:25'],
            'user_id'    => ['nullable','integer'],
        ]);

        $debit  = (float)($payload['debit']  ?? 0);
        $credit = (float)($payload['credit'] ?? 0);
        if ($debit > 0 && $credit > 0) {
            return response()->json(['message' => 'Provide either debit or credit, not both.'], 422);
        }
        if ($debit <= 0 && $credit <= 0) {
            return response()->json(['message' => 'Debit or credit is required.'], 422);
        }

        // Valid and active account?
        $exists = AccountCode::where('acct_code', $payload['acct_code'])
            ->where('active_flag', 1)
            ->exists();
        if (!$exists) return response()->json(['message' => 'Invalid or inactive account.'], 422);

        // No duplicate acct_code in same transaction
        $dup = CashReceiptDetails::where('transaction_id',$payload['transaction_id'])
            ->where('acct_code',$payload['acct_code'])
            ->exists();
        if ($dup) return response()->json(['message' => 'Duplicate account code for this transaction.'], 422);

        $companyId = CashReceipts::where('id', $payload['transaction_id'])->value('company_id');

        $detail = CashReceiptDetails::create([
            'transaction_id' => $payload['transaction_id'],
            'acct_code'      => $payload['acct_code'],
            'debit'          => $debit,   // from your computed values above
            'credit'         => $credit,  // from your computed values above
            'workstation_id' => $payload['workstation_id'] ?? null,
            'user_id'        => $payload['user_id'] ?? null,
            'company_id'     => $companyId,   // <-- IMPORTANT
        ]);

        // Adjust BANK debit line after any change and recalc totals
        $this->adjustBankDebit($payload['transaction_id']);
        $totals = $this->recalcTotals($payload['transaction_id']);

        return response()->json([
            'detail_id' => $detail->id,
            'totals'    => $totals,
        ]);
    }

    // === 4) Update a detail row ===
    public function updateDetail(Request $req)
    {
        $payload = $req->validate([
            'id'             => ['required','integer','exists:cash_receipt_details,id'],
            'transaction_id' => ['required','integer','exists:cash_receipts,id'],
            'acct_code'      => ['nullable','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
        ]);

        $detail = CashReceiptDetails::find($payload['id']);
        if (!$detail) return response()->json(['message' => 'Detail not found.'], 404);

        $apply = [];
        if (array_key_exists('acct_code', $payload)) $apply['acct_code'] = $payload['acct_code'];
        if (array_key_exists('debit', $payload))     $apply['debit']     = $payload['debit'];
        if (array_key_exists('credit', $payload))    $apply['credit']    = $payload['credit'];

        // If both provided, must be one-sided
        if (array_key_exists('debit',$apply) && array_key_exists('credit',$apply)) {
            $d = (float)$apply['debit']; $c = (float)$apply['credit'];
            if ($d > 0 && $c > 0) return response()->json(['message'=>'Provide either debit OR credit.'], 422);
            if ($d <= 0 && $c <= 0) return response()->json(['message'=>'Debit or credit is required.'], 422);
        }

        // If acct_code is changing, validate & prevent duplicates
        if (array_key_exists('acct_code', $apply) && $apply['acct_code'] !== $detail->acct_code) {
            $exists = AccountCode::where('acct_code', $apply['acct_code'])
                ->where('active_flag', 1)->exists();
            if (!$exists) return response()->json(['message' => 'Invalid or inactive account.'], 422);
            $dup = CashReceiptDetails::where('transaction_id', $payload['transaction_id'])
                ->where('acct_code', $apply['acct_code'])->exists();
            if ($dup) return response()->json(['message' => 'Duplicate account code for this transaction.'], 422);
        }

        $detail->update($apply);

        $this->adjustBankDebit($payload['transaction_id']);
        $totals = $this->recalcTotals($payload['transaction_id']);

        return response()->json(['ok'=>true,'totals'=>$totals]);
    }

    // === 5) Delete a detail row ===
    public function deleteDetail(Request $req)
    {
        $payload = $req->validate([
            'id'             => ['required','integer','exists:cash_receipt_details,id'],
            'transaction_id' => ['required','integer','exists:cash_receipts,id'],
        ]);

        // Prevent deleting the BANK row (workstation_id='BANK')
        $row = CashReceiptDetails::find($payload['id']);
        if ($row && ($row->workstation_id === 'BANK')) {
            return response()->json(['message' => 'Cannot delete the bank line.'], 422);
        }

        CashReceiptDetails::where('id',$payload['id'])->delete();

        $this->adjustBankDebit($payload['transaction_id']);
        $totals = $this->recalcTotals($payload['transaction_id']);

        return response()->json(['ok'=>true,'totals'=>$totals]);
    }

    // === 6) Show main + details (for Search Transaction) ===
    public function show($id, Request $req)
    {
        $companyId = $req->query('company_id');

        $main = CashReceipts::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->findOrFail($id);

        $details = CashReceiptDetails::where('transaction_id',$main->id)
            ->leftJoin('account_code','cash_receipt_details.acct_code','=','account_code.acct_code')
            ->orderBy('cash_receipt_details.id')
            ->get([
                'cash_receipt_details.id',
                'cash_receipt_details.transaction_id',
                'cash_receipt_details.acct_code',
                DB::raw('COALESCE(account_code.acct_desc, \'\') as acct_desc'),
                'cash_receipt_details.debit',
                'cash_receipt_details.credit',
                'cash_receipt_details.workstation_id',
            ]);

        return response()->json(['main'=>$main,'details'=>$details]);
    }

    // === 7) Search list for combobox (search by rv/cr no, customer, bank, etc.) ===
    public function list(Request $req)
    {
        $companyId = $req->query('company_id');
        $q  = trim((string) $req->query('q', ''));
        $qq = strtolower($q);

        $rows = CashReceipts::from('cash_receipts as r')
            ->when($companyId, fn ($qr) => $qr->where('r.company_id', $companyId))
            ->leftJoin('customer_list as c', function ($j) use ($companyId) {
                $j->on('c.cust_id', '=', 'r.cust_id');
                if ($companyId) $j->where('c.company_id', $companyId);
            })
            ->when($q !== '', function ($qr) use ($qq) {
                $qr->where(function ($w) use ($qq) {
                    $w->whereRaw('LOWER(r.cr_no) LIKE ?',               ["%{$qq}%"])
                      ->orWhereRaw('LOWER(r.cust_id) LIKE ?',           ["%{$qq}%"])
                      ->orWhereRaw('LOWER(r.collection_receipt) LIKE ?',["%{$qq}%"])
                      ->orWhereRaw('LOWER(c.cust_name) LIKE ?',         ["%{$qq}%"]);
                });
            })
            ->orderByDesc('r.cr_no')
            ->limit(50)
            ->get([
                'r.id','r.cr_no','r.cust_id','r.receipt_date','r.receipt_amount',
                'r.bank_id','r.collection_receipt','r.is_cancel',
                DB::raw("COALESCE(c.cust_name,'') as cust_name"),
            ]);

        return response()->json($rows);
    }

    // === 8) Dropdowns (customers, accounts, banks, payment methods) ===
    public function customers(Request $req)
    {
        $companyId = $req->query('company_id');
        $rows = CustomerList::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->orderBy('cust_name')
            ->get(['cust_id','cust_name']);

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
            ->get(['acct_code','acct_desc','bank_id']);

        return response()->json($rows);
    }

    public function banks(Request $req)
    {
        $companyId = $req->query('company_id');
        $rows = DB::table('bank')
            ->when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->orderBy('bank_name')
            ->get(['bank_id','bank_name']);

        // shape for dropdown-with-headers
        $items = $rows->map(fn($r)=>[
            'code' => $r->bank_id,
            'label' => $r->bank_id,
            'description' => $r->bank_name,
            'bank_id' => $r->bank_id,
            'bank_name' => $r->bank_name,
        ]);
        return response()->json($items);
    }

    public function paymentMethods(Request $req)
    {
        $rows = DB::table('payment_method')
            ->orderBy('pay_method')
            ->get(['pay_method_id','pay_method']);

        $items = $rows->map(fn($r)=>[
            'code' => $r->pay_method_id,
            'label' => $r->pay_method_id,
            'description' => $r->pay_method,
            'pay_method_id' => $r->pay_method_id,
            'pay_method' => $r->pay_method,
        ]);
        return response()->json($items);
    }

    // === 9) Cancel / Uncancel ===
    public function updateCancel(Request $req)
    {
        $data = $req->validate([
            'id'   => ['required','integer','exists:cash_receipts,id'],
            'flag' => ['required','in:0,1'],
        ]);
        $val = $data['flag'] == '1' ? 'y' : 'n';
        CashReceipts::where('id',$data['id'])->update(['is_cancel'=>$val]);
        return response()->json(['ok'=>true,'is_cancel'=>$val]);
    }


    /** Convert 0..999 into words (english) */
    private function chunkToWords(int $n): string {
        $ones = ['', 'one','two','three','four','five','six','seven','eight','nine','ten',
            'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
        $tens = ['', '', 'twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];

        $words = '';
        $hundred = intdiv($n, 100);
        $rest = $n % 100;

        if ($hundred) $words .= $ones[$hundred] . ' hundred' . ($rest ? ' ' : '');
        if ($rest) {
            if ($rest < 20) $words .= $ones[$rest];
            else {
                $words .= $tens[intdiv($rest,10)];
                $onesDigit = $rest % 10;
                if ($onesDigit) $words .= '-' . $ones[$onesDigit];
            }
        }
        return $words;
    }

    /** Convert an integer to words with scales (thousand, million, …) */
    private function numberToWordsInt(int $n): string {
        if ($n === 0) return 'zero';
        $scales = ['', ' thousand', ' million', ' billion', ' trillion'];
        $words = '';
        $scale = 0;
        while ($n > 0) {
            $chunk = $n % 1000;
            if ($chunk) {
                $chunkWords = $this->chunkToWords($chunk) . $scales[$scale];
                $words = $chunkWords . ($words ? ' ' . $words : '');
            }
            $n = intdiv($n, 1000);
            $scale++;
        }
        return $words;
    }

    /** Format amount into "PESO WORDS AND CC/100 ONLY" (uppercased) */
    private function pesoWords(float $amount): string {
        $int = (int) floor($amount + 0.0000001);
        $cents = (int) round(($amount - $int) * 100);
        $words = strtoupper($this->numberToWordsInt($int));
        $tail  = $cents === 0 ? ' PESOS ONLY' : sprintf(' PESOS AND %02d/100 ONLY', $cents);
        return $words . $tail;
    }




    // === 10) Print Receipt Voucher (PDF) ===
// === 10) Print Receipt Voucher (PDF) ===
public function formPdf(Request $req, $id)
{
    $header = DB::table('cash_receipts as r')
        ->leftJoin('customer_list as c', 'r.cust_id', '=', 'c.cust_id')
        ->select(
            'r.id','r.cr_no','r.cust_id','r.receipt_amount','r.pay_method',
            'r.bank_id','r.details','r.is_cancel','r.collection_receipt',
            DB::raw("to_char(r.receipt_date, 'MM/DD/YYYY') as receipt_date"),
            'c.cust_name','r.amount_in_words','r.workstation_id','r.user_id','r.created_at'
        )
        ->where('r.id', $id)
        ->first();

    if (!$header || $header->is_cancel === 'y') {
        abort(404, 'Receipt Voucher not found or cancelled');
    }

    $details = DB::table('cash_receipt_details as d')
        ->join('account_code as a', 'd.acct_code', '=', 'a.acct_code')
        ->where('d.transaction_id', $id)
        ->orderBy('d.workstation_id','desc') // BANK row first (to mimic legacy)
        ->orderBy('d.debit','desc')
        ->select('d.acct_code','a.acct_desc','d.debit','d.credit')
        ->get();

    $totalDebit  = (float)$details->sum('debit');
    $totalCredit = (float)$details->sum('credit');

    // ✅ Amount in words MUST follow receipt_amount (figures shown)
    $receiptAmount = (float)($header->receipt_amount ?? 0);
    $amountInWords = $this->pesoWords($receiptAmount); // <-- compute from figures
    $receiptAmountFmt = number_format($receiptAmount, 2);

    $pdf = new MyReceiptVoucherPDF('P','mm','LETTER',true,'UTF-8',false);
    $pdf->setPrintHeader(true);   // <-- ensure Header() runs (logo)
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(15,30,15);   // left, top, right
    $pdf->SetHeaderMargin(8);
    $pdf->SetFooterMargin(10);
    $pdf->AddPage();
    $pdf->SetFont('helvetica','',7);

    $pdf->setDataReceiptDate(\Carbon\Carbon::parse($header->created_at)->format('M d, Y'));
    $pdf->setDataReceiptTime(\Carbon\Carbon::parse($header->created_at)->format('h:i:sa'));

    $rvNumber        = $header->cr_no;
    $receiptDateText = $header->receipt_date;
    $collectionNo    = $header->collection_receipt;
    $custName        = (string)($header->cust_name ?? $header->cust_id);
    $explanation     = (string)($header->details ?? '');

    // Body
    $tbl = <<<EOD
<br><br>
<table border="0" cellpadding="1" cellspacing="0" nobr="true" width="100%">
<tr>
  <td width="10%"></td>
  <td width="20%"></td>
  <td width="20%"></td>
  <td width="50%" colspan="2" align="left"><div><font size="16"><b>RECEIPT VOUCHER</b></font></div></td>
</tr>
<tr><td colspan="5"></td></tr>
<tr>
  <td width="10%"></td><td width="20%"></td><td width="25%"></td>
  <td width="31%" align="left" valign="middle" height="30"><font size="14"><b>RV Number:</b></font></td>
  <td width="14%" align="left"><font size="18"><b><u>{$rvNumber}</u></b></font></td>
</tr>
<tr>
  <td width="10%"></td><td width="20%"></td><td width="25%"></td>
  <td width="31%" align="left"><font size="10"><b>Date:</b></font></td>
  <td width="14%" align="left"><font size="10"><u>{$receiptDateText}</u></font></td>
</tr>
<tr>
  <td width="10%"></td><td width="20%"></td><td width="25%"></td>
  <td width="31%" align="left"><font size="12"><b>Receipt Number:</b></font></td>
  <td width="14%" align="left"><font size="12"><u>{$collectionNo}</u></font></td>
</tr>
<tr>
  <td width="15%"><font size="10"><b>PAYOR</b></font></td>
  <td width="80%" colspan="4"><font size="14"><u>{$custName}</u></font></td>
</tr>
<tr>
  <td width="15%"><font size="10"><b>AMOUNT:</b></font></td>
  <td width="80%" colspan="4"><font size="10"><u>{$amountInWords}</u></font></td>
</tr>
</table>

<table><tr><td><br><br></td></tr></table>
<table border="1" cellspacing="0" cellpadding="5">
  <tr>
    <td width="70%" align="center"><font size="10"><b>DETAILS</b></font></td>
    <td width="30%" align="center"><font size="10"><b>AMOUNT</b></font></td>
  </tr>
  <tr>
    <td height="80"><font size="10">{$explanation}</font></td>
    <td align="right"><font size="10">{$receiptAmountFmt}</font></td>
  </tr>
</table>

<table><tr><td><br><br></td></tr></table>
<table border="1" cellpadding="3" cellspacing="0" nobr="true" width="100%">
 <tr>
  <td width="20%" align="center"><font size="10"><b>ACCOUNT</b></font></td>
  <td width="40%" align="center"><font size="10"><b>GL ACCOUNT</b></font></td>
  <td width="20%" align="center"><font size="10"><b>DEBIT</b></font></td>
  <td width="20%" align="center"><font size="10"><b>CREDIT</b></font></td>
 </tr>
EOD;

    foreach ($details as $d) {
        $debit  = $d->debit  ? number_format((float)$d->debit, 2) : '';
        $credit = $d->credit ? number_format((float)$d->credit, 2) : '';
        $tbl .= <<<EOD
  <tr>
    <td align="left"><font size="10">{$d->acct_code}</font></td>
    <td align="left"><font size="10">{$d->acct_desc}</font></td>
    <td align="right"><font size="10">{$debit}</font></td>
    <td align="right"><font size="10">{$credit}</font></td>
  </tr>
EOD;
    }

    $fmtD = number_format($totalDebit, 2);
    $fmtC = number_format($totalCredit, 2);
    $tbl .= <<<EOD
  <tr>
    <td></td>
    <td align="left"><font size="10">TOTAL</font></td>
    <td align="right"><font size="10">{$fmtD}</font></td>
    <td align="right"><font size="10">{$fmtC}</font></td>
  </tr>
</table>
EOD;

    $pdf->writeHTML($tbl, true, false, false, false, '');

    // Don’t allow printing if not balanced
    if (abs($totalDebit - $totalCredit) > 0.005) {
        $html = sprintf(
            '<!doctype html><meta charset="utf-8">
            <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
            <h2>Cannot print Receipt Voucher</h2>
            <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before printing.</p>
            <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
            number_format($totalDebit, 2),
            number_format($totalCredit, 2)
        );
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    $pdfContent = $pdf->Output('receiptVoucher.pdf', 'S');

    return response($pdfContent, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="receiptVoucher.pdf"');
}


    public function formExcel($id) { return response('Excel stub – implement renderer', 200); }

    // === 11) Unbalanced helpers (optional parity with Sales Journal) ===
    public function unbalancedExists(Request $req)
    {
        $companyId = $req->query('company_id');
        $exists = CashReceipts::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->where('is_balanced', false)
            ->exists();

        return response()->json(['exists' => $exists]);
    }

    public function unbalanced(Request $req)
    {
        $companyId = $req->query('company_id');
        $limit = (int) $req->query('limit', 20);

        $rows = CashReceipts::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->where('is_balanced', false)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get([
                'id','cr_no','cust_id',
                DB::raw('COALESCE(sum_debit,0)  as sum_debit'),
                DB::raw('COALESCE(sum_credit,0) as sum_credit'),
            ]);

        return response()->json(['items' => $rows]);
    }

    // ---- Private helpers ----

    /**
     * Adjust the BANK row debit so that:
     *   bank_debit = (sum of credits) - (sum of debits excluding bank row)
     * Auto-inserts the bank row if it’s missing and a mapping exists for bank_id.
     */
    protected function adjustBankDebit(int $transactionId): void
    {
        $main = CashReceipts::find($transactionId);
        if (!$main) return;

        // Resolve bank acct_code
        $bankAcct = AccountCode::where('bank_id', $main->bank_id)
            ->where('active_flag', 1)
            ->first(['acct_code']);

        if (!$bankAcct) return;

        // Find (or create) the BANK row
        $bankRow = CashReceiptDetails::where('transaction_id', $transactionId)
            ->where('acct_code', $bankAcct->acct_code)
            ->first();

        if (!$bankRow) {
            $bankRow = CashReceiptDetails::create([
                'transaction_id' => $transactionId,
                'acct_code'      => $bankAcct->acct_code,
                'debit'          => 0,
                'credit'         => 0,
                'workstation_id' => 'BANK',
                'user_id'        => $main->user_id ?? null,
                'company_id'     => $main->company_id,   // <-- IMPORTANT
            ]);
        } elseif ($bankRow->company_id === null) {
            // backfill if an old BANK row exists without company_id
            $bankRow->update(['company_id' => $main->company_id]);
        }


        $sumCredit = (float) CashReceiptDetails::where('transaction_id', $transactionId)
            ->sum('credit');

        $sumDebitExBank = (float) CashReceiptDetails::where('transaction_id', $transactionId)
            ->where('id', '<>', $bankRow->id)
            ->sum('debit');

        $newBankDebit = max(0, $sumCredit - $sumDebitExBank);

        $bankRow->update(['debit' => $newBankDebit, 'credit' => 0]);
    }

    /**
     * Recalc totals and update main:
     *   - receipt_amount = sum_credit (per requirement)
     *   - sum_debit, sum_credit, is_balanced
     */
    protected function recalcTotals(int $transactionId): array
    {
        $tot = CashReceiptDetails::where('transaction_id',$transactionId)
            ->selectRaw('COALESCE(SUM(debit),0) as sum_debit, COALESCE(SUM(credit),0) as sum_credit')
            ->first();

        $sumDebit  = round((float)$tot->sum_debit, 2);
        $sumCredit = round((float)$tot->sum_credit, 2);
        $balanced  = abs($sumDebit - $sumCredit) < 0.005;

        CashReceipts::where('id',$transactionId)->update([
            'receipt_amount' => $sumCredit,   // amount box mirrors total CREDIT
            'sum_debit'      => $sumDebit,
            'sum_credit'     => $sumCredit,
            'is_balanced'    => $balanced,
        ]);

        return ['debit'=>$sumDebit,'credit'=>$sumCredit,'balanced'=>$balanced];
    }
}
