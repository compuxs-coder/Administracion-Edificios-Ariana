<?php

namespace Src\Gastos\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Src\Gastos\Domain\Enums\EstadoCuentaPorPagar;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Gastos\Domain\Contracts\CuentaPorPagarRepositoryInterface;
use Src\Gastos\Infrastructure\Models\CuentaPorPagarEloquentModel;
use Src\Propiedad\Domain\Enums\TipoPersona;

final class EloquentCuentaPorPagarRepository implements CuentaPorPagarRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $query = CuentaPorPagarEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::GASTOS_VER))
            ->with(['gasto.edificio', 'proveedor.tercero'])
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['proveedor_id'] ?? null, static fn (Builder $query, string $id) => $query->where('proveedor_id', $id));
        $this->applyStateFilter($query, $filters['estado'] ?? null);
        $today = now()->toDateString();
        $pending = (clone $query)
            ->where('estado', EstadoCuentaPorPagar::PENDIENTE->value)
            ->where('saldo', '>', 0);
        $summary = [
            'totalPendiente' => bcadd((string) (clone $pending)->sum('saldo'), '0', 4),
            'totalVencido' => bcadd((string) (clone $pending)->whereDate('fecha_vencimiento', '<', $today)->sum('saldo'), '0', 4),
            'porVencer' => bcadd((string) (clone $pending)->whereDate('fecha_vencimiento', '>=', $today)->sum('saldo'), '0', 4),
            'cantidadPendiente' => (clone $pending)->count(),
        ];
        $query
            ->orderBy('fecha_vencimiento')
            ->orderBy('created_at');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (CuentaPorPagarEloquentModel $account): array => $this->serialize($account))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'summary' => $summary,
        ];
    }

    public function get(string $userId, string $edificioId, string $cuentaId): array
    {
        abort_unless(in_array($edificioId, $this->access->buildingIds($userId, PermisoEdificio::GASTOS_VER), true), 404);
        $account = CuentaPorPagarEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with(['gasto.edificio', 'proveedor.tercero'])
            ->findOrFail($cuentaId);

        return $this->serialize($account);
    }

    /** @return array<string, mixed> */
    private function serialize(CuentaPorPagarEloquentModel $account): array
    {
        $identity = $account->proveedor->tercero;

        return [
            'id' => $account->id,
            'edificioId' => $account->edificio_id,
            'edificio' => $account->gasto->edificio->nombre,
            'gastoId' => $account->gasto_id,
            'numeroGasto' => $account->gasto->numero,
            'proveedorId' => $account->proveedor_id,
            'proveedor' => $identity->tipo_persona === TipoPersona::PERSONA_JURIDICA
                ? $identity->razon_social
                : trim($identity->nombres.' '.$identity->apellidos),
            'fechaVencimiento' => $account->fecha_vencimiento->format('Y-m-d'),
            'montoOriginal' => $account->monto_original,
            'saldo' => $account->saldo,
            'estado' => $this->effectiveState($account),
            'anuladoAt' => $account->anulado_at?->toIso8601String(),
            'createdAt' => $account->created_at?->toIso8601String(),
        ];
    }

    private function applyStateFilter(Builder $query, ?string $state): void
    {
        match ($state) {
            'pendiente' => $query
                ->where('estado', EstadoCuentaPorPagar::PENDIENTE->value)
                ->whereColumn('saldo', 'monto_original'),
            'parcial' => $query
                ->where('estado', EstadoCuentaPorPagar::PENDIENTE->value)
                ->where('saldo', '>', 0)
                ->whereColumn('saldo', '<', 'monto_original'),
            'pagada' => $query
                ->where('estado', EstadoCuentaPorPagar::PENDIENTE->value)
                ->where('saldo', 0),
            'anulada' => $query->where('estado', EstadoCuentaPorPagar::ANULADA->value),
            default => null,
        };
    }

    private function effectiveState(CuentaPorPagarEloquentModel $account): string
    {
        if ($account->estado === EstadoCuentaPorPagar::ANULADA) {
            return 'anulada';
        }
        if (bccomp($account->saldo, '0.0000', 4) === 0) {
            return 'pagada';
        }

        return bccomp($account->saldo, $account->monto_original, 4) === 0
            ? 'pendiente'
            : 'parcial';
    }
}
