<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Src\Auth\Infrastructure\Repositories\EloquentUserRepository;
use Src\Edificio\Application\Policies\EdificioPolicy;
use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;
use Src\Edificio\Domain\Contracts\EdificioRepositoryInterface;
use Src\Edificio\Domain\Contracts\EstructuraRepositoryInterface;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Repositories\EloquentDepartamentoRepository;
use Src\Edificio\Infrastructure\Repositories\EloquentEdificioRepository;
use Src\Edificio\Infrastructure\Repositories\EloquentEstructuraRepository;
use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Infrastructure\Repositories\EloquentFacturaRepository;

class BoundedContextServiceProvider extends ServiceProvider
{
    private const BOUNDED_CONTEXTS = [
        'Auth',
        'Edificio',
        'Cliente',
        'Categoria',
        'Producto',
        'Factura',
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(EdificioRepositoryInterface::class, EloquentEdificioRepository::class);
        $this->app->bind(EstructuraRepositoryInterface::class, EloquentEstructuraRepository::class);
        $this->app->bind(DepartamentoRepositoryInterface::class, EloquentDepartamentoRepository::class);
        $this->app->bind(FacturaRepositoryInterface::class, EloquentFacturaRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::policy(EdificioEloquentModel::class, EdificioPolicy::class);

        $this->loadBoundedContextRoutes();
        $this->loadBoundedContextMigrations();
    }

    /**
     * Cargar las rutas de cada bounded context
     */
    protected function loadBoundedContextRoutes(): void
    {
        foreach (self::BOUNDED_CONTEXTS as $context) {
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
        foreach (self::BOUNDED_CONTEXTS as $context) {
            $migrationsPath = base_path("src/{$context}/Infrastructure/Migrations");

            if (is_dir($migrationsPath)) {
                $this->loadMigrationsFrom($migrationsPath);
            }
        }
    }
}
