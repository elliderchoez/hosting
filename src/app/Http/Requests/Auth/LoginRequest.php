<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            // Check if user is a registered Recruiter or Company
            $recruiter = \App\Models\Recruiter::where('email', strtolower($this->email))->first();
            if ($recruiter && \Illuminate\Support\Facades\Hash::check($this->password, $recruiter->password)) {
                if ($recruiter->status === 'pending') {
                    RateLimiter::clear($this->throttleKey());
                    throw ValidationException::withMessages([
                        'email' => 'Tu solicitud de cuenta aún está en revisión por la administración de la ULEAM. Te notificaremos una vez sea validada.',
                    ]);
                }

                if ($recruiter->status === 'rejected') {
                    RateLimiter::clear($this->throttleKey());
                    $reason = $recruiter->rejection_reason ?: 'No cumple con las políticas de validación institucional.';
                    throw ValidationException::withMessages([
                        'email' => "Tu solicitud de cuenta fue rechazada por la administración de Nexus Academic. Motivo: {$reason}",
                    ]);
                }

                if ($recruiter->status === 'inactive' || ! $recruiter->verified) {
                    RateLimiter::clear($this->throttleKey());
                    throw ValidationException::withMessages([
                        'email' => 'Tu cuenta ha sido desactivada temporalmente por la administración de Nexus Academic. Por favor comunícate con soporte.',
                    ]);
                }

                // If approved / verified, log in using the recruiter guard
                Auth::guard('recruiter')->login($recruiter, $this->boolean('remember'));
                RateLimiter::clear($this->throttleKey());
                return;
            }

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = Auth::user();
        if ($user && ! $user->is_active) {
            $blockedReason = $user->blocked_reason ?: 'Infracción institucional o baja de matrícula.';
            Auth::logout();
            $this->session()->invalidate();
            $this->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => "Tu cuenta ha sido desactivada o bloqueada por la administración. Motivo: {$blockedReason}",
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
