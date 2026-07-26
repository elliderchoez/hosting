<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Display the user's professional profile.
     */
    public function showProfessional(Request $request): Response
    {
        $user = $request->user();
        
        $profile = \App\Models\Profile::firstOrCreate(
            ['user_id' => $user->id],
            ['bio' => '', 'skills' => [], 'education' => []]
        );

        return Inertia::render('Profile/Show', [
            'profile' => $profile,
            'status' => session('status'),
        ]);
    }

    /**
     * Update additional student profile details (bio, skills, education, etc.)
     */
    public function updateDetails(Request $request): RedirectResponse
    {
        $user = $request->user();
        
        $request->validate([
            'bio' => 'nullable|string|max:1000',
            'phone' => 'nullable|string|max:20',
            'github_username' => 'nullable|string|max:100',
            'linkedin_url' => 'nullable|string|max:255|url',
            'skills' => 'nullable|array',
            'education' => 'nullable|array',
        ]);

        $profile = \App\Models\Profile::firstOrCreate(['user_id' => $user->id]);
        
        $profile->update([
            'bio' => $request->bio,
            'phone' => $request->phone,
            'github_username' => $request->github_username,
            'linkedin_url' => $request->linkedin_url,
            'skills' => $request->skills ?? [],
            'education' => $request->education ?? [],
        ]);

        return Redirect::route('profile.professional')->with('status', 'Detalles profesionales actualizados correctamente.');
    }

    /**
     * View or download print-friendly HTML CV.
     */
    public function generateCv(Request $request)
    {
        $user = Auth::user();
        $profile = \App\Models\Profile::where('user_id', $user->id)->first();

        if (!$profile) {
            return redirect()->back()->withErrors(['error' => 'Por favor completa tu perfil primero.']);
        }

        $projects = \App\Models\Project::where('user_id', $user->id)->get();

        // Return a beautiful print-friendly CV layout that the user can print to PDF
        return view('cv_template', [
            'user' => $user,
            'profile' => $profile,
            'projects' => $projects
        ]);
    }
}
