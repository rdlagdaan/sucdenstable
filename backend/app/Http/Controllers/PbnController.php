<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PbnController extends Controller
{
    // PBN # combobox (headers: PBN No, Sugar Type, Vendor ID, Vendor Name, Crop Year, PBN Date)
    public function list(Request $req)
    {
        $req->validate([
            'company_id' => 'required|integer',
            'q'          => 'nullable|string',
            'vend_code'  => 'nullable|string',
            'sugar_type' => 'nullable|string',
            'crop_year'  => 'nullable|string',
        ]);

        $companyId = (int) $req->query('company_id');
        $q         = trim((string) $req->query('q', ''));
        $vendCode  = $req->query('vend_code');
        $sugarType = $req->query('sugar_type');
        $cropYear  = $req->query('crop_year');

        $rows = DB::table('pbn_entry')
            ->where('company_id', $companyId)
            ->where('posted_flag', 1)                 // ✅ posted only
            ->when($vendCode,  fn($w) => $w->where('vend_code',  $vendCode))
            ->when($sugarType, fn($w) => $w->where('sugar_type', $sugarType))
            ->when($cropYear,  fn($w) => $w->where('crop_year',  $cropYear))
            ->when($q !== '', function ($w) use ($q) {
                $qq = strtolower($q);
                $w->where(function ($k) use ($qq) {
                    $k->whereRaw('LOWER(pbn_number) LIKE ?', ["%{$qq}%"])
                      ->orWhereRaw("TO_CHAR(pbn_date,'YYYY-MM-DD') LIKE ?", ["%{$qq}%"]);
                });
            })
            ->orderByDesc('pbn_date')
            ->limit(100)
            ->get([
                'id','pbn_number','pbn_date',
                'sugar_type','crop_year','vend_code','vendor_name',
            ]);

        return response()->json($rows);
    }



    // Item # combobox for a given PBN
    public function items(Request $req)
    {
        $data = $req->validate([
            'company_id'  => 'required|integer',
            'pbn_number'  => 'required|string',
        ]);

        $rows = DB::table('pbn_entry_details as d')
            ->join('pbn_entry as e', 'e.id', '=', 'd.pbn_entry_id')
            ->where('e.company_id', (int) $data['company_id'])
            ->where('d.pbn_number', $data['pbn_number'])
            ->orderBy('d.row')
            ->get([
                'd.row',
                'd.mill',
                'd.quantity',
                'd.unit_cost',
                'd.commission',
                DB::raw('NULL as mill_code'),
            ]);

        return response()->json($rows);
    }

}
