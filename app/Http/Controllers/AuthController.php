<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Issue an API token for an existing user. There is no registration
     * endpoint: the seeder creates a demo user to log in with, since
     * building account registration is not part of this feature.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = Auth::user()->createToken('api')->plainTextToken;

        return response()->json(['token' => $token]);
    }
}
