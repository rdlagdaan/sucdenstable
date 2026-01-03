<?php

namespace App\Http\Controllers;

use App\Models\CashDisbursement;
use App\Models\CashDisbursementDetail;
use App\Models\AccountCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema; 
/**
 * PDF class (Disbursement/Check Voucher)
 * - Uses same header/footer style as your Receipt Voucher
 * - Shows DV/CD number, date, check/ref no, payee, amount in words, explanation,
 *   and GL lines (BANK row first).
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xls  as XlsWriter;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;


class MyDisbursementVoucherPDF extends \TCPDF {
    public $receiptDate;
    public $receiptTime;

    public function setDataReceiptDate($date) { $this->receiptDate = $date; }
    public function setDataReceiptTime($time) { $this->receiptTime = $time; }

public function Header() {
    $candidates = [
        public_path('images/sucdenLogo.jpg'),
        public_path('images/sucdenLogo.png'),
        public_path('sucdenLogo.jpg'),
        public_path('sucdenLogo.png'),
    ];
    foreach ($candidates as $image) {
        if ($image && is_file($image)) {
            $this->Image($image, 15, 10, 50, '', '', '', 'T', false, 300, '', false, false, 0, false, false, false);
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
              <tr><td align="center"><font size="8">Paid by SUCDEN PHILIPPINES, INC.</font><br><br></td></tr>
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

class CashDisbursementController extends Controller
{
    // === Numbering ===
    public function generateCdNumber(Request $req)
    {
        $companyId = $req->query('company_id');

        $last = CashDisbursement::when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderBy('cd_no','desc')
            ->value('cd_no');

        $base = is_numeric($last) ? (int)$last : 800000;
        return response()->json(['cd_no' => (string)($base + 1)]);
    }

    // === Create header (auto-add BANK row placeholder) ===
    public function storeMain(Request $req)
    {
        $data = $req->validate([
            'cd_no'           => ['nullable','string','max:25'],
            'vend_id'         => ['required','string','max:50'],
            'disburse_date'   => ['required','date'],
            'pay_method'      => ['required','string','max:15'],
            'bank_id'         => ['required','string','max:15'],
            'check_ref_no'    => ['required','string','max:25'],
            'explanation'     => ['nullable','string','max:1000'],
            'amount_in_words' => ['nullable','string','max:255'],
            'company_id'      => ['required','integer'],
            'workstation_id'  => ['nullable','string','max:25'],
            'user_id'         => ['nullable','integer'],
        ]);

        if (empty($data['cd_no'])) {
            $next = $this->generateCdNumber(new Request(['company_id' => $data['company_id']]));
            $data['cd_no'] = $next->getData()->cd_no ?? null;
        }

        $data['is_cancel']       = 'n';
        $data['disburse_amount'] = 0;
        $data['sum_debit']       = 0;
        $data['sum_credit']      = 0;
        $data['is_balanced']     = false;

        $main = CashDisbursement::create($data);

        // Auto-create BANK line if we can resolve bank acct from account_code.bank_id
$bankAcct = AccountCode::where('bank_id', $data['bank_id'])
    ->where('company_id', (int) $data['company_id'])
    ->where('active_flag', 1)
    ->first(['acct_code']);

        if ($bankAcct) {
            CashDisbursementDetail::create([
                'transaction_id' => $main->id,
                'acct_code'      => $bankAcct->acct_code,
                'debit'          => 0,
                'credit'         => 0,
                'workstation_id' => 'BANK',
                'company_id'     => $data['company_id'],
                'user_id'        => $data['user_id'] ?? null,
            ]);
        }

        return response()->json(['id'=>$main->id,'cd_no'=>$main->cd_no]);
    }

    // === Insert detail row ===
    public function saveDetail(Request $req)
    {
        $payload = $req->validate([
            'transaction_id' => ['required','integer','exists:cash_disbursement,id'],
            'acct_code'      => ['required','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
            'workstation_id' => ['nullable','string','max:25'],
            'user_id'        => ['nullable','integer'],
        ]);

$txId = (int) $payload['transaction_id'];
$companyId = CashDisbursement::where('id', $txId)->value('company_id');

$this->requireNotCancelled($txId);
$this->requireApprovedEdit($txId, $companyId ? (int)$companyId : null);

        $debit  = (float)($payload['debit']  ?? 0);
        $credit = (float)($payload['credit'] ?? 0);
        if ($debit > 0 && $credit > 0) {
            return response()->json(['message'=>'Provide either debit OR credit, not both.'], 422);
        }
        if ($debit <= 0 && $credit <= 0) {
            return response()->json(['message'=>'Debit or credit is required.'], 422);
        }

$exists = AccountCode::where('acct_code', $payload['acct_code'])
    ->where('company_id', (int) $companyId)
    ->where('active_flag', 1)
    ->exists();

        if (!$exists) return response()->json(['message'=>'Invalid or inactive account.'], 422);

        $dup = CashDisbursementDetail::where('transaction_id',$payload['transaction_id'])
            ->where('acct_code',$payload['acct_code'])
            ->exists();
        if ($dup) return response()->json(['message'=>'Duplicate account code for this transaction.'], 422);


        $detail = CashDisbursementDetail::create([
            'transaction_id' => $payload['transaction_id'],
            'acct_code'      => $payload['acct_code'],
            'debit'          => $debit,
            'credit'         => $credit,
            'workstation_id' => $payload['workstation_id'] ?? null,
            'user_id'        => $payload['user_id'] ?? null,
            'company_id'     => $companyId,
        ]);

        $this->adjustBankCredit($payload['transaction_id']);
        $totals = $this->recalcTotals($payload['transaction_id']);

        return response()->json(['detail_id'=>$detail->id,'totals'=>$totals]);
    }

    // === Update a detail row ===
    public function updateDetail(Request $req)
    {
        $payload = $req->validate([
            'id'             => ['required','integer','exists:cash_disbursement_details,id'],
            'transaction_id' => ['required','integer','exists:cash_disbursement,id'],
            'acct_code'      => ['nullable','string','max:75'],
            'debit'          => ['nullable','numeric'],
            'credit'         => ['nullable','numeric'],
        ]);

$txId = (int) $payload['transaction_id'];
$companyId = CashDisbursement::where('id', $txId)->value('company_id');

$this->requireNotCancelled($txId);
$this->requireApprovedEdit($txId, $companyId ? (int)$companyId : null);

        $detail = CashDisbursementDetail::find($payload['id']);
        if (!$detail) return response()->json(['message'=>'Detail not found.'], 404);

        $apply = [];
        if (array_key_exists('acct_code',$payload)) $apply['acct_code'] = $payload['acct_code'];
        if (array_key_exists('debit',$payload))     $apply['debit']     = $payload['debit'];
        if (array_key_exists('credit',$payload))    $apply['credit']    = $payload['credit'];

        if (array_key_exists('debit',$apply) && array_key_exists('credit',$apply)) {
            $d=(float)$apply['debit']; $c=(float)$apply['credit'];
            if ($d > 0 && $c > 0) return response()->json(['message'=>'Provide either debit OR credit.'], 422);
            if ($d <= 0 && $c <= 0) return response()->json(['message'=>'Debit or credit is required.'], 422);
        }

        if (array_key_exists('acct_code',$apply) && $apply['acct_code'] !== $detail->acct_code) {
$exists = AccountCode::where('acct_code', $apply['acct_code'])
    ->where('company_id', (int) $companyId)
    ->where('active_flag', 1)
    ->exists();

            if (!$exists) return response()->json(['message'=>'Invalid or inactive account.'], 422);
            $dup = CashDisbursementDetail::where('transaction_id',$payload['transaction_id'])
                ->where('acct_code',$apply['acct_code'])->exists();
            if ($dup) return response()->json(['message'=>'Duplicate account code for this transaction.'], 422);
        }

        $detail->update($apply);

        $this->adjustBankCredit($payload['transaction_id']);
        $totals = $this->recalcTotals($payload['transaction_id']);

        return response()->json(['ok'=>true,'totals'=>$totals]);
    }

    // === Delete detail row (not the BANK row) ===
    public function deleteDetail(Request $req)
    {
        $payload = $req->validate([
            'id'             => ['required','integer','exists:cash_disbursement_details,id'],
            'transaction_id' => ['required','integer','exists:cash_disbursement,id'],
        ]);

$txId = (int) $payload['transaction_id'];
$companyId = CashDisbursement::where('id', $txId)->value('company_id');

$this->requireNotCancelled($txId);
$this->requireApprovedEdit($txId, $companyId ? (int)$companyId : null);

        $row = CashDisbursementDetail::find($payload['id']);
        if ($row && ($row->workstation_id === 'BANK')) {
            return response()->json(['message'=>'Cannot delete the bank line.'], 422);
        }

        CashDisbursementDetail::where('id',$payload['id'])->delete();

        $this->adjustBankCredit($payload['transaction_id']);
        $totals = $this->recalcTotals($payload['transaction_id']);

        return response()->json(['ok'=>true,'totals'=>$totals]);
    }

    // === Delete a whole transaction (optional but handy) ===
public function destroy($id)
{
    abort(403, 'Delete must be done via Approval workflow.');
}


    // === Show header + details ===
public function show($id, Request $req)
{
    $companyId = $req->query('company_id');

    $main = CashDisbursement::when($companyId, fn($q)=>$q->where('company_id',$companyId))
        ->findOrFail($id);

    $details = CashDisbursementDetail::from('cash_disbursement_details as d')
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
            'd.workstation_id',
        ]);

    return response()->json(['main'=>$main,'details'=>$details]);
}


    // === List (for Search Transaction) ===
public function list(Request $req)
{
    $companyId = $req->query('company_id');
    $q  = trim(strtolower((string) $req->query('q', '')));

    // Detect which key exists in vendor_list (vend_id or vend_code)
    $vendKey = Schema::hasColumn('vendor_list', 'vend_id') ? 'vend_id'
             : (Schema::hasColumn('vendor_list', 'vend_code') ? 'vend_code' : null);

    $rows = DB::table('cash_disbursement as d')
        ->when($companyId, fn ($qr) => $qr->where('d.company_id', $companyId))

        // Join vendor_list only if a usable key exists
        ->when($vendKey, function ($qr) use ($vendKey, $companyId) {
            $qr->leftJoin('vendor_list as v', function ($j) use ($vendKey, $companyId) {
                $j->on("v.$vendKey", '=', 'd.vend_id');
                if ($companyId) $j->where('v.company_id', $companyId);
            });
        })

        // Join bank to show bank_name in the search list
        ->leftJoin('bank as b', function ($j) use ($companyId) {
            $j->on('b.bank_id', '=', 'd.bank_id');
            if ($companyId) $j->where('b.company_id', $companyId);
        })

        // Free-text filter
        ->when($q !== '', function ($qr) use ($q, $vendKey) {
            $qr->where(function ($w) use ($q, $vendKey) {
                $w->whereRaw('LOWER(d.cd_no) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(d.vend_id) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(d.check_ref_no) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(d.bank_id) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(b.bank_name) LIKE ?', ["%{$q}%"]);
                if ($vendKey) {
                    $w->orWhereRaw('LOWER(v.vend_name) LIKE ?', ["%{$q}%"]);
                }
            });
        })

        ->orderByDesc('d.cd_no')
        ->limit(50)
        ->get([
            'd.id', 'd.cd_no', 'd.vend_id', 'd.disburse_date', 'd.disburse_amount',
            'd.bank_id', 'd.check_ref_no', 'd.is_cancel',
            DB::raw($vendKey ? "COALESCE(v.vend_name,'') as vend_name" : "'' as vend_name"),
            DB::raw("COALESCE(b.bank_name,'') as bank_name"),
        ]);

    return response()->json($rows);
}


    // === Dropdowns ===
    public function vendors(Request $req)
    {
        $companyId = $req->query('company_id');
        $rows = DB::table('vendor_list')
            ->when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->orderBy('vend_name')
            ->get(['vend_code','vend_name']);

        $items = $rows->map(fn($r)=>[
            'code' => $r->vend_code,
            'label' => $r->vend_code,
            'description' => $r->vend_name,
            'vend_id' => $r->vend_code,
            'vend_name' => $r->vend_name,
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
            'pay_method'    => $r->pay_method,
        ]);
        return response()->json($items);
    }

    // === Cancel/Uncancel ===
public function updateCancel(Request $req)
{
    abort(403, 'Cancel/Uncancel must be done via Approval workflow.');
}


    /** Convert helpers (0..999 → words) */
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

    private function pesoWords(float $amount): string {
        $int = (int) floor($amount + 0.0000001);
        $cents = (int) round(($amount - $int) * 100);
        $words = strtoupper($this->numberToWordsInt($int));
        $tail  = $cents === 0 ? ' PESOS ONLY' : sprintf(' PESOS AND %02d/100 ONLY', $cents);
        return $words . $tail;
    }

    // === PDF ===
    public function formPdf(Request $req, $id)
    {
        // pick the correct vendor key at runtime
        $vendKey = Schema::hasColumn('vendor_list', 'vend_id')
            ? 'vend_id'
            : (Schema::hasColumn('vendor_list', 'vend_code') ? 'vend_code' : null);

        $headerQ = DB::table('cash_disbursement as d');

$companyId = $req->query('company_id');

if ($vendKey) {
    $headerQ->leftJoin('vendor_list as v', function ($j) use ($vendKey, $companyId) {
        $j->on('d.vend_id', '=', 'v.'.$vendKey);
        if ($companyId) $j->where('v.company_id', '=', $companyId);
    });
}


        $header = $headerQ
            ->select(
                'd.id','d.cd_no','d.vend_id','d.disburse_amount','d.pay_method',
                'd.bank_id','d.explanation','d.is_cancel','d.check_ref_no',
                DB::raw("to_char(d.disburse_date, 'MM/DD/YYYY') as disburse_date"),
                DB::raw($vendKey ? "COALESCE(v.vend_name,'') as vend_name" : "'' as vend_name"),
                'd.amount_in_words','d.workstation_id','d.user_id','d.created_at'
            )
    ->where('d.id', $id)
    ->when($companyId, fn($q) => $q->where('d.company_id', $companyId))
    ->first();

        if (!$header || $header->is_cancel === 'y') {
            abort(404, 'Cash Disbursement not found or cancelled');
        }

$companyId = $req->query('company_id');

$details = DB::table('cash_disbursement_details as x')
    ->join('account_code as a', function ($j) use ($companyId) {
        $j->on('x.acct_code', '=', 'a.acct_code');
        if ($companyId) $j->where('a.company_id', '=', $companyId);
    })
    ->where('x.transaction_id', $id)
    ->when($companyId, fn($q) => $q->where('x.company_id', $companyId))
    ->orderBy('x.workstation_id','desc')
    ->orderBy('x.credit','desc')
    ->select('x.acct_code','a.acct_desc','x.debit','x.credit')
    ->get();


        $totalDebit  = (float)$details->sum('debit');
        $totalCredit = (float)$details->sum('credit');

        $dvAmount = (float)($header->disburse_amount ?? 0);
        $amountInWords = $this->pesoWords($dvAmount);
        $dvAmountFmt = number_format($dvAmount, 2);

        $pdf = new MyDisbursementVoucherPDF('P','mm','LETTER',true,'UTF-8',false);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(15,30,15);
        $pdf->SetHeaderMargin(8);
        $pdf->SetFooterMargin(10);
        $pdf->AddPage();
        $pdf->SetFont('helvetica','',7);

        $pdf->setDataReceiptDate(\Carbon\Carbon::parse($header->created_at)->format('M d, Y'));
        $pdf->setDataReceiptTime(\Carbon\Carbon::parse($header->created_at)->format('h:i:sa'));

        $dvNumber     = $header->cd_no;
        $dvDateText   = $header->disburse_date;
        $checkNo      = $header->check_ref_no;
        $payee        = (string)($header->vend_name ?? $header->vend_id);
        $explanation  = (string)($header->explanation ?? '');

        $tbl = <<<EOD
<br><br>
<table border="0" cellpadding="1" cellspacing="0" nobr="true" width="100%">
<tr>
  <td width="10%"></td>
  <td width="20%"></td>
  <td width="20%"></td>
  <td width="50%" colspan="2" align="left"><div><font size="16"><b>DISBURSEMENT VOUCHER</b></font></div></td>
</tr>
<tr><td colspan="5"></td></tr>
<tr>
  <td width="10%"></td><td width="20%"></td><td width="25%"></td>
  <td width="31%" align="left" valign="middle" height="30"><font size="14"><b>DV Number:</b></font></td>
  <td width="14%" align="left"><font size="18"><b><u>{$dvNumber}</u></b></font></td>
</tr>
<tr>
  <td width="10%"></td><td width="20%"></td><td width="25%"></td>
  <td width="31%" align="left"><font size="10"><b>Date:</b></font></td>
  <td width="14%" align="left"><font size="10"><u>{$dvDateText}</u></font></td>
</tr>
<tr>
  <td width="10%"></td><td width="20%"></td><td width="25%"></td>
  <td width="31%" align="left"><font size="12"><b>Check/Ref #:</b></font></td>
  <td width="14%" align="left"><font size="12"><u>{$checkNo}</u></font></td>
</tr>
<tr>
  <td width="15%"><font size="10"><b>PAYEE</b></font></td>
  <td width="80%" colspan="4"><font size="14"><u>{$payee}</u></font></td>
</tr>
<tr>
  <td width="15%"><font size="10"><b>AMOUNT:</b></font></td>
  <td width="80%" colspan="4"><font size="10"><u>{$amountInWords}</u></font></td>
</tr>
</table>

<table><tr><td><br><br></td></tr></table>
<table border="1" cellspacing="0" cellpadding="5">
  <tr>
    <td width="70%" align="center"><font size="10"><b>EXPLANATION</b></font></td>
    <td width="30%" align="center"><font size="10"><b>AMOUNT</b></font></td>
  </tr>
  <tr>
    <td height="80"><font size="10">{$explanation}</font></td>
    <td align="right"><font size="10">{$dvAmountFmt}</font></td>
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

        if (abs($totalDebit - $totalCredit) > 0.005) {
            $html = sprintf(
                '<!doctype html><meta charset="utf-8">
                <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
                <h2>Cannot print Disbursement Voucher</h2>
                <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before printing.</p>
                <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
                number_format($totalDebit, 2),
                number_format($totalCredit, 2)
            );
            return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $pdfContent = $pdf->Output('disbursementVoucher.pdf', 'S');
        return response($pdfContent, 200)
            ->header('Content-Type','application/pdf')
            ->header('Content-Disposition','inline; filename="disbursementVoucher.pdf"');
    }



public function formExcel(Request $req, $id)
{
    $vendKey = Schema::hasColumn('vendor_list', 'vend_id')
        ? 'vend_id'
        : (Schema::hasColumn('vendor_list', 'vend_code') ? 'vend_code' : null);

    $headerQ = DB::table('cash_disbursement as d');
$companyId = $req->query('company_id');

if ($vendKey) {
    $headerQ->leftJoin('vendor_list as v', function ($j) use ($vendKey, $companyId) {
        $j->on('d.vend_id', '=', 'v.'.$vendKey);
        if ($companyId) $j->where('v.company_id', '=', $companyId);
    });
}


    $header = $headerQ
        ->select(
            'd.id','d.cd_no','d.vend_id','d.disburse_amount','d.pay_method',
            'd.bank_id','d.explanation','d.is_cancel','d.check_ref_no',
            DB::raw("to_char(d.disburse_date, 'MM/DD/YYYY') as disburse_date"),
            DB::raw($vendKey ? "COALESCE(v.vend_name,'') as vend_name" : "'' as vend_name"),
            'd.amount_in_words','d.created_at'
        )
    ->where('d.id', $id)
    ->when($companyId, fn($q) => $q->where('d.company_id', $companyId))
    ->first();

    if (!$header || $header->is_cancel === 'y') {
        abort(404, 'Cash Disbursement not found or cancelled');
    }

$details = DB::table('cash_disbursement_details as x')
    ->join('account_code as a', function ($j) use ($companyId) {
        $j->on('x.acct_code', '=', 'a.acct_code');
        if ($companyId) $j->where('a.company_id', '=', $companyId);
    })
    ->where('x.transaction_id', $id)
    ->when($companyId, fn($q) => $q->where('x.company_id', $companyId))
    ->orderBy('x.workstation_id','desc')
    ->orderBy('x.credit','desc')
    ->select('x.acct_code','a.acct_desc','x.debit','x.credit')
    ->get();


    $totalDebit  = (float)$details->sum('debit');
    $totalCredit = (float)$details->sum('credit');
    $dvAmount = (float)($header->disburse_amount ?? 0);
    $amountInWords = $this->pesoWords($dvAmount);

    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Disbursement Voucher');
    $row = 1;
    $sheet->setCellValue("A{$row}", 'DISBURSEMENT VOUCHER'); $row++;
    $sheet->setCellValue("A{$row}", 'DV Number:');      $sheet->setCellValue("B{$row}", $header->cd_no); $row++;
    $sheet->setCellValue("A{$row}", 'Date:');           $sheet->setCellValue("B{$row}", $header->disburse_date); $row++;
    $sheet->setCellValue("A{$row}", 'Check/Ref #:');    $sheet->setCellValue("B{$row}", $header->check_ref_no); $row++;
    $sheet->setCellValue("A{$row}", 'Payee:');          $sheet->setCellValue("B{$row}", (string)($header->vend_name ?: $header->vend_id)); $row++;
    $sheet->setCellValue("A{$row}", 'Amount (in words):'); $sheet->setCellValue("B{$row}", $amountInWords); $row += 2;

    $sheet->setCellValue("A{$row}", 'EXPLANATION');
    $sheet->setCellValue("B{$row}", 'AMOUNT');
    $sheet->getStyle("A{$row}:B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A{$row}:B{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;

    $sheet->setCellValue("A{$row}", (string)($header->explanation ?? ''));
    $sheet->setCellValue("B{$row}", $dvAmount);
    $sheet->getStyle("A{$row}:B{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
    $row += 2;

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

    $sheet->setCellValue("B{$row}", 'TOTAL');
    $sheet->setCellValue("C{$row}", $totalDebit);
    $sheet->setCellValue("D{$row}", $totalCredit);
    $sheet->getStyle("A{$row}:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C{$row}:D{$row}")->getNumberFormat()->setFormatCode('#,##0.00');

    $sheet->getColumnDimension('A')->setWidth(18);
    $sheet->getColumnDimension('B')->setWidth(40);
    $sheet->getColumnDimension('C')->setWidth(14);
    $sheet->getColumnDimension('D')->setWidth(14);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    foreach (['A2','A3','A4','A5','A6'] as $addr) {
        $sheet->getStyle($addr)->getFont()->setBold(true);
    }

    $format = strtolower((string)$req->query('format','xlsx'));
    $fileBase = 'disbursement-voucher-'.($header->cd_no ?: $id);
    if ($format === 'xls') {
        $writer = new XlsWriter($ss);
        $filename = $fileBase.'.xls';
        $contentType = 'application/vnd.ms-excel';
    } else {
        $writer = new XlsxWriter($ss);
        $filename = $fileBase.'.xlsx';
        $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

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

    // === Unbalanced helpers ===
    public function unbalancedExists(Request $req)
    {
        $companyId = $req->query('company_id');
        $exists = CashDisbursement::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->where('is_balanced', false)
            ->exists();

        return response()->json(['exists'=>$exists]);
    }

    public function unbalanced(Request $req)
    {
        $companyId = $req->query('company_id');
        $limit = (int)$req->query('limit',20);

        $rows = CashDisbursement::when($companyId, fn($q)=>$q->where('company_id',$companyId))
            ->where('is_balanced', false)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get([
                'id','cd_no','vend_id',
                DB::raw('COALESCE(sum_debit,0)  as sum_debit'),
                DB::raw('COALESCE(sum_credit,0) as sum_credit'),
            ]);

        return response()->json(['items'=>$rows]);
    }

    // ---- Private helpers ----

    /**
     * Adjust BANK row CREDIT so that:
     *   bank_credit = (sum of debits excluding bank) - (sum of credits excluding bank)
     * And set bank debit to zero. Auto-insert the bank row if mapping exists.
     */
    protected function adjustBankCredit(int $transactionId): void
    {
        $main = CashDisbursement::find($transactionId);
        if (!$main) return;

$bankAcct = AccountCode::where('bank_id', $main->bank_id)
    ->where('company_id', (int) $main->company_id)
    ->where('active_flag', 1)
    ->first(['acct_code']);

        if (!$bankAcct) return;

$bankRow = CashDisbursementDetail::where('transaction_id', $transactionId)
    ->where('workstation_id', 'BANK')
    ->first();

if ($bankRow && $bankRow->acct_code !== $bankAcct->acct_code) {
    $bankRow->update(['acct_code' => $bankAcct->acct_code]);
}


        if (!$bankRow) {
            $bankRow = CashDisbursementDetail::create([
                'transaction_id' => $transactionId,
                'acct_code'      => $bankAcct->acct_code,
                'debit'          => 0,
                'credit'         => 0,
                'workstation_id' => 'BANK',
                'user_id'        => $main->user_id ?? null,
                'company_id'     => $main->company_id,
            ]);
        } elseif ($bankRow->company_id === null) {
            $bankRow->update(['company_id'=>$main->company_id]);
        }

        $sumDebitExBank = (float) CashDisbursementDetail::where('transaction_id',$transactionId)
            ->where('id','<>',$bankRow->id)
            ->sum('debit');

        $sumCreditExBank = (float) CashDisbursementDetail::where('transaction_id',$transactionId)
            ->where('id','<>',$bankRow->id)
            ->sum('credit');

        $newBankCredit = max(0, $sumDebitExBank - $sumCreditExBank);

        $bankRow->update(['debit' => 0, 'credit' => $newBankCredit]);
    }


/**
 * Require that transaction is not cancelled.
 */
protected function requireNotCancelled(int $transactionId): void
{
    $flag = CashDisbursement::where('id', $transactionId)->value('is_cancel');
    if ($flag === 'y') {
        abort(403, 'This transaction is CANCELLED and cannot be edited.');
    }
}

/**
 * Require an ACTIVE approved edit window in approvals table.
 * Matches your Cash Receipts approval enforcement style.
 */
/**
 * Require an ACTIVE approved edit window in approvals table.
 * Case-insensitive action/module match to avoid EDIT vs edit issues.
 */
/**
 * Require an ACTIVE approved edit window in approvals table.
 * Case-insensitive on action ('edit' vs 'EDIT').
 */
protected function requireApprovedEdit(int $transactionId, ?int $companyId = null): void
{
    $now = now();

    $q = DB::table('approvals')
        ->where('module', 'cash_disbursement')
        ->where('record_id', $transactionId)
        ->whereRaw('LOWER(action) = ?', ['edit'])   // ✅ case-insensitive
        ->where('status', 'approved')
        ->whereNull('consumed_at')
        ->whereNotNull('expires_at')
        ->where('expires_at', '>', $now);

    if (!empty($companyId)) {
        $q->where('company_id', $companyId);
    }

    $ok = $q->exists();

    // optional fallback (legacy rows without company_id)
    if (!$ok && !empty($companyId)) {
        $ok = DB::table('approvals')
            ->where('module', 'cash_disbursement')
            ->where('record_id', $transactionId)
            ->whereRaw('LOWER(action) = ?', ['edit']) // ✅ case-insensitive
            ->where('status', 'approved')
            ->whereNull('consumed_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->exists();
    }

    if (!$ok) {
        abort(403, 'Edit approval is required or has expired.');
    }
}


public function updateMain(Request $req)
{
    $data = $req->validate([
        'id'             => ['required','integer','exists:cash_disbursement,id'],
        'vend_id'        => ['required','string','max:50'],
        'disburse_date'  => ['required','date'],
        'pay_method'     => ['required','string','max:15'],
        'bank_id'        => ['required','string','max:15'],
        'check_ref_no'   => ['required','string','max:25'],
        'explanation'    => ['nullable','string','max:1000'],
        'amount_in_words'=> ['nullable','string','max:255'],
        'company_id'     => ['nullable','integer'],
        'user_id'        => ['nullable','integer'],
    ]);

    $tx = CashDisbursement::findOrFail($data['id']);
    $companyId = $data['company_id'] ?? $tx->company_id;

    // enforce rules
    $this->requireNotCancelled((int) $tx->id);
    $this->requireApprovedEdit((int) $tx->id, $companyId ? (int) $companyId : null);

    $oldBankId = (string) ($tx->bank_id ?? '');

    // update header
    $tx->update([
        'vend_id'        => $data['vend_id'],
        'disburse_date'  => $data['disburse_date'],
        'pay_method'     => $data['pay_method'],
        'bank_id'        => $data['bank_id'],
        'check_ref_no'   => $data['check_ref_no'],
        'explanation'    => $data['explanation'] ?? '',
        'amount_in_words'=> $data['amount_in_words'] ?? $tx->amount_in_words,
        'company_id'     => $companyId,
        'user_id'        => $data['user_id'] ?? $tx->user_id,
    ]);

    // If bank changed, ensure BANK row acct_code aligns to new bank_id mapping
    if ($oldBankId !== (string) $data['bank_id']) {
$bankAcct = AccountCode::where('bank_id', $data['bank_id'])
    ->where('company_id', (int) $data['company_id'])
    ->where('active_flag', 1)
    ->first(['acct_code']);


        if ($bankAcct) {
            $bankRow = CashDisbursementDetail::where('transaction_id', $tx->id)
                ->where('workstation_id', 'BANK')
                ->first();

            if ($bankRow) {
                $bankRow->update(['acct_code' => $bankAcct->acct_code]);
            } else {
                CashDisbursementDetail::create([
                    'transaction_id' => $tx->id,
                    'acct_code'      => $bankAcct->acct_code,
                    'debit'          => 0,
                    'credit'         => 0,
                    'workstation_id' => 'BANK',
                    'company_id'     => $companyId,
                    'user_id'        => $tx->user_id ?? null,
                ]);
            }
        }
    }

    // recompute bank + totals (ALWAYS define $totals before returning)
    $this->adjustBankCredit((int) $tx->id);
    $totals = $this->recalcTotals((int) $tx->id);

    // re-read header after recalcTotals updates disburse_amount/sums/is_balanced
    $tx->refresh();

    return response()->json([
        'ok'     => true,
        'id'     => $tx->id,
        'cd_no'  => $tx->cd_no,
        'main'   => $tx,      // optional but useful for frontend
        'totals' => $totals,
    ]);
}


public function updateMainNoApproval(Request $req)
{
    $data = $req->validate([
        'id'             => ['required','integer','exists:cash_disbursement,id'],
        'vend_id'        => ['required','string','max:50'],
        'disburse_date'  => ['required','date'],
        'pay_method'     => ['required','string','max:15'],
        'bank_id'        => ['required','string','max:15'],
        'check_ref_no'   => ['required','string','max:25'],
        'explanation'    => ['nullable','string','max:1000'],
        'amount_in_words'=> ['nullable','string','max:255'],
        'company_id'     => ['required','integer'],
        'user_id'        => ['nullable','integer'],
    ]);

    $tx = CashDisbursement::findOrFail($data['id']);
    $companyId = (int) $data['company_id'];

    // ✅ use the SAME cancel guard your working updateMain uses
    // (If you only have requireNotCancelled(), keep that.)
    $this->requireNotCancelled((int) $tx->id);

    $oldBankId = (string) ($tx->bank_id ?? '');

    // ✅ update header (same fields as updateMain, but no approval requirement)
    $tx->update([
        'vend_id'        => $data['vend_id'],
        'disburse_date'  => $data['disburse_date'],
        'pay_method'     => $data['pay_method'],
        'bank_id'        => $data['bank_id'],
        'check_ref_no'   => $data['check_ref_no'],
        'explanation'    => $data['explanation'] ?? '',
        'amount_in_words'=> $data['amount_in_words'] ?? $tx->amount_in_words,
        'company_id'     => $companyId,
        'user_id'        => $data['user_id'] ?? $tx->user_id,
    ]);

    // ✅ IMPORTANT: if bank changed, align the BANK row acct_code (same logic as updateMain)
    if ($oldBankId !== (string) $data['bank_id']) {
$bankAcct = AccountCode::where('bank_id', $data['bank_id'])
    ->where('company_id', (int) $data['company_id'])
    ->where('active_flag', 1)
    ->first(['acct_code']);


        if ($bankAcct) {
            $bankRow = CashDisbursementDetail::where('transaction_id', $tx->id)
                ->where('workstation_id', 'BANK')
                ->first();

            if ($bankRow) {
                $bankRow->update(['acct_code' => $bankAcct->acct_code]);
            } else {
                CashDisbursementDetail::create([
                    'transaction_id' => $tx->id,
                    'acct_code'      => $bankAcct->acct_code,
                    'debit'          => 0,
                    'credit'         => 0,
                    'workstation_id' => 'BANK',
                    'company_id'     => $companyId,
                    'user_id'        => $tx->user_id ?? null,
                ]);
            }
        }
    }

    // ✅ recompute BANK + totals exactly like updateMain
    $this->adjustBankCredit((int) $tx->id);
    $totals = $this->recalcTotals((int) $tx->id);

    $tx->refresh();

    return response()->json([
        'ok'     => true,
        'id'     => $tx->id,
        'cd_no'  => $tx->cd_no,
        'main'   => $tx,
        'totals' => $totals,
    ]);
}




    /**
     * Recalc totals and update header:
     *   - disburse_amount = sum_debit
     *   - sum_debit, sum_credit, is_balanced
     */
    protected function recalcTotals(int $transactionId): array
    {
        $tot = CashDisbursementDetail::where('transaction_id',$transactionId)
            ->selectRaw('COALESCE(SUM(debit),0) as sum_debit, COALESCE(SUM(credit),0) as sum_credit')
            ->first();

        $sumDebit  = round((float)$tot->sum_debit, 2);
        $sumCredit = round((float)$tot->sum_credit, 2);
        $balanced  = abs($sumDebit - $sumCredit) < 0.005;

        CashDisbursement::where('id',$transactionId)->update([
            'disburse_amount' => $sumDebit,   // amount box mirrors total DEBIT
            'sum_debit'       => $sumDebit,
            'sum_credit'      => $sumCredit,
            'is_balanced'     => $balanced,
        ]);

        return ['debit'=>$sumDebit,'credit'=>$sumCredit,'balanced'=>$balanced];
    }
}
