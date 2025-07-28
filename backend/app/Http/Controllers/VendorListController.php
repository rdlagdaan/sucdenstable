<?php 

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VendorList;

class VendorListController extends Controller
{
    public function index()
    {
        return response()->json(VendorList::all());
    }
}
