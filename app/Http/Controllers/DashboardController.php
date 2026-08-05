<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Mostrar el dashboard principal
     */
    public function index(): Response
    {
        return Inertia::render('Dashboard');
    }
}
