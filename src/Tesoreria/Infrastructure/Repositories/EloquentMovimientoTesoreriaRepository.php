<?php

namespace Src\Tesoreria\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Enums\EstadoDesembolso;
use Src\Gastos\Domain\Enums\FormaDesembolso;
use Src\Gastos\Infrastructure\Models\DesembolsoEloquentModel;
use Src\Tesoreria\Domain\Contracts\MovimientoTesoreriaRepositoryInterface;
use Src\Tesoreria\Domain\Enums\EstadoConciliacionTesoreria;
use Src\Tesoreria\Domain\Enums\EstadoCuentaTesoreria;
use Src\Tesoreria\Domain\Enums\EstadoEfectivoMovimientoTesoreria;
use Src\Tesoreria\Domain\Enums\EstadoMovimientoTesoreria;
use Src\Tesoreria\Domain\Enums\NaturalezaMovimientoTesoreria;
use Src\Tesoreria\Domain\Enums\TipoCuentaTesoreria;
use Src\Tesoreria\Infrastructure\Models\ConciliacionTesoreriaEloquentModel;
use Src\Tesoreria\Infrastructure\Models\CuentaTesoreriaEloquentModel;
use Src\Tesoreria\Infrastructure\Models\MovimientoTesoreriaEloquentModel;

