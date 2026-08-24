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
        $unreadMessagesCount = 0;
        if ($user) {
            $unreadMessagesCount = \App\Models\ContactLog::where('student_id', $user->id)
                ->whereNull('read_at')
                ->count();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'unreadMessagesCount' => $unreadMessagesCount,
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
