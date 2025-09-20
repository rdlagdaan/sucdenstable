<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PbnController extends Controller
{
    // PBN # combobox (headers: PBN No, Sugar Type, Vendor ID, Vendor Name, Crop Year, PBN Date)
public function list(Request $req)
{
    $q = trim($req->query('q', ''));
    $posted = $req->query('include_posted'); // no default → only filter if sent

    $rows = DB::table('pbn_entry as p')
        ->select([
            'p.pbn_number',
            'p.sugar_type',
            'p.vend_code as vendor_code',
            'p.vendor_name',
            'p.crop_year',
            'p.pbn_date',
        ])
        ->when($posted !== null, fn($w) => $w->where('p.posted_flag', (int) $posted))
        ->when($q !== '', function ($w) use ($q) {
            $like = "%{$q}%";
            $w->where(function ($x) use ($like) {
                // Use ILIKE (PostgreSQL) so search is case-insensitive
                $x->whereRaw('p.pbn_number ILIKE ?', [$like])
                  ->orWhereRaw('p.vend_code ILIKE ?', [$like])     // Vendor ID
                  ->orWhereRaw('p.vendor_name ILIKE ?', [$like])   // Vendor Name
                  ->orWhereRaw('CAST(p.crop_year AS TEXT) ILIKE ?', [$like])
                  ->orWhereRaw('TO_CHAR(p.pbn_date, \'YYYY-MM-DD\') ILIKE ?', [$like]);
            });
        })
        ->orderBy('p.pbn_date', 'asc')
        ->limit(100)
        ->get();

    return response()->json($rows);
}



    // Item # combobox for a given PBN
    public function items(Request $req)
    {
        $pbn = $req->query('pbn_number', '');

        // Adjust table/columns below to your actual PBN item detail table
        $rows = DB::table('pbn_entry_details as d')
            ->select([
                'd.row',
                'd.mill',
                'd.quantity',
                'd.unit_cost',
                'd.commission',
                DB::raw('NULL as mill_code'),
            ])
            ->where('d.pbn_number', $pbn)
            ->orderBy('d.row')
            ->get();

        return response()->json($rows);
    }
}
