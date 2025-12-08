<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = Auth::user();
        $row = DB::table('users_employees')->where('id', $user->id)->first();

        if (!$row) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Build a URL we can always serve via the API (no /storage dependency in the FE container)
        $exists = false;
        foreach (['jpg','jpeg','png'] as $ext) {
            if (Storage::disk('public')->exists("employee_photos/{$row->id}.{$ext}")) {
                $exists = true;
                break;
            }
        }
        $photoUrl = $exists ? route('user.profile.photo.show', [], false) : null;
        
        return response()->json([
            'id'            => $row->id,
            'username'      => $row->username,
            'email_address' => $row->email_address,
            'first_name'    => $row->first_name,
            'middle_name'   => $row->middle_name,
            'last_name'     => $row->last_name,
            'photo_url'     => $photoUrl,
        ]);
    }


// app/Http/Controllers/UserProfileController.php

public function showPhoto(Request $request)
{
    $user = Auth::user();
    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    try {
        $rel = null;
        foreach (['jpg','jpeg','png'] as $ext) {
            $c = "employee_photos/{$user->id}.{$ext}";
            if (Storage::disk('public')->exists($c)) { $rel = $c; break; }
        }

        if (!$rel) {
            // Nothing uploaded
            return response('', 204);
        }

        // Determine MIME
        $ext  = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';

        // Use Storage's response() which streams via Flysystem (Octane-safe)
        // We also force inline display and disable caching
        return Storage::disk('public')->response($rel, null, [
            'Content-Type'   => $mime,
            'Cache-Control'  => 'no-cache, no-store, must-revalidate',
            'Pragma'         => 'no-cache',
            'Expires'        => '0',
            'Content-Disposition' => 'inline',
        ]);
    } catch (\Throwable $e) {
        \Log::error('showPhoto failed', [
            'user_id' => $user?->id,
            'err'     => $e->getMessage(),
        ]);
        return response()->json(['message' => 'Photo not available'], 404);
    }
}






    // PUT /api/user/profile (unchanged)

    // POST /api/user/profile/password (unchanged)

    // POST /api/user/profile/photo (just change the returned URL)
    public function uploadPhoto(Request $request)
    {
        $user = Auth::user();
        $current = DB::table('users_employees')->where('id', $user->id)->first();
        if (!$current) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'photo' => ['required','image','mimes:jpg,jpeg,png','max:2048'],
        ]);

        $file = $request->file('photo');
        $ext  = strtolower($file->getClientOriginalExtension());

        try {
            foreach (['jpg','jpeg','png'] as $e) {
                $old = "employee_photos/{$current->id}.{$e}";
                if (Storage::disk('public')->exists($old)) {
                    Storage::disk('public')->delete($old);
                }
            }

            $path = $file->storeAs('employee_photos', "{$current->id}.{$ext}", 'public');

            DB::table('users_employees')->where('id', $current->id)->update(['updated_at' => now()]);

            // Return the API URL (proxied by /api in your FE nginx)
            return response()->json([
                'message'   => 'Photo uploaded',
                // relative path
                'photo_url' => route('user.profile.photo.show', [], false),
            ]);


        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Photo upload failed'], 500);
        }
    }

    // PUT /api/user/profile  (basic fields only)
    public function update(Request $request)
    {
        $user = Auth::user();
        $current = DB::table('users_employees')->where('id', $user->id)->first();
        if (!$current) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'username'      => ['required','alpha_num','max:25', Rule::unique('users_employees','username')->ignore($current->id)],
            'email_address' => ['required','email','max:100', Rule::unique('users_employees','email_address')->ignore($current->id)],
            'first_name'    => ['required','max:50'],
            'middle_name'   => ['nullable','max:50'],
            'last_name'     => ['required','max:50'],
        ]);

        DB::table('users_employees')->where('id', $current->id)->update([
            'username'      => $validated['username'],
            'email_address' => $validated['email_address'],
            'first_name'    => $validated['first_name'],
            'middle_name'   => $validated['middle_name'] ?? '',
            'last_name'     => $validated['last_name'],
            'updated_at'    => now(),
        ]);

        return response()->json(['message' => 'Profile updated successfully']);
    }

    // POST /api/user/profile/password  (separate password update; validates old password first)
    public function updatePassword(Request $request)
    {
        $user = Auth::user();
        $current = DB::table('users_employees')->where('id', $user->id)->first();
        if (!$current) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'old_password'              => ['required','string'],
            'new_password'              => ['required', Password::min(8)], // tweak policy as needed
            'new_password_confirmation' => ['required','same:new_password'],
        ]);

        // Verify old password
        if (!Hash::check($validated['old_password'], $current->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        DB::table('users_employees')->where('id', $current->id)->update([
            'password'   => Hash::make($validated['new_password']),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Password updated successfully']);
    }








}
