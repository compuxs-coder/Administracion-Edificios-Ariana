<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Src\Auth\Infrastructure\Repositories\EloquentUserRepository;
use Src\Edificio\Application\Policies\EdificioPolicy;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;
use Src\Edificio\Domain\Contracts\EdificioRepositoryInterface;
use Src\Edificio\Domain\Contracts\EstructuraRepositoryInterface;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Repositories\EloquentAccesoEdificioRepository;
use Src\Edificio\Infrastructure\Repositories\EloquentDepartamentoRepository;
use Src\Edificio\Infrastructure\Repositories\EloquentEdificioRepository;
use Src\Edificio\Infrastructure\Repositories\EloquentEstructuraRepository;
use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Infrastructure\Repositories\EloquentFacturaRepository;
use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;
use Src\Finanzas\Domain\Contracts\CargoRepositoryInterface;
use Src\Finanzas\Domain\Contracts\CarteraReadRepositoryInterface;
use Src\Finanzas\Domain\Contracts\LecturaConsumoRepositoryInterface;
use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;
use Src\Finanzas\Domain\Contracts\ReciboPagoRepositoryInterface;
use Src\Finanzas\Domain\Contracts\TarifaConceptoRepositoryInterface;
use Src\Finanzas\Infrastructure\Repositories\EloquentConceptoCobroRepository;
use Src\Finanzas\Infrastructure\Repositories\EloquentCargoRepository;
use Src\Finanzas\Infrastructure\Repositories\EloquentCarteraReadRepository;
use Src\Finanzas\Infrastructure\Repositories\EloquentLecturaConsumoRepository;
use Src\Finanzas\Infrastructure\Repositories\EloquentPagoRepository;
use Src\Finanzas\Infrastructure\Repositories\EloquentReciboPagoRepository;
use Src\Finanzas\Infrastructure\Repositories\EloquentTarifaConceptoRepository;
use Src\Gastos\Domain\Contracts\ContratoProveedorRepositoryInterface;
use Src\Gastos\Domain\Contracts\CuentaPorPagarRepositoryInterface;
use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;
use Src\Gastos\Domain\Contracts\ProveedorRepositoryInterface;
use Src\Gastos\Infrastructure\Repositories\EloquentContratoProveedorRepository;
use Src\Gastos\Infrastructure\Repositories\EloquentCuentaPorPagarRepository;
use Src\Gastos\Infrastructure\Repositories\EloquentGastoRepository;
use Src\Gastos\Infrastructure\Repositories\EloquentProveedorRepository;
use Src\Propiedad\Application\Policies\PropietarioPolicy;
use Src\Propiedad\Application\Policies\ResidentePolicy;
use Src\Propiedad\Domain\Contracts\OcupacionRepositoryInterface;
use Src\Propiedad\Domain\Contracts\PropietarioRepositoryInterface;
use Src\Propiedad\Domain\Contracts\ResidenteRepositoryInterface;
use Src\Propiedad\Domain\Contracts\TitularidadRepositoryInterface;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Repositories\EloquentOcupacionRepository;
use Src\Propiedad\Infrastructure\Repositories\EloquentPropietarioRepository;
use Src\Propiedad\Infrastructure\Repositories\EloquentResidenteRepository;
use Src\Propiedad\Infrastructure\Repositories\EloquentTitularidadRepository;

class BoundedContextServiceProvider extends ServiceProvider
{
    private const BOUNDED_CONTEXTS = [
        'Auth',
        'Edificio',
        'Propiedad',
        'Finanzas',
        'Gastos',
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
        $this->app->bind(AccesoEdificioRepositoryInterface::class, EloquentAccesoEdificioRepository::class);
        $this->app->bind(EdificioRepositoryInterface::class, EloquentEdificioRepository::class);
        $this->app->bind(EstructuraRepositoryInterface::class, EloquentEstructuraRepository::class);
        $this->app->bind(DepartamentoRepositoryInterface::class, EloquentDepartamentoRepository::class);
        $this->app->bind(PropietarioRepositoryInterface::class, EloquentPropietarioRepository::class);
        $this->app->bind(TitularidadRepositoryInterface::class, EloquentTitularidadRepository::class);
        $this->app->bind(ResidenteRepositoryInterface::class, EloquentResidenteRepository::class);
        $this->app->bind(OcupacionRepositoryInterface::class, EloquentOcupacionRepository::class);
        $this->app->bind(ConceptoCobroRepositoryInterface::class, EloquentConceptoCobroRepository::class);
        $this->app->bind(CargoRepositoryInterface::class, EloquentCargoRepository::class);
        $this->app->bind(CarteraReadRepositoryInterface::class, EloquentCarteraReadRepository::class);
        $this->app->bind(LecturaConsumoRepositoryInterface::class, EloquentLecturaConsumoRepository::class);
        $this->app->bind(PagoRepositoryInterface::class, EloquentPagoRepository::class);
        $this->app->bind(ReciboPagoRepositoryInterface::class, EloquentReciboPagoRepository::class);
        $this->app->bind(TarifaConceptoRepositoryInterface::class, EloquentTarifaConceptoRepository::class);
        $this->app->bind(ProveedorRepositoryInterface::class, EloquentProveedorRepository::class);
        $this->app->bind(ContratoProveedorRepositoryInterface::class, EloquentContratoProveedorRepository::class);
        $this->app->bind(GastoRepositoryInterface::class, EloquentGastoRepository::class);
        $this->app->bind(CuentaPorPagarRepositoryInterface::class, EloquentCuentaPorPagarRepository::class);
        $this->app->bind(FacturaRepositoryInterface::class, EloquentFacturaRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::policy(EdificioEloquentModel::class, EdificioPolicy::class);
        Gate::policy(PropietarioEloquentModel::class, PropietarioPolicy::class);
        Gate::policy(ResidenteEloquentModel::class, ResidentePolicy::class);

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
