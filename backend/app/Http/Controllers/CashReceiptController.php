<?php

namespace App\Http\Controllers;

use App\Models\CashReceipts;
use App\Models\CashReceiptDetails;
use App\Models\AccountCode;
use App\Models\CustomerList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// ⬇️ Excel export
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xls  as XlsWriter;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * PDF class (Receipt Voucher)
 * - Draws logo from common locations (jpg/png)
 * - Exposes setters for created date/time text (footer)
 */
class MyReceiptVoucherPDF extends \TCPDF {
    public $receiptDate;
    public $receiptTime;

    public function setDataReceiptDate($date) { $this->receiptDate = $date; }
    public function setDataReceiptTime($time) { $this->receiptTime = $time; }

    public function Header() {
        // Try several common logo locations & extensions
        $candidates = [
            public_path('images/sucdenLogo.jpg'),
            public_path('images/sucdenLogo.png'),
            public_path('sucdenLogo.jpg'),
            public_path('sucdenLogo.png'),
        ];
        foreach ($candidates as $image) {
            if ($image && is_file($image)) {
                // x=15, y=10, width=50 matches your legacy layout
                $this->Image(
                    $image, 15, 10, 50, '', '', '', 'T',
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
    // === 1) Generate next CR number ===
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

        // Auto-insert BANK GL line if resolvable
        $bankAcct = AccountCode::where('bank_id', $data['bank_id'])
            ->where('active_flag', 1)
            ->first(['acct_code']);
        if ($bankAcct) {
            CashReceiptDetails::create([
                'transaction_id' => $main->id,
                'acct_code'      => $bankAcct->acct_code,
                'debit'          => 0,
                'credit'         => 0,
                'workstation_id' => 'BANK',
                'company_id'     => $data['company_id'],
                'user_id'        => $data['user_id'] ?? null,
            ]);
        }

        return response()->json([
            'id'    => $main->id,
            'cr_no' => $main->cr_no,
        ]);
    }


public function updateMain(Request $req)
{
    $data = $req->validate([
        'id'                => ['required','integer','exists:cash_receipts,id'],
        'cust_id'           => ['required','string','max:50'],
        'receipt_date'      => ['required','date'],
        'pay_method'        => ['required','string','max:15'],
        'bank_id'           => ['required','string','max:15'],
        'collection_receipt'=> ['required','string','max:25'],
        'details'           => ['nullable','string','max:1000'],
        'amount_in_words'   => ['nullable','string','max:255'],
        'company_id'        => ['required','integer'],
    ]);

    $id = (int) $data['id'];

    $this->requireNotCancelledOrDeleted($id);
    $this->requireApprovedEdit($req, $id);

    $main = CashReceipts::findOrFail($id);

    // Update header fields
    $main->update([
        'cust_id'            => $data['cust_id'],
        'receipt_date'       => $data['receipt_date'],
        'pay_method'         => $data['pay_method'],
        'bank_id'            => $data['bank_id'],
        'collection_receipt' => $data['collection_receipt'],
        'details'            => $data['details'] ?? '',
        'amount_in_words'    => $data['amount_in_words'] ?? '',
    ]);

    // If bank changed, BANK row must follow the new bank's acct_code
    $bankAcct = AccountCode::where('bank_id', $data['bank_id'])
        ->where('active_flag', 1)
        ->first(['acct_code']);

    if ($bankAcct) {
        $bankRow = CashReceiptDetails::where('transaction_id', $id)
            ->where('workstation_id', 'BANK')
            ->first();

        if ($bankRow) {
            $bankRow->update([
                'acct_code' => $bankAcct->acct_code,
                'credit'    => 0,
            ]);
        } else {
            CashReceiptDetails::create([
                'transaction_id' => $id,
                'acct_code'      => $bankAcct->acct_code,
                'debit'          => 0,
                'credit'         => 0,
                'workstation_id' => 'BANK',
                'company_id'     => $data['company_id'],
            ]);
        }

        // Recompute BANK debit + totals after bank adjustment
        $this->adjustBankDebit($id);
        $totals = $this->recalcTotals($id);
    } else {
        $totals = $this->recalcTotals($id);
    }

    return response()->json([
        'ok'     => true,
        'id'     => $id,
        'totals' => $totals,
    ]);
}

// 🔓 Save Main (NO approval) — header-only update
public function updateMainNoApproval(Request $req)
{
    $data = $req->validate([
        'id'                => ['required','integer','exists:cash_receipts,id'],
        'cust_id'           => ['required','string','max:50'],
        'receipt_date'      => ['required','date'],
        'pay_method'        => ['required','string','max:15'],
        'bank_id'           => ['required','string','max:15'],
        'collection_receipt'=> ['required','string','max:25'],
        'details'           => ['nullable','string','max:1000'],
        'amount_in_words'   => ['nullable','string','max:255'],
        'company_id'        => ['required','integer'],
    ]);

    $id = (int)$data['id'];

    // ✅ SAFETY: do not allow changes when cancelled/deleted
    $this->requireNotCancelledOrDeleted($id);

    $main = CashReceipts::findOrFail($id);

    // ✅ HEADER-ONLY UPDATE (no totals touch, no detail touch)
    $main->update([
        'cust_id'            => $data['cust_id'],
        'receipt_date'       => $data['receipt_date'],
        'pay_method'         => $data['pay_method'],
        'bank_id'            => $data['bank_id'],
        'collection_receipt' => $data['collection_receipt'],
        'details'            => $data['details'] ?? '',
        'amount_in_words'    => $data['amount_in_words'] ?? '',
    ]);

    // ✅ If bank changed, keep BANK row acct_code in sync (same as updateMain)
    $bankAcct = AccountCode::where('bank_id', $data['bank_id'])
        ->where('active_flag', 1)
        ->first(['acct_code']);

    if ($bankAcct) {
        $bankRow = CashReceiptDetails::where('transaction_id', $id)
            ->where('workstation_id', 'BANK')
            ->first();

        if ($bankRow) {
            $bankRow->update([
                'acct_code' => $bankAcct->acct_code,
                'credit'    => 0,
            ]);
        } else {
            CashReceiptDetails::create([
                'transaction_id' => $id,
                'acct_code'      => $bankAcct->acct_code,
                'debit'          => 0,
                'credit'         => 0,
                'workstation_id' => 'BANK',
                'company_id'     => $data['company_id'],
            ]);
        }

        // Keep BANK debit correct after bank change (safe even if details unchanged)
        $this->adjustBankDebit($id);
        $totals = $this->recalcTotals($id);
    } else {
        $totals = $this->recalcTotals($id);
    }

    return response()->json([
        'ok'     => true,
        'id'     => $id,
        'totals' => $totals,
    ]);
}



    // === 3) Insert a detail row ===
    public function saveDetail(Request $req)
    {
        $payload = $req->validate([
            'transaction_id' => ['required','integer','exists:cash_receipts,id'],
            'acct_code'      => ['required','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
            'workstation_id' => ['nullable','string','max:25'],
            'user_id'        => ['nullable','integer'],
        ]);
        
$this->requireNotCancelledOrDeleted((int)$payload['transaction_id']);
$this->requireApprovedEdit($req, (int)$payload['transaction_id']);

        $debit  = (float)($payload['debit']  ?? 0);
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

        $dup = CashReceiptDetails::where('transaction_id',$payload['transaction_id'])
            ->where('acct_code',$payload['acct_code'])
            ->exists();
        if ($dup) return response()->json(['message' => 'Duplicate account code for this transaction.'], 422);

        $companyId = CashReceipts::where('id', $payload['transaction_id'])->value('company_id');

        $detail = CashReceiptDetails::create([
            'transaction_id' => $payload['transaction_id'],
            'acct_code'      => $payload['acct_code'],
            'debit'          => $debit,
            'credit'         => $credit,
            'workstation_id' => $payload['workstation_id'] ?? null,
            'user_id'        => $payload['user_id'] ?? null,
            'company_id'     => $companyId,
        ]);

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

$this->requireNotCancelledOrDeleted((int)$payload['transaction_id']);
$this->requireApprovedEdit($req, (int)$payload['transaction_id']);

        $detail = CashReceiptDetails::find($payload['id']);
        if (!$detail) return response()->json(['message' => 'Detail not found.'], 404);

        $apply = [];
        if (array_key_exists('acct_code', $payload)) $apply['acct_code'] = $payload['acct_code'];
        if (array_key_exists('debit', $payload))     $apply['debit']     = $payload['debit'];
        if (array_key_exists('credit', $payload))    $apply['credit']    = $payload['credit'];

        if (array_key_exists('debit',$apply) && array_key_exists('credit',$apply)) {
            $d = (float)$apply['debit']; $c = (float)$apply['credit'];
            if ($d > 0 && $c > 0) return response()->json(['message'=>'Provide either debit OR credit.'], 422);
            if ($d <= 0 && $c <= 0) return response()->json(['message'=>'Debit or credit is required.'], 422);
        }

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

$this->requireNotCancelledOrDeleted((int)$payload['transaction_id']);
$this->requireApprovedEdit($req, (int)$payload['transaction_id']);

        $row = CashReceiptDetails::find($payload['id']);
        if ($row && ($row->workstation_id === 'BANK')) {
            return response()->json(['message' => 'Cannot delete the bank line.'], 422);
        }

        CashReceiptDetails::where('id',$payload['id'])->delete();

        $this->adjustBankDebit($payload['transaction_id']);
        $totals = $this->recalcTotals($payload['transaction_id']);

        return response()->json(['ok'=>true,'totals'=>$totals]);
    }

    // === 6) Show main + details ===
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

    // === 7) Search list ===
    public function list(Request $req)
    {
        $companyId = $req->query('company_id');
        $q  = trim((string) $req->query('q', ''));
        $qq = strtolower($q);

        $rows = CashReceipts::from('cash_receipts as r')
            ->when($companyId, fn ($qr) => $qr->where('r.company_id', $companyId))
            ->whereIn('r.is_cancel', ['n', 'c'])
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

    // === 8) Dropdowns ===
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

    // legacy endpoint: use c/n
    $val = $data['flag'] === '1' ? 'c' : 'n';

    CashReceipts::where('id', (int)$data['id'])->update(['is_cancel' => $val]);

    return response()->json(['ok' => true, 'is_cancel' => $val]);
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

if (!$header || in_array($header->is_cancel, ['c','d'], true)) {
    abort(404, 'Receipt Voucher not found or cancelled');
}


        $details = DB::table('cash_receipt_details as d')
            ->join('account_code as a', 'd.acct_code', '=', 'a.acct_code')
            ->where('d.transaction_id', $id)
            ->orderBy('d.workstation_id','desc') // BANK row first
            ->orderBy('d.debit','desc')
            ->select('d.acct_code','a.acct_desc','d.debit','d.credit')
            ->get();

        $totalDebit  = (float)$details->sum('debit');
        $totalCredit = (float)$details->sum('credit');

        // Amount in words must reflect the figure printed
        $receiptAmount   = (float)($header->receipt_amount ?? 0);
        $amountInWords   = $this->pesoWords($receiptAmount);
        $receiptAmtFmt   = number_format($receiptAmount, 2);

        $pdf = new MyReceiptVoucherPDF('P','mm','LETTER',true,'UTF-8',false);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setImageScale(1.25);
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
    <td align="right"><font size="10">{$receiptAmtFmt}</font></td>
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

    // === 10b) Excel (XLSX default; ?format=xls optional) ===
    public function formExcel(Request $req, $id)
    {
        $header = DB::table('cash_receipts as r')
            ->leftJoin('customer_list as c', 'r.cust_id', '=', 'c.cust_id')
            ->select(
                'r.id','r.cr_no','r.cust_id','r.receipt_amount','r.pay_method',
                'r.bank_id','r.details','r.is_cancel','r.collection_receipt',
                DB::raw("to_char(r.receipt_date, 'MM/DD/YYYY') as receipt_date"),
                'c.cust_name','r.amount_in_words','r.created_at'
            )
            ->where('r.id', $id)
            ->first();

if (!$header || in_array($header->is_cancel, ['c','d'], true)) {
    abort(404, 'Receipt Voucher not found or cancelled');
}


        $details = DB::table('cash_receipt_details as d')
            ->join('account_code as a', 'd.acct_code', '=', 'a.acct_code')
            ->where('d.transaction_id', $id)
            ->orderBy('d.workstation_id','desc') // BANK row first
            ->orderBy('d.debit','desc')
            ->select('d.acct_code','a.acct_desc','d.debit','d.credit')
            ->get();

        $totalDebit  = (float)$details->sum('debit');
        $totalCredit = (float)$details->sum('credit');
        $receiptAmount = (float)($header->receipt_amount ?? 0);
        $amountInWords = $this->pesoWords($receiptAmount);

        // Build spreadsheet
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Receipt Voucher');

        $row = 1;
        $sheet->setCellValue("A{$row}", 'RECEIPT VOUCHER'); $row++;
        $sheet->setCellValue("A{$row}", 'RV Number:');      $sheet->setCellValue("B{$row}", $header->cr_no); $row++;
        $sheet->setCellValue("A{$row}", 'Date:');           $sheet->setCellValue("B{$row}", $header->receipt_date); $row++;
        $sheet->setCellValue("A{$row}", 'Receipt Number:'); $sheet->setCellValue("B{$row}", $header->collection_receipt); $row++;
        $sheet->setCellValue("A{$row}", 'Payor:');          $sheet->setCellValue("B{$row}", (string)($header->cust_name ?? $header->cust_id)); $row++;
        $sheet->setCellValue("A{$row}", 'Amount (in words):'); $sheet->setCellValue("B{$row}", $amountInWords); $row += 2;

        // Details box
        $sheet->setCellValue("A{$row}", 'DETAILS');
        $sheet->setCellValue("B{$row}", 'AMOUNT');
        $sheet->getStyle("A{$row}:B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$row}:B{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;

        $sheet->setCellValue("A{$row}", (string)($header->details ?? ''));
        $sheet->setCellValue("B{$row}", $receiptAmount);
        $sheet->getStyle("A{$row}:B{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $row += 2;

        // GL lines
        $sheet->setCellValue("A{$row}", 'ACCOUNT');
        $sheet->setCellValue("B{$row}", 'GL ACCOUNT');
        $sheet->setCellValue("C{$row}", 'DEBIT');
        $sheet->setCellValue("D{$row}", 'CREDIT');
        $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$row}:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row++;

        foreach ($details as $d) {
            $sheet->setCellValue("A{$row}", $d->acct_code);
            $sheet->setCellValue("B{$row}", $d->acct_desc);
            if ($d->debit)  $sheet->setCellValue("C{$row}", (float)$d->debit);
            if ($d->credit) $sheet->setCellValue("D{$row}", (float)$d->credit);
            $sheet->getStyle("A{$row}:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("C{$row}:D{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }

        // Totals
        $sheet->setCellValue("B{$row}", 'TOTAL');
        $sheet->setCellValue("C{$row}", $totalDebit);
        $sheet->setCellValue("D{$row}", $totalCredit);
        $sheet->getStyle("A{$row}:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("C{$row}:D{$row}")->getNumberFormat()->setFormatCode('#,##0.00');

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(14);

        // Bold headings
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1:D1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        foreach (['A2','A3','A4','A5','A6'] as $addr) {
            $sheet->getStyle($addr)->getFont()->setBold(true);
        }

        // Stream to browser
        $format = strtolower((string)$req->query('format','xlsx'));
        $fileBase = 'receipt-voucher-'.($header->cr_no ?: $id);
        if ($format === 'xls') {
            $writer = new XlsWriter($ss);
            $filename = $fileBase.'.xls';
            $contentType = 'application/vnd.ms-excel';
        } else {
            $writer = new XlsxWriter($ss);
            $filename = $fileBase.'.xlsx';
            $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        // Output buffer clean (Octane/Swoole safe)
        if (ob_get_length()) { ob_end_clean(); }

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control'       => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
    }

    // === 11) Unbalanced helpers ===
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
    protected function adjustBankDebit(int $transactionId): void
    {
        $main = CashReceipts::find($transactionId);
        if (!$main) return;

        $bankAcct = AccountCode::where('bank_id', $main->bank_id)
            ->where('active_flag', 1)
            ->first(['acct_code']);

        if (!$bankAcct) return;

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
                'company_id'     => $main->company_id,
            ]);
        } elseif ($bankRow->company_id === null) {
            $bankRow->update(['company_id' => $main->company_id]);
        }

        $sumCredit = (float) CashReceiptDetails::where('transaction_id', $transactionId)->sum('credit');
        $sumDebitExBank = (float) CashReceiptDetails::where('transaction_id', $transactionId)
            ->where('id', '<>', $bankRow->id)->sum('debit');

        $newBankDebit = max(0, $sumCredit - $sumDebitExBank);
        $bankRow->update(['debit' => $newBankDebit, 'credit' => 0]);
    }


/**
 * Require an ACTIVE approved EDIT approval window for this cash receipt.
 * Blocks direct API calls that try to bypass approvals.
 */
private function requireApprovedEdit(Request $req, int $transactionId): void
{
    $companyId = $req->input('company_id') ?? $req->query('company_id');

    $row = DB::table('approvals')
        ->where('module', 'cash_receipts')
        ->where('record_id', $transactionId)
        ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
        ->where('action', 'edit')
        ->where('status', 'approved')
        ->whereNull('consumed_at')
        ->whereNotNull('expires_at')
        ->where('expires_at', '>', now())
        ->orderByDesc('id')
        ->first();

    if (!$row) {
        abort(403, 'Edit requires an active approval.');
    }
}

/**
 * Helper to prevent edits on cancelled/deleted records.
 */
private function requireNotCancelledOrDeleted(int $transactionId): void
{
    $flag = CashReceipts::where('id', $transactionId)->value('is_cancel');
    if (in_array($flag, ['c', 'd'], true)) {
        abort(409, 'Transaction is cancelled/deleted and cannot be edited.');
    }
}

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