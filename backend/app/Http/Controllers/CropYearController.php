<?php 
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CropYear;

class CropYearController extends Controller
{
    public function index()
    {
        return response()->json(CropYear::all());
    }
}
