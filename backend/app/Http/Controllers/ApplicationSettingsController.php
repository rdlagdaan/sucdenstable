<?php

namespace App\Http\Controllers;
//namespace App\Http\Controllers\PbnEntryController;
use App\Models\ApplicationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class  ApplicationSettingsController extends Controller
{
    public function getSetting($code): JsonResponse
    {
        $setting = ApplicationSetting::where('apset_code', $code)->first();

        if (!$setting) {
            return response()->json(['message' => 'Setting not found'], 404);
        }

        return response()->json(['value' => (int) $setting->value]);
    }    //

    public function get($code)
    {
        $value = DB::table('application_settings')->where('apset_code', $code)->value('value');
        return response()->json(['value' => $value]);
    }

public function getNextPbnNumber(Request $request)
{
    $request->validate([
        'company_id' => 'required|integer',
        'sugar_type' => 'required|string',
    ]);

    $companyId = $request->input('company_id');
    $sugarType = $request->input('sugar_type');

    $setting = DB::table('application_settings')
        ->where('apset_code', 'PBNNO')
        ->where('company_id', $companyId)
        ->where('type', $sugarType)
        ->lockForUpdate()
        ->first();

    if (!$setting) {
        return response()->json(['error' => 'Application setting not found.'], 404);
    }

    $prefix = $sugarType;
    $currentValue = intval($setting->value);
    $nextValue = $currentValue + 1;

    $formattedNumber = str_pad($nextValue, 6, '0', STR_PAD_LEFT);
    $pbnNumber = $prefix . $formattedNumber;

    // Update the setting value
    DB::table('application_settings')
        ->where('id', $setting->id)
        ->update(['value' => $nextValue]);

    return response()->json(['pbn_number' => $pbnNumber]);
}


}
