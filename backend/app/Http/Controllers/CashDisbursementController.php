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

    public $companyId = null;

    public function setCompanyId(?int $companyId): void
    {
        $this->companyId = $companyId;
    }


    public $receiptDate;
    public $receiptTime;
    public $preparedByInitials = '';

    public function setPreparedByInitials(string $initials) { $this->preparedByInitials = $initials; }

    public function setDataReceiptDate($date) { $this->receiptDate = $date; }
    public function setDataReceiptTime($time) { $this->receiptTime = $time; }

public function Header()
{
    // You MUST set this before creating the PDF:
    // $pdf->companyId = (int)$companyId;
    $companyId = (int)($this->companyId ?? 0);

    // =========================
    // COMPANY 2 — AMEROP
    // =========================
    if ($companyId === 2) {

        // --- Amerop logo (smaller, like printed sample) ---
        $logo = public_path('ameropLogo.jpg');
        if (is_file($logo)) {
            // x, y, width
            $this->Image(
                $logo,
                15,   // left
                12,   // top
                22,   // SMALL logo width (matches printed sample)
                '', '', '', 'T',
                false, 300
            );
        }

        // --- Text to the RIGHT of logo ---
        // Color: Amerop blue
        $this->SetTextColor(40, 85, 160);

        // AMEROP (top line)
        $this->SetFont('helvetica', 'B', 14);
        $this->Text(40, 14, 'AMEROP');

        // PHILIPPINES, INC. (second line)
        $this->SetFont('helvetica', '', 9);
        $this->Text(40, 20, 'PHILIPPINES, INC.');

        // Reset color for rest of document
        $this->SetTextColor(0, 0, 0);

        return;
    }

    // =========================
    // DEFAULT — SUCDEN (unchanged)
    // =========================
    $candidates = [
        public_path('images/sucdenLogo.jpg'),
        public_path('images/sucdenLogo.png'),
        public_path('sucdenLogo.jpg'),
        public_path('sucdenLogo.png'),
    ];

    foreach ($candidates as $image) {
        if ($image && is_file($image)) {
            $this->Image(
                $image,
                15,
                10,
                50,
                '',
                '',
                '',
                'T',
                false,
                300
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

    // ✅ Company label (company_id 1 vs 2)
    $companyId = (int)($this->companyId ?? 0);
    $paidBy = ($companyId === 2)
        ? 'AMEROP PHILIPPINES, INC.'
        : 'SUCDEN PHILIPPINES, INC.';

    $html = '
    <table border="0"><tr>
      <td width="70%">
        <table border="1" cellpadding="5" cellspacing="0" width="100%">
          <tr>
            <td width="33.33%">
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
            <td width="33.33%">
              <table border="0" cellpadding="0" cellspacing="0" width="100%" height="65">
                <tr>
                  <td valign="top" align="left"><font size="8">Checked:</font></td>
                </tr>
                <tr>
                  <td height="54"></td>
                </tr>
              </table>
            </td>
            <td width="33.34%">
              <table border="0" cellpadding="0" cellspacing="0" width="100%" height="65">
                <tr>
                  <td valign="top" align="left"><font size="8">Approved:</font></td>
                </tr>
                <tr>
                  <td height="54"></td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
      <td width="5%"></td>
      <td width="25%">
        <table border="1" cellpadding="5">
          <tr><td align="center"><font size="8">Received from __PAID_BY__</font><br><br></td></tr>
          <tr><td align="center"><font size="8">Signature Over Printed Name/Date</font></td></tr>
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

    // ✅ Safe inject (prevents quote/concat parse errors)
    $html = str_replace('__PAID_BY__', htmlspecialchars($paidBy), $html);

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

        // ✅ Block duplicate Check/Ref # per company (case-insensitive)
        $ref = trim((string) ($data['check_ref_no'] ?? ''));
        if ($ref !== '') {
            $dup = CashDisbursement::where('company_id', (int) $data['company_id'])
                ->whereRaw('LOWER(check_ref_no) = ?', [strtolower($ref)])
                ->exists();

            if ($dup) {
                return response()->json([
                    'message' => 'Duplicate Check / Ref #. This Check/Ref # already exists.',
                ], 422);
            }
        }


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

$this->requireDetailsEditable($txId, $companyId ? (int)$companyId : null);

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

        //$dup = CashDisbursementDetail::where('transaction_id',$payload['transaction_id'])
        //    ->where('acct_code',$payload['acct_code'])
        //    ->exists();
        //if ($dup) return response()->json(['message'=>'Duplicate account code for this transaction.'], 422);


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

$this->requireDetailsEditable($txId, $companyId ? (int)$companyId : null);

$detail = CashDisbursementDetail::where('id', (int)$payload['id'])
    ->where('transaction_id', (int)$payload['transaction_id'])
    ->first();

if (!$detail) {
    return response()->json(['message'=>'Detail not found for this transaction.'], 404);
}
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
            //$dup = CashDisbursementDetail::where('transaction_id',$payload['transaction_id'])
            //    ->where('acct_code',$apply['acct_code'])->exists();
            //if ($dup) return response()->json(['message'=>'Duplicate account code for this transaction.'], 422);
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

$this->requireDetailsEditable($txId, $companyId ? (int)$companyId : null);

$row = CashDisbursementDetail::where('id', (int)$payload['id'])
    ->where('transaction_id', (int)$payload['transaction_id'])
    ->first();

if (!$row) {
    return response()->json(['message'=>'Detail not found for this transaction.'], 404);
}
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

    $main = CashDisbursement::select([
        'id','cd_no','vend_id','disburse_date','disburse_amount','pay_method',
        'bank_id','explanation','is_cancel','check_ref_no',
        'amount_in_words','workstation_id','user_id','company_id',
        'sum_debit','sum_credit','is_balanced',
        'exported_at','exported_by',
        'created_at','updated_at',
    ])
    ->when($companyId, fn($q)=>$q->where('company_id',$companyId))
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
        // ✅ BANK amount for dropdown list (must show BANK row, not header disburse_amount)
        // BANK row is identified by workstation_id = 'BANK' and amount is typically CREDIT.
        // We join one row per transaction_id.
        ->leftJoinSub(
            DB::table('cash_disbursement_details')
                ->selectRaw("
                    transaction_id,
                    MAX(
                        CASE
                            WHEN workstation_id = 'BANK'
                            THEN COALESCE(NULLIF(credit, 0), debit, 0)
                            ELSE NULL
                        END
                    ) as bank_amount
                ")
                ->groupBy('transaction_id'),
            'bk',
            function ($j) {
                $j->on('bk.transaction_id', '=', 'd.id');
            }
        )


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
        // Free-text filter
        ->when($q !== '', function ($qr) use ($q, $vendKey) {
            $qr->where(function ($w) use ($q, $vendKey) {
                $w->whereRaw('LOWER(d.cd_no) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(d.vend_id) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(d.check_ref_no) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(d.bank_id) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(b.bank_name) LIKE ?', ["%{$q}%"])

                  // ✅ amount searchable (supports typing 20000 to match 20000 or 20000.00)
                  ->orWhereRaw('CAST(d.disburse_amount AS TEXT) LIKE ?', ["%{$q}%"]);

                if ($vendKey) {
                    $w->orWhereRaw('LOWER(v.vend_name) LIKE ?', ["%{$q}%"]);
                }
            });
        })

        ->distinct()
        ->orderByDesc('d.cd_no')
        ->limit(50)
        ->get([
            'd.id', 'd.cd_no', 'd.vend_id', 'd.disburse_date',

            // ✅ keep header total available (optional)
            'd.disburse_amount',

            // ✅ dropdown Amount should be bank_amount
            DB::raw("COALESCE(bk.bank_amount, 0) as bank_amount"),

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


protected function userInitials(?int $userId): string
{
    if (empty($userId)) return '';

    // ✅ Your actual table
    if (\Illuminate\Support\Facades\Schema::hasTable('users_employees')) {
        $u = (string) DB::table('users_employees')
            ->where('id', (int)$userId)
            ->value('username');

        $u = strtoupper(trim((string)$u));
        if ($u !== '') return $u;
    }

    // last fallback (never blank)
    return 'U' . (int)$userId;
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


/**
 * Print Check PDF (8" x 3" landscape) — amount is BANK row amount.
 * Locks transaction by setting exported_at (mark once).
 */
public function checkPdf(Request $request, $id)
{
    $companyId  = $request->query('company_id');
    $exportedBy = $request->query('user_id');

    // vendor key runtime
    $vendKey = Schema::hasColumn('vendor_list', 'vend_id')
        ? 'vend_id'
        : (Schema::hasColumn('vendor_list', 'vend_code') ? 'vend_code' : null);

    $headerQ = DB::table('cash_disbursement as d');

    if ($vendKey) {
        $headerQ->leftJoin('vendor_list as v', function ($j) use ($vendKey, $companyId) {
            $j->on('d.vend_id', '=', 'v.'.$vendKey);
            if ($companyId) $j->where('v.company_id', '=', $companyId);
        });
    }

    $header = $headerQ
        ->select(
            'd.id',
            'd.cd_no',
            'd.vend_id',
            'd.is_cancel',
            'd.check_ref_no',
            DB::raw("d.disburse_date as raw_disburse_date"),
            DB::raw($vendKey ? "COALESCE(v.vend_name,'') as vend_name" : "'' as vend_name"),
            'd.amount_in_words'
        )
        ->where('d.id', $id)
        ->when($companyId, fn($q) => $q->where('d.company_id', $companyId))
        ->first();

    if (!$header || $header->is_cancel === 'y') {
        abort(404, 'Cash Disbursement not found or cancelled');
    }

    // Ensure balanced (same guard behavior as Sales check)
    $details = DB::table('cash_disbursement_details as x')
        ->where('x.transaction_id', $id)
        ->when($companyId, fn($q) => $q->where('x.company_id', $companyId))
        ->get(['x.debit', 'x.credit']);

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

    // ✅ Amount source: BANK row amount (prefer CREDIT, fallback DEBIT)
    $bankRow = CashDisbursementDetail::where('transaction_id', (int) $id)
        ->where('workstation_id', 'BANK')
        ->first(['debit', 'credit']);

    $bankAmount = 0.0;
    if ($bankRow) {
        $bankAmount = (float) (($bankRow->credit ?? 0) > 0 ? $bankRow->credit : ($bankRow->debit ?? 0));
    }

    // If BANK row missing/zero, fail clearly (check amount must be BANK amount)
    if ($bankAmount <= 0) {
        $html = '<!doctype html><meta charset="utf-8">
            <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
            <h2>Cannot print Check</h2>
            <p>Bank (BANK row) amount is missing or zero. Please ensure the BANK line exists and has the correct amount.</p>';
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    $amountNumeric    = round($bankAmount, 2);
    $amountNumericStr = number_format($amountNumeric, 2);

    $amountWords = trim((string) ($header->amount_in_words ?? ''));
    if ($amountWords === '') {
        $amountWords = $this->pesoWords($amountNumeric);
    }

    $payeeName = (string) ($header->vend_name ?: $header->vend_id);

    $date = $header->raw_disburse_date
        ? \Carbon\Carbon::parse($header->raw_disburse_date)
        : \Carbon\Carbon::now();

    $mm   = $date->format('m');
    $dd   = $date->format('d');
    $yyyy = $date->format('Y');

    // ✅ Same physical check size & layout style as Sales checkPdf
    $checkWidthMm  = 8.0 * 25.4;   // 203.2 mm
    $checkHeightMm = 3.0 * 25.4;   // 76.2 mm

    $pdf = new \TCPDF('L', 'mm', [$checkWidthMm, $checkHeightMm], true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(false, 0);

    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);

    // 1) Date (MM  DD  YYYY)
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

    $fileName = 'check_' . ($header->cd_no ?? $id) . '.pdf';

    // ✅ lock transaction after successful check print
    $this->markExportedOnce((int) $id, $companyId ? (int)$companyId : null, $exportedBy ? (int)$exportedBy : null);

    $pdfContent = $pdf->Output($fileName, 'S');

    return response($pdfContent, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="'.$fileName.'"');
}

/**
 * Mark exported once (do not overwrite exported_at if already set).
 */
protected function markExportedOnce(int $id, ?int $companyId, ?int $userId): void
{
    $q = DB::table('cash_disbursement')
        ->where('id', $id)
        ->whereNull('exported_at');

    if (!empty($companyId)) {
        $q->where('company_id', $companyId);
    }

    $q->update([
        'exported_at' => now(),
        'exported_by' => $userId,
    ]);
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
            $j->on('d.vend_id', '=', 'v.' . $vendKey);
            if ($companyId) $j->where('v.company_id', '=', $companyId);
        });
    }

    $header = $headerQ
        ->select(
            'd.id', 'd.cd_no', 'd.vend_id', 'd.disburse_amount', 'd.pay_method',
            'd.bank_id', 'd.explanation', 'd.is_cancel', 'd.check_ref_no',
            DB::raw("to_char(d.disburse_date, 'MM/DD/YYYY') as disburse_date"),
            DB::raw($vendKey ? "COALESCE(v.vend_name,'') as vend_name" : "'' as vend_name"),
            'd.amount_in_words', 'd.workstation_id', 'd.user_id', 'd.created_at'
        )
        ->where('d.id', $id)
        ->when($companyId, fn($q) => $q->where('d.company_id', $companyId))
        ->first();

    if (!$header || $header->is_cancel === 'y') {
        abort(404, 'Cash Disbursement not found or cancelled');
    }

    // Details (BANK first)
    $details = DB::table('cash_disbursement_details as x')
        ->join('account_code as a', function ($j) use ($companyId) {
            $j->on('x.acct_code', '=', 'a.acct_code');
            if ($companyId) $j->where('a.company_id', '=', $companyId);
        })
        ->where('x.transaction_id', $id)
        ->when($companyId, fn($q) => $q->where('x.company_id', $companyId))
        ->orderByRaw("CASE WHEN x.workstation_id = 'BANK' THEN 0 ELSE 1 END ASC")
        ->orderBy('x.id', 'asc')
        ->select('x.acct_code', 'a.acct_desc', 'x.debit', 'x.credit')
        ->get();

    $totalDebit  = (float) $details->sum('debit');
    $totalCredit = (float) $details->sum('credit');

    // --- Use BANK row amount for display (instead of header->disburse_amount) ---
    $bankRow = CashDisbursementDetail::where('transaction_id', (int) $id)
        ->where('workstation_id', 'BANK')
        ->first(['debit', 'credit']);

    $bankAmount = 0.0;
    if ($bankRow) {
        $bankAmount = (float) (($bankRow->credit ?? 0) > 0 ? $bankRow->credit : ($bankRow->debit ?? 0));
    }

    $bankAmountFmt = number_format($bankAmount, 2);

    // ✅ Replace "amount in words" display under PESOS with BANK amount (words)
    $amountInWords = $this->pesoWords($bankAmount);

    // ✅ Replace AMOUNT (right of explanation) with BANK amount (figure)
    $dvAmountFmt = $bankAmountFmt;

    // PDF init
    $pdf = new MyDisbursementVoucherPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);

    // ✅ pass company_id into PDF so Header() can pick the correct logo
    $pdf->setCompanyId($companyId ? (int)$companyId : null);

    // TEMP TEST 2: show exactly what PHP receives from query string
    $pdf->setPreparedByInitials('[' . strtoupper(trim((string) $req->query('prepared_by', ''))) . ']');

    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(15, 30, 15);
    $pdf->SetHeaderMargin(8);
    $pdf->SetFooterMargin(10);

    // ✅ Reserve footer space so table never overlaps footer
    $pdf->SetAutoPageBreak(true, 55);

    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 7);

    $pdf->setDataReceiptDate(\Carbon\Carbon::parse($header->created_at)->format('M d, Y'));
    $pdf->setDataReceiptTime(\Carbon\Carbon::parse($header->created_at)->format('h:i:sa'));

    // ✅ CV number always 6 digits (pad only if purely numeric)
    $rawDvNo  = (string) ($header->cd_no ?? '');
    $dvNumber = ctype_digit($rawDvNo) ? str_pad($rawDvNo, 6, '0', STR_PAD_LEFT) : $rawDvNo;

    $dvDateText   = $header->disburse_date;
    $checkNo      = $header->check_ref_no;
    $payee        = (string) ($header->vend_name ?? $header->vend_id);
    $explanation  = (string) ($header->explanation ?? '');

    // =================== TOP (header + explanation) ===================
    // =================== TOP (fixed-position voucher block + HTML explanation table) ===================

    // CHECK VOUCHER block (drawn manually so X/Y can be controlled exactly)
    $voucherTitleX = 120;
    $voucherTitleY = 24;

    $labelX   = 120;
    $valueX   = 150;
    $lineEndX = 192;

    $row1Y = 36; // CV Number
    $row2Y = 44; // Check Date
    $row3Y = 52; // Check Number

    // Title
    $pdf->SetFont('helvetica', 'B', 16);

    /* Sucden logo blue color */
    $pdf->SetTextColor(0, 102, 153);

    $pdf->SetXY($voucherTitleX, $voucherTitleY);
    $pdf->Cell(60, 6, 'CHECK VOUCHER', 0, 0, 'L', false);

    /* restore normal black text after the header */
    $pdf->SetTextColor(0, 0, 0);

    // Labels
    $pdf->SetFont('helvetica', 'B', 11);

    $pdf->SetXY($labelX, $row1Y);
    $pdf->Cell(28, 5, 'CV Number:', 0, 0, 'L', false);

    $pdf->SetXY($labelX, $row2Y);
    $pdf->Cell(28, 5, 'Check Date:', 0, 0, 'L', false);

    $pdf->SetXY($labelX, $row3Y);
    $pdf->Cell(28, 5, 'Check Number:', 0, 0, 'L', false);

    // Values
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetXY($valueX, $row1Y - 3.0); // move 430967 upward
    $pdf->Cell(32, 6, $dvNumber, 0, 0, 'L', false);
    $pdf->Line($valueX, $row1Y + 5.0, $lineEndX, $row1Y + 5.0);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY($valueX, $row2Y - 0.8);
    $pdf->Cell(32, 5, $dvDateText, 0, 0, 'L', false);
    $pdf->Line($valueX, $row2Y + 4.2, $lineEndX, $row2Y + 4.2);

    $checkNoText  = (string) ($checkNo ?? '');
    $checkNoX     = $valueX;
    $checkNoY     = $row3Y - 0.8;
    $checkNoW     = 42;
    $checkNoLineH = 4.0;

    $pdf->SetFont('helvetica', 'B', 10);
    $checkNoLines = max(1, $pdf->getNumLines($checkNoText, $checkNoW));
    $checkNoH     = $checkNoLines * $checkNoLineH;

    $pdf->SetXY($checkNoX, $checkNoY);
    $pdf->MultiCell(
        $checkNoW,
        $checkNoLineH,
        $checkNoText,
        0,
        'L',
        false,
        1,
        $checkNoX,
        $checkNoY,
        true,
        0,
        false,
        true,
        0,
        'T',
        false
    );
    $pdf->Line($checkNoX, $checkNoY + $checkNoH + 0.6, $lineEndX, $checkNoY + $checkNoH + 0.6);

    // PAY TO / PESOS block (drawn manually so vertical position is exact)
    $payLabelX = 15;
    $payValueX = 42;
    $payToY    = 68;
    $pesosY    = 76;

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetXY($payLabelX, $payToY);
    $pdf->Cell(24, 5, 'PAY TO', 0, 0, 'L', false);

    $pdf->SetXY($payLabelX, $pesosY);
    $pdf->Cell(24, 5, 'PESOS:', 0, 0, 'L', false);

    $pdf->SetFont('helvetica', '', 14);
    $pdf->SetXY($payValueX, $payToY - 0.5);
    $pdf->Cell(95, 6, $payee, 0, 0, 'L', false);
    $pdf->Line($payValueX, $payToY + 5.2, 140, $payToY + 5.2);

    $pesosText  = (string) ($amountInWords ?? '');
    $pesosX     = $payValueX;
    $pesosTopY  = $pesosY - 0.5;
    $pesosW     = 118;
    $pesosLineH = 4.2;

    $pdf->SetFont('helvetica', '', 10);
    $pesosLines = max(1, $pdf->getNumLines($pesosText, $pesosW));
    $pesosH     = $pesosLines * $pesosLineH;

    $pdf->SetXY($pesosX, $pesosTopY);
    $pdf->MultiCell(
        $pesosW,
        $pesosLineH,
        $pesosText,
        0,
        'L',
        false,
        1,
        $pesosX,
        $pesosTopY,
        true,
        0,
        false,
        true,
        0,
        'T',
        false
    );
    $pdf->Line($pesosX, $pesosTopY + $pesosH + 0.4, 147, $pesosTopY + $pesosH + 0.4);

    // Explanation / Amount table starts below the manually drawn top block
    $pdf->SetY(90);

    $tblTop = <<<EOD
<table border="1" cellspacing="0" cellpadding="5">
  <tr>
    <td width="70%" align="center"><font size="10"><b>EXPLANATION</b></font></td>
    <td width="30%" align="center"><font size="10"><b>AMOUNT</b></font></td>
  </tr>
  <tr>
    <td height="60"><font size="10">{$explanation}</font></td>
    <td align="right"><font size="10">{$dvAmountFmt}</font></td>
  </tr>
</table>

<table><tr><td height="2"></td></tr></table>
EOD;

    $pdf->writeHTML($tblTop, true, false, false, false, '');

    // =================== GL TABLE (chunked per page to avoid broken borders) ===================
    $glHeader = <<<EOD
<table border="1" cellpadding="1" cellspacing="0" width="100%">
  <tr>
    <td width="20%" align="center" height="16"><font size="10"><b>ACCOUNT</b></font></td>
    <td width="40%" align="center" height="16"><font size="10"><b>GL ACCOUNT</b></font></td>
    <td width="20%" align="center" height="16"><font size="10"><b>DEBIT</b></font></td>
    <td width="20%" align="center" height="16"><font size="10"><b>CREDIT</b></font></td>
  </tr>
EOD;

    $rowsPerPage = 12; // tweak if needed
    $rows = $details->values();

    for ($i = 0; $i < $rows->count(); $i += $rowsPerPage) {

        // Start a clean new page for each chunk after the first chunk
        if ($i > 0) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 7);
        }

        $chunk = $rows->slice($i, $rowsPerPage);

        $tblGl = $glHeader;

        foreach ($chunk as $d) {
            // ✅ show BLANK instead of 0.00
            $debitVal  = (float) ($d->debit  ?? 0);
            $creditVal = (float) ($d->credit ?? 0);

            $debit  = ($debitVal  > 0) ? number_format($debitVal, 2) : '';
            $credit = ($creditVal > 0) ? number_format($creditVal, 2) : '';

            $acct = (string) $d->acct_code;
            $desc = (string) $d->acct_desc;

            $tblGl .= <<<EOD
  <tr>
    <td align="left" height="14"><font size="10">{$acct}</font></td>
    <td align="left" height="14"><font size="10">{$desc}</font></td>
    <td align="right" height="14"><font size="10">{$debit}</font></td>
    <td align="right" height="14"><font size="10">{$credit}</font></td>
  </tr>
EOD;
        }

        // TOTAL only on last chunk
        if ($i + $rowsPerPage >= $rows->count()) {
            $fmtD = number_format($totalDebit, 2);
            $fmtC = number_format($totalCredit, 2);
            $tblGl .= <<<EOD
  <tr>
    <td height="14"></td>
    <td align="left" height="14"><font size="10">TOTAL</font></td>
    <td align="right" height="14"><font size="10">{$fmtD}</font></td>
    <td align="right" height="14"><font size="10">{$fmtC}</font></td>
  </tr>
EOD;
        }

        $tblGl .= "</table>";

        $pdf->writeHTML($tblGl, true, false, false, false, '');
    }

    // Balanced guard (same as before)
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

    // Mark exported
    DB::table('cash_disbursement')
        ->where('id', (int) $id)
        ->update([
            'exported_at' => now(),
            'exported_by' => $header->user_id ?? null,
        ]);

    $pdfContent = $pdf->Output('disbursementVoucher.pdf', 'S');
    return response($pdfContent, 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="disbursementVoucher.pdf"')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
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
    ->orderByRaw("CASE WHEN x.workstation_id = 'BANK' THEN 0 ELSE 1 END ASC")
    ->orderBy('x.id', 'asc')

    ->select('x.acct_code','a.acct_desc','x.debit','x.credit')
    ->get();


    $totalDebit  = (float)$details->sum('debit');
    $totalCredit = (float)$details->sum('credit');

// ✅ Block Excel export when unbalanced (same rule as PDF)
if (abs($totalDebit - $totalCredit) > 0.005) {
    $html = sprintf(
        '<!doctype html><meta charset="utf-8">
        <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px}</style>
        <h2>Cannot download Disbursement Voucher</h2>
        <p>Details are not balanced. Please ensure <b>Debit = Credit</b> before downloading.</p>
        <p><b>Debit:</b> %s<br><b>Credit:</b> %s</p>',
        number_format($totalDebit, 2),
        number_format($totalCredit, 2)
    );
    return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
}

// ✅ Mark as exported ONLY when download is allowed
DB::table('cash_disbursement')
    ->where('id', (int) $id)
    ->update([
        'exported_at' => now(),
        'exported_by' => $header->user_id ?? null,
    ]);


    //$dvAmount = (float)($header->disburse_amount ?? 0);
    //$amountInWords = $this->pesoWords($dvAmount);

// --- Use BANK row amount for display (instead of header->disburse_amount) ---
$bankRow = CashDisbursementDetail::where('transaction_id', (int) $id)
    ->where('workstation_id', 'BANK')
    ->first(['debit', 'credit']);

// For Disbursement, BANK is typically CREDIT; fallback to DEBIT if needed.
$bankAmount = 0.0;
if ($bankRow) {
    $bankAmount = (float) (($bankRow->credit ?? 0) > 0 ? $bankRow->credit : ($bankRow->debit ?? 0));
}

$bankAmountFmt = number_format($bankAmount, 2);

// ✅ Replace "amount in words" display under PESOS with BANK amount (figure)
$amountInWords = $this->pesoWords($bankAmount);

// ✅ Replace AMOUNT (right of explanation) with BANK amount (figure)
$dvAmount = $bankAmountFmt;

// Keep original header amount available if you still need it elsewhere
$dvAmount = (float)($bankAmount ?? 0);




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
        if (((float)($d->debit ?? 0)) > 0)  $sheet->setCellValue("C{$row}", (float)$d->debit);
        if (((float)($d->credit ?? 0)) > 0) $sheet->setCellValue("D{$row}", (float)$d->credit);
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
 * True if transaction was already exported (PDF/Excel).
 * Spreadsheet lock trigger: exported_at is not null.
 */
protected function isExported(int $transactionId): bool
{
    return DB::table('cash_disbursement')
        ->where('id', $transactionId)
        ->whereNotNull('exported_at')
        ->exists();
}

/**
 * Spreadsheet (details) edit rule:
 * - Always block if cancelled
 * - If NOT exported yet => details can be edited WITHOUT approval
 * - If exported already => requires ACTIVE approved edit window
 */
protected function requireDetailsEditable(int $transactionId, ?int $companyId = null): void
{
    $this->requireNotCancelled($transactionId);

    if ($this->isExported($transactionId)) {
        $this->requireApprovedEdit($transactionId, $companyId);
    }
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

    // ✅ Block duplicate Check/Ref # per company (exclude this record)
    $ref = trim((string) ($data['check_ref_no'] ?? ''));
    if ($ref !== '') {
        $dup = CashDisbursement::where('company_id', (int) $companyId)
            ->whereRaw('LOWER(check_ref_no) = ?', [strtolower($ref)])
            ->where('id', '<>', (int) $tx->id)
            ->exists();

        if ($dup) {
            return response()->json([
                'message' => 'Duplicate Check / Ref #. This Check/Ref # already exists.',
            ], 422);
        }
    }

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
    ->where('company_id', (int) $companyId)
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

    // ✅ Block duplicate Check/Ref # per company (exclude this record)
    $ref = trim((string) ($data['check_ref_no'] ?? ''));
    if ($ref !== '') {
        $dup = CashDisbursement::where('company_id', (int) $companyId)
            ->whereRaw('LOWER(check_ref_no) = ?', [strtolower($ref)])
            ->where('id', '<>', (int) $tx->id)
            ->exists();

        if ($dup) {
            return response()->json([
                'message' => 'Duplicate Check / Ref #. This Check/Ref # already exists.',
            ], 422);
        }
    }


    // ✅ use the SAME cancel guard your working updateMain uses
    // (If you only have requireNotCancelled(), keep that.)
    $this->requireNotCancelled((int) $tx->id);
// ✅ Enforce new rule: once exported, edits require approval (even for header)
if ($this->isExported((int) $tx->id)) {
    abort(403, 'This transaction was already exported. Edit approval is required.');
}

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
    ->where('company_id', (int) $companyId)
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
 * ✅ Check if Check/Ref # already exists (per company).
 * Used by frontend "live check" before save.
 *
 * GET /cash-disbursement/check-ref-exists?company_id=1&check_ref_no=ABC&exclude_id=123
 */
public function checkRefExists(Request $req)
{
    $data = $req->validate([
        'company_id'    => ['required','integer'],
        'check_ref_no'  => ['required','string','max:25'],
        'exclude_id'    => ['nullable'],
    ]);

    $companyId = (int) $data['company_id'];
    $ref = trim((string) $data['check_ref_no']);
    $excludeId = (int) ($data['exclude_id'] ?? 0);

    $q = CashDisbursement::where('company_id', $companyId)
        ->whereRaw('LOWER(check_ref_no) = ?', [strtolower($ref)]);

    if ($excludeId > 0) {
        $q->where('id', '<>', $excludeId);
    }

    $hit = $q->orderByDesc('id')->first(['id','cd_no']);

    return response()->json([
        'exists'      => (bool) $hit,
        'id'          => $hit?->id,
        'cd_no'       => $hit?->cd_no,
        'company_id'  => $companyId,
        'check_ref_no'=> $ref,
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
