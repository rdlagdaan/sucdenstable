<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MillList;
use Illuminate\Support\Facades\DB;

class MillListController extends Controller
{
    public function index()
    {
        return MillList::select('mill_id', 'mill_name', 'insurance_rate', 'storage_rate', 'days_free', 'market_value', 'ware_house', 'shippable_flag', 'company_id')->orderBy('mill_name')->get();
    }

}



