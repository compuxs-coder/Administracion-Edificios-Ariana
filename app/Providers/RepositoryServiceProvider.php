<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Los bindings ahora se manejan en BoundedContextServiceProvider
    }

    public function boot(): void
    {
        //
    }
}
