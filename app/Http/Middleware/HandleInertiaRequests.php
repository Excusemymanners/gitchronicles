<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * "auth.user" is sent to every page, so it holds only id, name, email and is_admin
     * (never the password hash or the remember token). is_admin only shows the „admin” tag in the header:
     * what a user may do always comes from the policy (the "can_…" props of each page).
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user() === null ? null : [
                    ...$request->user()->only(['id', 'name', 'email']),
                    'is_admin' => (bool) $request->user()->is_admin,
                ],
            ],
        ];
    }
}
