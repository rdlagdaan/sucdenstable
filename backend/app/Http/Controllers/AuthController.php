<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Str;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

use App\Models\UsersEmployee;

use Carbon\Carbon;


class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|alpha_num|max:25',
            'email' => 'required|email',
            'password' => [
                'required',
                'min:8',
                'regex:/[a-z]/',      // at least one lowercase letter
                'regex:/[A-Z]/',      // at least one uppercase letter
                'regex:/[0-9]/'       // at least one digit
            ],
            'role_id' => 'required|exists:roles,id',
            'first_name' => 'required|max:50',
            'last_name' => 'required|max:50',
            'middle_name' => 'required|max:50',
            'designation' => 'required|max:50',

        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $user = new UsersEmployee();
            $user->username = $request->username;
            $user->email_address = $request->email;
            $user->password = Hash::make($request->password);
            $user->salt = Str::random(16);
            $user->activation_code = Str::uuid();
            $user->forgotten_password_code = null;
            $user->forgotten_password_time = null;
            $user->remember_code = null;
            $user->date_created = Carbon::now();
            $user->created_by = $request->username;
            $user->last_login = null;
            $user->role_id = $request->role_id;
            $user->status = 'active';
            $user->active = true;
            $user->first_name = $request->first_name;
            $user->last_name = $request->last_name;
            $user->middle_name = $request->middle_name;
            $user->designation = $request->designation;

            $user->time_stamp = Carbon::now();

            $user->save();

            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => $user
            ], 201);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'The user already exists!'
            ], 409);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Database error.',
                'error' => $e->getMessage()
            ], 500);
        }    
    }


    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'company_id' => 'required|integer',
        ]);

        $user = UsersEmployee::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->active) {
            return response()->json(['message' => 'Account not active'], 403);
        }

        Auth::login($user); // Only needed if using session
        $token = $user->createToken('auth_token')->plainTextToken;

        // Update last login
        $user->last_login = now();
        $user->save();

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email_address,
                'role_id' => $user->role_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'designation' => $user->designation,
                'company_id' => $request->company_id, // from frontend
            ]
        ]);
    }

public function logout(Request $request)
{
    $user = $request->user();

    if ($user) {
        // If using Sanctum token-based auth, delete the token
        if (method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        // Optional: if you want to flush session too
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    return response()->json(['message' => 'Logged out successfully']);
}


}