final class EloquentMovimientoTesoreriaRepository implements MovimientoTesoreriaRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $query = $this->movementQuery()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::TESORERIA_VER))
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['cuenta_id'] ?? null, static fn (Builder $query, string $id) => $query->where('cuenta_id', $id))
            ->when($filters['naturaleza'] ?? null, static fn (Builder $query, string $nature) => $query->where('naturaleza', $nature))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $state) => $query->where('estado', $state))
            ->when($filters['fecha_desde'] ?? null, static fn (Builder $query, string $date) => $query->whereDate('fecha_movimiento', '>=', $date))
            ->when($filters['fecha_hasta'] ?? null, static fn (Builder $query, string $date) => $query->whereDate('fecha_movimiento', '<=', $date));
        $this->applyEffectiveStateFilter($query, $filters['estado_conciliacion'] ?? null);
        $paginator = $query
            ->orderByDesc('fecha_movimiento')
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (MovimientoTesoreriaEloquentModel $movement): array => $this->serialize($movement))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $cuentaId, string $movimientoId): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::TESORERIA_VER);
        $movement = $this->movementQuery(true)
            ->where('edificio_id', $edificioId)
            ->where('cuenta_id', $cuentaId)
            ->findOrFail($movimientoId);

        return $this->serialize($movement, true);
    }

    public function options(string $userId, PermisoEdificio $permission, bool $onlyActiveAccounts = false): array
    {
        $buildings = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
        $accounts = CuentaTesoreriaEloquentModel::query()
            ->whereIn('edificio_id', $buildings->pluck('id')->all())
            ->when($onlyActiveAccounts, static fn (Builder $query) => $query->where('estado', EstadoCuentaTesoreria::ACTIVA->value))
            ->orderBy('edificio_id')
            ->orderBy('codigo')
            ->get(['id', 'edificio_id', 'codigo', 'nombre', 'tipo', 'estado']);

        return [
            'edificios' => $buildings->map(static fn (EdificioEloquentModel $building): array => [
                'id' => $building->id,
                'nombre' => $building->nombre,
            ])->all(),
            'cuentas' => $accounts->map(static fn (CuentaTesoreriaEloquentModel $account): array => [
                'id' => $account->id,
                'edificioId' => $account->edificio_id,
                'codigo' => $account->codigo,
                'nombre' => $account->nombre,
                'tipo' => $account->tipo->value,
                'estado' => $account->estado->value,
            ])->all(),
        ];
    }

    public function create(string $userId, string $edificioId, string $cuentaId, array $data): array
    {
        $date = $this->date($data['fecha_movimiento'] ?? null);
        if ($date->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['fecha_movimiento' => 'La fecha del movimiento no puede ser futura.']);
        }
        $amount = $this->money($data['monto'] ?? null);

        return DB::transaction(function () use ($userId, $edificioId, $cuentaId, $data, $date, $amount): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::MOVIMIENTOS_TESORERIA_REGISTRAR, true);
            $account = $this->lockedAccount($edificioId, $cuentaId);
            if ($account->estado !== EstadoCuentaTesoreria::ACTIVA) {
                throw ValidationException::withMessages(['cuenta' => 'La cuenta está inactiva y no admite movimientos nuevos.']);
            }
            $movement = new MovimientoTesoreriaEloquentModel();
            $movement->fill([
                'edificio_id' => $edificioId,
                'cuenta_id' => $account->id,
                'fecha_movimiento' => $date,
                'naturaleza' => $data['naturaleza'],
                'monto' => $amount,
                'referencia' => $this->nullableTrim($data['referencia'] ?? null),
                'descripcion' => trim((string) ($data['descripcion'] ?? '')),
                'estado' => EstadoMovimientoTesoreria::REGISTRADO,
                'registrado_por' => $userId,
            ])->save();

            return ['id' => $movement->id];
        });
    }

    public function cancel(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
        string $reason,
    ): void {
        DB::transaction(function () use ($userId, $edificioId, $cuentaId, $movimientoId, $reason): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::MOVIMIENTOS_TESORERIA_ANULAR, true);
            $this->lockedAccount($edificioId, $cuentaId);
            $movement = $this->lockedMovement($edificioId, $cuentaId, $movimientoId);
            if ($movement->estado !== EstadoMovimientoTesoreria::REGISTRADO) {
                throw ValidationException::withMessages(['estado' => 'El movimiento ya fue anulado.']);
            }
            if (ConciliacionTesoreriaEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('cuenta_id', $cuentaId)
                ->where('movimiento_id', $movement->id)
                ->where('estado', EstadoConciliacionTesoreria::VIGENTE->value)
                ->lockForUpdate()
                ->first(['id']) !== null) {
                throw ValidationException::withMessages([
                    'conciliacion' => 'Revierta la conciliación vigente antes de anular el movimiento.',
                ]);
            }
            $movement->fill([
                'estado' => EstadoMovimientoTesoreria::ANULADO,
                'anulado_por' => $userId,
                'anulado_at' => CarbonImmutable::now(),
                'motivo_anulacion' => trim($reason),
            ])->save();
        });
    }

    public function reconciliationOptions(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
    ): array {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::CONCILIACIONES_GESTIONAR);
        $movement = $this->movementQuery(true)
            ->where('edificio_id', $edificioId)
            ->where('cuenta_id', $cuentaId)
            ->findOrFail($movimientoId);
        $this->assertReconcilable($movement, $movement->cuenta);
        if ($movement->conciliacionVigente !== null) {
            throw ValidationException::withMessages(['conciliacion' => 'El movimiento ya tiene una conciliación vigente.']);
        }
        $payments = DesembolsoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('estado', EstadoDesembolso::REGISTRADO->value)
            ->where('monto', $movement->monto)
            ->when(
                $movement->cuenta->tipo === TipoCuentaTesoreria::CAJA,
                static fn (Builder $query) => $query->where('forma_pago', FormaDesembolso::EFECTIVO->value),
                static fn (Builder $query) => $query->where('forma_pago', '<>', FormaDesembolso::EFECTIVO->value),
            )
            ->whereNotIn('id', ConciliacionTesoreriaEloquentModel::query()
                ->select('desembolso_id')
                ->where('estado', EstadoConciliacionTesoreria::VIGENTE->value))
            ->orderByDesc('fecha_desembolso')
            ->orderByDesc('numero')
            ->get();

        return [
            'movimiento' => $this->serialize($movement, true),
            'desembolsos' => $payments->map(fn (DesembolsoEloquentModel $payment): array => $this->serializeCandidate($payment))->all(),
        ];
    }

    public function reconcile(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
        array $data,
    ): array {
        return DB::transaction(function () use ($userId, $edificioId, $cuentaId, $movimientoId, $data): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::CONCILIACIONES_GESTIONAR, true);
            $account = $this->lockedAccount($edificioId, $cuentaId);
            $movement = $this->lockedMovement($edificioId, $cuentaId, $movimientoId);
            $this->assertReconcilable($movement, $account);
            if (ConciliacionTesoreriaEloquentModel::query()
                ->where('movimiento_id', $movement->id)
                ->where('estado', EstadoConciliacionTesoreria::VIGENTE->value)
                ->lockForUpdate()
                ->first(['id']) !== null) {
                throw ValidationException::withMessages(['conciliacion' => 'El movimiento ya tiene una conciliación vigente.']);
            }
            $payment = DesembolsoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoDesembolso::REGISTRADO->value)
                ->lockForUpdate()
                ->find($data['desembolso_id'] ?? null);
            if ($payment === null) {
                throw ValidationException::withMessages(['desembolso_id' => 'El desembolso no está registrado en el edificio.']);
            }
            if (ConciliacionTesoreriaEloquentModel::query()
                ->where('desembolso_id', $payment->id)
                ->where('estado', EstadoConciliacionTesoreria::VIGENTE->value)
                ->lockForUpdate()
                ->first(['id']) !== null) {
                throw ValidationException::withMessages(['desembolso_id' => 'El desembolso ya tiene una conciliación vigente.']);
            }
            if (bccomp($movement->monto, $payment->monto, 4) !== 0) {
                throw ValidationException::withMessages(['desembolso_id' => 'El desembolso debe tener exactamente el monto del movimiento.']);
            }
            $this->assertPaymentCompatibility($account, $payment);

            $reconciliation = new ConciliacionTesoreriaEloquentModel();
            try {
                $reconciliation->fill([
                    'edificio_id' => $edificioId,
                    'cuenta_id' => $account->id,
                    'movimiento_id' => $movement->id,
                    'desembolso_id' => $payment->id,
                    'estado' => EstadoConciliacionTesoreria::VIGENTE,
                    'conciliado_por' => $userId,
                    'conciliado_at' => CarbonImmutable::now(),
                    'nota' => $this->nullableTrim($data['nota'] ?? null),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'conciliacion' => 'El movimiento o desembolso ya tiene una conciliación vigente.',
                ]);
            }

            return ['id' => $reconciliation->id];
        });
    }

    public function reverse(
        string $userId,
        string $edificioId,
        string $cuentaId,
        string $movimientoId,
        string $conciliacionId,
        string $reason,
    ): void {
        DB::transaction(function () use ($userId, $edificioId, $cuentaId, $movimientoId, $conciliacionId, $reason): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::CONCILIACIONES_GESTIONAR, true);
            $this->lockedAccount($edificioId, $cuentaId);
            $this->lockedMovement($edificioId, $cuentaId, $movimientoId);
            $reconciliation = ConciliacionTesoreriaEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('cuenta_id', $cuentaId)
                ->where('movimiento_id', $movimientoId)
                ->lockForUpdate()
                ->findOrFail($conciliacionId);
            if ($reconciliation->estado !== EstadoConciliacionTesoreria::VIGENTE) {
                throw ValidationException::withMessages(['estado' => 'La conciliación ya fue revertida.']);
            }
            $reconciliation->fill([
                'estado' => EstadoConciliacionTesoreria::REVERTIDA,
                'revertido_por' => $userId,
                'revertido_at' => CarbonImmutable::now(),
                'motivo_reversion' => trim($reason),
            ])->save();
        });
    }

    private function movementQuery(bool $withHistory = false): Builder
    {
        $relations = [
            'edificio', 'cuenta', 'registradoPor', 'anuladoPor',
            'conciliacionVigente.desembolso', 'conciliacionVigente.conciliadoPor',
        ];
        if ($withHistory) {
            $relations = [
                ...$relations,
                'conciliaciones.desembolso', 'conciliaciones.conciliadoPor', 'conciliaciones.revertidoPor',
            ];
        }

        return MovimientoTesoreriaEloquentModel::query()->with($relations);
    }

    private function applyEffectiveStateFilter(Builder $query, ?string $state): void
    {
        match ($state) {
            EstadoEfectivoMovimientoTesoreria::ANULADO->value => $query->where('estado', EstadoMovimientoTesoreria::ANULADO->value),
            EstadoEfectivoMovimientoTesoreria::NO_APLICA->value => $query
                ->where('estado', EstadoMovimientoTesoreria::REGISTRADO->value)
                ->where('naturaleza', NaturalezaMovimientoTesoreria::INGRESO->value),
            EstadoEfectivoMovimientoTesoreria::CONCILIADO->value => $query
                ->where('estado', EstadoMovimientoTesoreria::REGISTRADO->value)
                ->where('naturaleza', NaturalezaMovimientoTesoreria::EGRESO->value)
                ->whereHas('conciliacionVigente'),
            EstadoEfectivoMovimientoTesoreria::PENDIENTE->value => $query
                ->where('estado', EstadoMovimientoTesoreria::REGISTRADO->value)
                ->where('naturaleza', NaturalezaMovimientoTesoreria::EGRESO->value)
                ->whereDoesntHave('conciliacionVigente'),
            default => null,
        };
    }

    private function authorizedBuilding(
        string $userId,
        string $buildingId,
        PermisoEdificio $permission,
        bool $lock = false,
    ): EdificioEloquentModel {
        if ($lock) {
            $building = EdificioEloquentModel::query()->lockForUpdate()->findOrFail($buildingId);
            abort_unless($this->access->hasPermission($userId, $buildingId, $permission), 404);

            return $building;
        }

        return EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->findOrFail($buildingId);
    }

    private function lockedAccount(string $buildingId, string $accountId): CuentaTesoreriaEloquentModel
    {
        return CuentaTesoreriaEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->lockForUpdate()
            ->findOrFail($accountId);
    }

    private function lockedMovement(string $buildingId, string $accountId, string $movementId): MovimientoTesoreriaEloquentModel
    {
        return MovimientoTesoreriaEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->where('cuenta_id', $accountId)
            ->lockForUpdate()
            ->findOrFail($movementId);
    }

    private function assertReconcilable(
        MovimientoTesoreriaEloquentModel $movement,
        CuentaTesoreriaEloquentModel $account,
    ): void {
        if ($movement->estado !== EstadoMovimientoTesoreria::REGISTRADO) {
            throw ValidationException::withMessages(['movimiento' => 'Sólo puede conciliarse un movimiento registrado.']);
        }
        if ($movement->naturaleza !== NaturalezaMovimientoTesoreria::EGRESO) {
            throw ValidationException::withMessages(['movimiento' => 'Sólo los egresos pueden conciliarse con desembolsos.']);
        }
        if ($movement->cuenta_id !== $account->id || $movement->edificio_id !== $account->edificio_id) {
            abort(404);
        }
    }

    private function assertPaymentCompatibility(
        CuentaTesoreriaEloquentModel $account,
        DesembolsoEloquentModel $payment,
    ): void {
        $cashPayment = $payment->forma_pago === FormaDesembolso::EFECTIVO;
        if (($account->tipo === TipoCuentaTesoreria::CAJA && ! $cashPayment)
            || ($account->tipo === TipoCuentaTesoreria::BANCARIA && $cashPayment)) {
            throw ValidationException::withMessages([
                'desembolso_id' => 'La forma de pago del desembolso no es compatible con el tipo de cuenta.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(MovimientoTesoreriaEloquentModel $movement, bool $detail = false): array
    {
        $active = $movement->conciliacionVigente;
        $effectiveState = match (true) {
            $movement->estado === EstadoMovimientoTesoreria::ANULADO => EstadoEfectivoMovimientoTesoreria::ANULADO,
            $movement->naturaleza === NaturalezaMovimientoTesoreria::INGRESO => EstadoEfectivoMovimientoTesoreria::NO_APLICA,
            $active !== null => EstadoEfectivoMovimientoTesoreria::CONCILIADO,
            default => EstadoEfectivoMovimientoTesoreria::PENDIENTE,
        };
        $data = [
            'id' => $movement->id,
            'edificioId' => $movement->edificio_id,
            'edificio' => $movement->edificio?->nombre,
            'cuentaId' => $movement->cuenta_id,
            'cuenta' => $movement->cuenta?->nombre,
            'cuentaCodigo' => $movement->cuenta?->codigo,
            'cuentaTipo' => $movement->cuenta?->tipo->value,
            'fechaMovimiento' => $movement->fecha_movimiento->format('Y-m-d'),
            'naturaleza' => $movement->naturaleza->value,
            'monto' => $movement->monto,
            'referencia' => $movement->referencia,
            'descripcion' => $movement->descripcion,
            'estado' => $movement->estado->value,
            'estadoConciliacion' => $effectiveState->value,
            'registradoPorId' => $movement->registrado_por,
            'registradoPor' => $movement->registradoPor?->name,
            'registradoAt' => $movement->created_at?->toIso8601String(),
            'anuladoPorId' => $movement->anulado_por,
            'anuladoPor' => $movement->anuladoPor?->name,
            'anuladoAt' => $movement->anulado_at?->toIso8601String(),
            'motivoAnulacion' => $movement->motivo_anulacion,
            'conciliacion' => $active === null ? null : $this->serializeReconciliation($active),
            'createdAt' => $movement->created_at?->toIso8601String(),
        ];
        if ($detail) {
            $data['historialConciliaciones'] = $movement->conciliaciones
                ->sortByDesc(static fn (ConciliacionTesoreriaEloquentModel $reconciliation): string => $reconciliation->conciliado_at->toIso8601String())
                ->values()
                ->map(fn (ConciliacionTesoreriaEloquentModel $reconciliation): array => $this->serializeReconciliation($reconciliation))
                ->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeReconciliation(ConciliacionTesoreriaEloquentModel $reconciliation): array
    {
        return [
            'id' => $reconciliation->id,
            'estado' => $reconciliation->estado->value,
            'conciliadoPorId' => $reconciliation->conciliado_por,
            'conciliadoPor' => $reconciliation->conciliadoPor?->name,
            'conciliadoAt' => $reconciliation->conciliado_at?->toIso8601String(),
            'nota' => $reconciliation->nota,
            'revertidoPorId' => $reconciliation->revertido_por,
            'revertidoPor' => $reconciliation->revertidoPor?->name,
            'revertidoAt' => $reconciliation->revertido_at?->toIso8601String(),
            'motivoReversion' => $reconciliation->motivo_reversion,
            'desembolso' => $reconciliation->desembolso === null
                ? null
                : $this->serializeCandidate($reconciliation->desembolso),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeCandidate(DesembolsoEloquentModel $payment): array
    {
        return [
            'id' => $payment->id,
            'numero' => $payment->numero,
            'fechaDesembolso' => $payment->fecha_desembolso->format('Y-m-d'),
            'monto' => $payment->monto,
            'formaPago' => $payment->forma_pago->value,
            'referencia' => $payment->referencia,
            'proveedor' => $payment->proveedor_nombre_snapshot,
        ];
    }

    private function date(mixed $value): CarbonImmutable
    {
        $value = (string) $value;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['fecha_movimiento' => 'La fecha debe tener formato YYYY-MM-DD.']);
        }

        return $date;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || bccomp($value, '0', 4) <= 0) {
            throw ValidationException::withMessages(['monto' => 'El monto debe ser decimal positivo con hasta cuatro decimales.']);
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        if (strlen($integer) > 10) {
            throw ValidationException::withMessages(['monto' => 'El monto supera la precisión permitida.']);
        }

        return $integer.'.'.str_pad($fraction, 4, '0');
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
