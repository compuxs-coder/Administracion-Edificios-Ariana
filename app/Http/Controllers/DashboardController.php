<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use Src\Finanzas\Application\Actions\PaginateCarteraAction;

class DashboardController extends Controller
{
    public function __construct(private readonly PaginateCarteraAction $cartera) {}
    /**
     * Mostrar el dashboard principal
     */
    public function index(Request $request): Response
    {
        $result = $this->cartera->execute((string) $request->user()->getAuthIdentifier(), ['per_page' => 1]);

        return Inertia::render('Dashboard', ['cartera' => $result['summary']]);
    }
}
