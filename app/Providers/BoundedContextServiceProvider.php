<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Src\Auth\Infrastructure\Repositories\EloquentUserRepository;
use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Infrastructure\Repositories\EloquentFacturaRepository;

class BoundedContextServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(FacturaRepositoryInterface::class, EloquentFacturaRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadBoundedContextRoutes();
        $this->loadBoundedContextMigrations();
    }

    /**
     * Cargar las rutas de cada bounded context
     */
    protected function loadBoundedContextRoutes(): void
    {
        $boundedContexts = [
            'Auth',
            'Cliente',
            'Categoria',
            'Producto',
            'Factura',
        ];

        foreach ($boundedContexts as $context) {
            // Cargar rutas de API
            $apiRoutesPath = base_path("src/{$context}/api.php");
            if (file_exists($apiRoutesPath)) {
                Route::prefix('api/v1')
                    ->middleware('api')
                    ->group($apiRoutesPath);
            }

            // Cargar rutas Web
            $webRoutesPath = base_path("src/{$context}/web.php");
            if (file_exists($webRoutesPath)) {
                Route::middleware('web')
                    ->group($webRoutesPath);
            }
        }
    }

    /**
     * Cargar las migraciones de cada bounded context
     */
    protected function loadBoundedContextMigrations(): void
    {
        $boundedContexts = [
            'Auth',
            'Cliente',
            'Categoria',
            'Producto',
            'Factura',
        ];

        foreach ($boundedContexts as $context) {
            $migrationsPath = base_path("src/{$context}/Infrastructure/Migrations");

            if (is_dir($migrationsPath)) {
                $this->loadMigrationsFrom($migrationsPath);
            }
        }
    }
}
