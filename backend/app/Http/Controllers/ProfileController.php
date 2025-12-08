<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function photo(Request $request)
    {
        // Default avatar kapag walang session o walang file
        $default = public_path('avatar-default.png');  // maglagay ka ng file na ito

        // Kung may session at may user, subukan ang personal photo
        $user = auth()->user(); // ok lang kahit walang auth middleware; web session pa rin ito kung meron
        if ($user) {
            // Adjust extension/path as needed
            $path = storage_path('app/public/profile_photos/' . $user->id . '.jpg');
            if (file_exists($path)) {
                return response()->file($path)->header('Cache-Control', 'no-cache, no-store, must-revalidate');
            }
        }

        // Fallback (never redirects)
        return response()->file($default)->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
