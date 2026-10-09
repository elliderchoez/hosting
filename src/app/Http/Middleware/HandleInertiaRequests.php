<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $recruiter = \Illuminate\Support\Facades\Auth::guard('recruiter')->user();

        $unreadMessagesCount = 0;
        if ($user) {
            $unreadMessagesCount = \App\Models\ContactLog::where('student_id', $user->id)
                ->whereNull('read_at')
                ->count();
        }

        $authUser = null;
        if ($user) {
            $authUser = $user;
        } elseif ($recruiter) {
            $authUser = [
                'id' => $recruiter->id,
                'name' => $recruiter->name,
                'email' => $recruiter->email,
                'role' => $recruiter->account_type, // 'company' or 'recruiter'
                'is_partner' => true,
                'company' => $recruiter->company,
                'position' => $recruiter->position,
                'tax_id' => $recruiter->tax_id,
                'website_url' => $recruiter->website_url,
                'linkedin_url' => $recruiter->linkedin_url,
                'verified' => $recruiter->verified,
                'status' => $recruiter->status,
            ];
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $authUser,
                'recruiter' => $recruiter,
                'unreadMessagesCount' => $unreadMessagesCount,
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
