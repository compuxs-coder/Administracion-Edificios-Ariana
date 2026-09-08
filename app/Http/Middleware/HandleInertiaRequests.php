<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Src\Edificio\Application\Services\AccesoEdificioService;

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
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $permissionMap = $request->user() === null
            ? []
            : app(AccesoEdificioService::class)->permissionMap((string) $request->user()->getAuthIdentifier());

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
                'access' => [
                    'byBuilding' => $permissionMap,
                    'any' => array_values(array_unique(array_merge(...array_values($permissionMap ?: [[]])))),
                ],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'ziggy' => fn () => [
                ...\Illuminate\Support\Facades\Route::current() ? (new \Tighten\Ziggy\Ziggy)->toArray() : [],
                'location' => $request->url(),
            ],
        ];
    }
}
