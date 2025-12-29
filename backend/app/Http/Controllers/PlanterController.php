<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PlantersList;

class Planterontroller extends Controller
{
public function lookup(Request $req)
{
    $tin = trim((string) $req->query('tin', ''));
    if ($tin === '') {
        return response()->json(['tin' => '', 'display_name' => '']);
    }

    // company scope:
    // prefer header, fallback to query param (so FE can still pass company_id if needed)
    $companyId = (string) (
        $req->header('X-Company-ID')
        ?? $req->query('company_id', '')
    );

    $q = \App\Models\PlantersList::query()->where('tin', $tin);

    // only filter by company if we received one
    if ($companyId !== '') {
        $q->where('company_id', $companyId);
    }

    $p = $q->first();

    if (!$p) {
        return response()->json(['tin' => $tin, 'display_name' => '']);
    }

    $dn = trim((string)($p->display_name ?? ''));

    if ($dn === '') {
        $mi = trim((string)($p->middle_name ?? ''));
        $mi = $mi !== '' ? (' ' . mb_substr($mi, 0, 1) . '.') : '';
        $dn = trim(($p->last_name ?? '') . ', ' . ($p->first_name ?? '') . $mi);
    }

    return response()->json([
        'tin'          => (string) $p->tin,
        'display_name' => $dn,
    ]);
}

}
