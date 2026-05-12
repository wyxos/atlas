<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class AuthentikController extends Controller
{
    /**
     * Redirect to Authentik for authentication.
     */
    public function redirect(Request $request)
    {
        return Socialite::driver('authentik')
            ->redirect();
    }

    /**
     * Handle the Authentik callback.
     */
    public function callback(Request $request)
    {
        $authentikUser = Socialite::driver('authentik')->user();

        $user = User::where('email', $authentikUser->getEmail())->first();

        if (! $user) {
            $user = User::create([
                'name' => $authentikUser->getName() ?? $authentikUser->getEmail(),
                'email' => $authentikUser->getEmail(),
                'password' => bcrypt(str()->random(32)),
                'is_admin' => false,
            ]);
        }

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
