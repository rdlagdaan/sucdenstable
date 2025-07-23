<?php

namespace App\Http\Controllers;
use App\Models\ApplicationSetting;
use Illuminate\Http\JsonResponse;

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
}
