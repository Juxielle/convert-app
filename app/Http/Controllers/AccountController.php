<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountRequest;
use App\Models\User;
use App\Services\AccountMail;
use App\Services\Subscriptions;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user ? [...$user->only(['id', 'name', 'email', 'role']), 'plan' => app(Subscriptions::class)->current($user)->plan->slug] : null;

        return response()->json(['data' => ['user' => $profile, 'csrf_token' => csrf_token(),
            'password_reset_available' => AccountMail::available()]]);
    }

    public function register(AccountRequest $request): JsonResponse
    {
        abort_if($request->user(), 409);
        $user = DB::transaction(fn () => User::create($request->safe()->only(['name', 'email', 'password'])));
        Auth::login($user);
        $request->session()->regenerate();

        return $this->session($request)->setStatusCode(201);
    }

    public function login(AccountRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->safe()->only(['email', 'password']))) {
            throw ValidationException::withMessages(['email' => ['Adresse email ou mot de passe incorrect.']]);
        }
        $request->session()->regenerate();

        return $this->session($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->session($request);
    }

    public function profile(AccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $values = $request->safe()->only(['name', 'email']);
        if ($user->email !== $values['email']) {
            $user->email_verified_at = null;
        }
        $user->fill($values)->save();

        return $this->session($request);
    }

    public function password(AccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => $request->validated('password'), 'remember_token' => Str::random(60)])->save();
        $request->session()->put('password_hash_web', $user->password);
        if (config('session.driver') === 'database') {
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $request->session()->regenerate();

        return $this->session($request);
    }

    public function forgot(AccountRequest $request): JsonResponse
    {
        if (! AccountMail::available()) {
            return response()->json(['error' => ['code' => 'MAIL_UNAVAILABLE', 'message' => 'La réinitialisation nécessite un service email configuré.']], 503);
        }
        try {
            Password::sendResetLink($request->safe()->only('email'));
        } catch (\Throwable) {
            // Native mail transport exceptions can contain delivery details; discard them.
            return response()->json(['error' => ['code' => 'MAIL_UNAVAILABLE', 'message' => 'L’envoi email est momentanément indisponible.']], 503);
        }

        return response()->json(['data' => ['message' => 'Si un compte correspond à cette adresse, un lien vous sera envoyé.']]);
    }

    public function reset(AccountRequest $request): JsonResponse
    {
        $status = Password::reset($request->safe()->only(['email', 'password', 'password_confirmation', 'token']), function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['token' => ['Ce lien est invalide ou a expiré.']]);
        }

        return response()->json(['data' => ['message' => 'Votre mot de passe a été modifié. Vous pouvez vous connecter.']]);
    }
}
