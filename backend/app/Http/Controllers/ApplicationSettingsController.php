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

    public function getPbnNumber(Request $request)
    {
        $companyId = $request->query('company_id'); // e.g., 1 for SUCDEN, 2 for AMEROP
        $year = date('Y');
        $apsetCode = 'PBNNO';

        if (!$companyId) {
            return response()->json(['error' => 'Missing company_id'], 400);
        }

        DB::beginTransaction();

        try {
            // Lock the record for update
            $setting = DB::table('application_settings')
                ->where('apset_code', $apsetCode)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if (!$setting) {
                // Insert a new row if it doesn't exist
                DB::table('application_settings')->insert([
                    'apset_code' => $apsetCode,
                    'value' => '1',
                    'description' => "Auto-numbering for PBN {$year} (company_id={$companyId})",
                    'company_id' => $companyId,
                    'updated_at' => now(),
                ]);
                $currentValue = 1;
            } else {
                $currentValue = (int) $setting->value;
                DB::table('application_settings')
                    ->where('apset_code', $apsetCode)
                    ->where('company_id', $companyId)
                    ->update([
                        'value' => (string) ($currentValue + 1),
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();

            // Optional: resolve company code label (for output)
            $companyCode = match ((int)$companyId) {
                1 => 'SUC',
                2 => 'AMR',
                default => 'GEN',
            };

            $padded = str_pad($currentValue, 6, '0', STR_PAD_LEFT);
            $formatted = "PBN-{$year}-{$companyCode}-{$padded}";

            return response()->json(['value' => $formatted]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to generate PBN number'], 500);
        }
    }



}
