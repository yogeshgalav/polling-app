<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class AuthController extends Controller
{
    public function user(Request $request)
    {
        return response()->json([
            "user" => $request->user(),
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            "name" => ["required", "string", "max:255"],
            "email" => ["required", "string", "lowercase", "email", "max:255", "unique:" . User::class],
            "password" => ["required", "confirmed", Rules\Password::defaults()],
            "is_admin" => ["nullable", "boolean"],
        ]);

        $isAdmin = (bool) ($validated["is_admin"] ?? false);

        $user = DB::transaction(function () use ($validated, $isAdmin) {
            $user = User::create([
                "name" => $validated["name"],
                "email" => $validated["email"],
                "password" => Hash::make($validated["password"]),
            ]);

            if ($isAdmin) {
                $user->admin()->create();
            }

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            "user" => $user,
            "redirect_to" => $user->isAdmin() ? route("admin.polls.index") : route("polls.index"),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            "email" => ["required", "string", "email"],
            "password" => ["required", "string"],
            "remember" => ["sometimes", "boolean"],
        ]);

        $remember = (bool) ($credentials["remember"] ?? false);

        if (!Auth::attempt(["email" => $credentials["email"], "password" => $credentials["password"]], $remember)) {
            return response()->json([
                "message" => "The provided credentials are incorrect.",
                "errors" => [
                    "email" => ["The provided credentials are incorrect."],
                ],
            ], 422);
        }

        $request->session()->regenerate();

        return response()->json([
            "user" => $request->user(),
            "redirect_to" => $request->user()?->isAdmin()
                ? route("admin.polls.index")
                : route("polls.index"),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard("web")->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}

