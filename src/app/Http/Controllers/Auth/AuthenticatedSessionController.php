<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response|RedirectResponse
    {
        if (Auth::guard('recruiter')->check()) {
            session()->forget('url.intended');
            return redirect()->route('welcome');
        }

        if (Auth::guard('web')->check()) {
            $user = Auth::guard('web')->user();
            return $user->isAdmin()
                ? redirect()->route('admin.dashboard')
                : redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        if (Auth::guard('recruiter')->check()) {
            // Desloguear guard 'web' para no mezclar sesión con admin/estudiante
            Auth::guard('web')->logout();

            // Un recruiter/empresa jamás debe ser enviado a /admin ni /dashboard
            $intended = session()->pull('url.intended');
            if ($intended && !str_contains($intended, '/admin') && !str_contains($intended, '/dashboard') && !str_contains($intended, '/profile/account')) {
                return redirect()->to($intended);
            }

            return redirect()->route('welcome')->with('status', '¡Bienvenido! Has iniciado sesión como empresa aliada.');
        }

        if ($request->user() && $request->user()->isAdmin()) {
            Auth::guard('recruiter')->logout();
            return redirect()->intended(route('admin.dashboard'));
        }

        Auth::guard('recruiter')->logout();
        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        Auth::guard('recruiter')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
