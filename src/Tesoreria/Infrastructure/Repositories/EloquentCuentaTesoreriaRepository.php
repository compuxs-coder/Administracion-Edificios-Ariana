<?php

namespace Src\Tesoreria\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Tesoreria\Domain\Contracts\CuentaTesoreriaRepositoryInterface;
use Src\Tesoreria\Domain\Enums\EstadoCuentaTesoreria;
use Src\Tesoreria\Domain\Enums\EstadoMovimientoTesoreria;
use Src\Tesoreria\Domain\Enums\NaturalezaMovimientoTesoreria;
use Src\Tesoreria\Domain\Enums\TipoCuentaTesoreria;
use Src\Tesoreria\Infrastructure\Models\CuentaTesoreriaEloquentModel;

final class EloquentCuentaTesoreriaRepository implements CuentaTesoreriaRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $query = $this->accountQuery()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::TESORERIA_VER))
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['tipo'] ?? null, static fn (Builder $query, string $type) => $query->where('tipo', $type))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $state) => $query->where('estado', $state))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $query) => $query
                    ->whereRaw('LOWER(codigo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(entidad_financiera, ?)) LIKE ?', ['', $term]));
            })
            ->orderBy('edificio_id')
            ->orderBy('codigo');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (CuentaTesoreriaEloquentModel $account): array => $this->serialize($account))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $cuentaId): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::TESORERIA_VER);
        $account = $this->accountQuery()
            ->where('edificio_id', $edificioId)
            ->findOrFail($cuentaId);

        return $this->serialize($account);
    }

    public function getForManagement(string $userId, string $edificioId, string $cuentaId): array
    {
        $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR);
        $account = $this->accountQuery()
            ->where('edificio_id', $edificioId)
            ->findOrFail($cuentaId);

        return $this->serialize($account, true);
    }

    public function options(string $userId, PermisoEdificio $permission): array
    {
        $buildings = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return ['edificios' => $buildings->map(static fn (EdificioEloquentModel $building): array => [
            'id' => $building->id,
            'nombre' => $building->nombre,
        ])->all()];
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR, true);
            $account = new CuentaTesoreriaEloquentModel();
            $accountData = $this->accountData($data);
            $this->assertUnique($edificioId, $accountData);
            try {
                $account->fill([
                    'edificio_id' => $edificioId,
                    ...$accountData,
                    'estado' => EstadoCuentaTesoreria::ACTIVA,
                    'registrado_por' => $userId,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'default' => 'El código o número de cuenta ya está registrado en el edificio.',
                ]);
            }

            return ['id' => $account->id];
        });
    }

    public function update(string $userId, string $edificioId, string $cuentaId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $cuentaId, $data): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR, true);
            $account = CuentaTesoreriaEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($cuentaId);
            $newData = $this->accountData($data);
            $this->assertUnique($edificioId, $newData, $account->id);
            if ($account->movimientos()->exists() && $this->identityChanged($account, $newData)) {
                throw ValidationException::withMessages([
                    'cuenta' => 'La identidad y el tipo de una cuenta con movimientos son inmutables.',
                ]);
            }
            try {
                $account->fill($newData)->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'default' => 'El código o número de cuenta ya está registrado en el edificio.',
                ]);
            }
        });
    }

    public function changeStatus(
        string $userId,
        string $edificioId,
        string $cuentaId,
        EstadoCuentaTesoreria $estado,
    ): void {
        DB::transaction(function () use ($userId, $edificioId, $cuentaId, $estado): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR, true);
            $account = CuentaTesoreriaEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($cuentaId);
            $account->estado = $estado;
            $account->save();
        });
    }

    private function accountQuery(): Builder
    {
        return CuentaTesoreriaEloquentModel::query()
            ->with('edificio')
            ->withCount('movimientos')
            ->withSum(['movimientos as total_ingresos' => static fn (Builder $query) => $query
                ->where('estado', EstadoMovimientoTesoreria::REGISTRADO->value)
                ->where('naturaleza', NaturalezaMovimientoTesoreria::INGRESO->value)], 'monto')
            ->withSum(['movimientos as total_egresos' => static fn (Builder $query) => $query
                ->where('estado', EstadoMovimientoTesoreria::REGISTRADO->value)
                ->where('naturaleza', NaturalezaMovimientoTesoreria::EGRESO->value)], 'monto');
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

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function accountData(array $data): array
    {
        $bank = ($data['tipo'] ?? null) === TipoCuentaTesoreria::BANCARIA->value;

        return [
            'codigo' => mb_strtoupper(trim((string) ($data['codigo'] ?? ''))),
            'nombre' => trim((string) ($data['nombre'] ?? '')),
            'tipo' => $data['tipo'] ?? null,
            'entidad_financiera' => $bank ? $this->nullableTrim($data['entidad_financiera'] ?? null) : null,
            'tipo_cuenta_bancaria' => $bank ? $this->nullableTrim($data['tipo_cuenta_bancaria'] ?? null) : null,
            'numero_cuenta' => $bank ? $this->nullableTrim($data['numero_cuenta'] ?? null) : null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function identityChanged(CuentaTesoreriaEloquentModel $account, array $data): bool
    {
        return $account->tipo->value !== $data['tipo']
            || $account->entidad_financiera !== $data['entidad_financiera']
            || $account->tipo_cuenta_bancaria !== $data['tipo_cuenta_bancaria']
            || $account->numero_cuenta !== $data['numero_cuenta'];
    }

    /** @return array<string, mixed> */
    private function serialize(CuentaTesoreriaEloquentModel $account, bool $includeSensitive = false): array
    {
        $income = $this->money($account->getAttribute('total_ingresos'));
        $expenses = $this->money($account->getAttribute('total_egresos'));

        return [
            'id' => $account->id,
            'edificioId' => $account->edificio_id,
            'edificio' => $account->edificio?->nombre,
            'codigo' => $account->codigo,
            'nombre' => $account->nombre,
            'tipo' => $account->tipo->value,
            'entidadFinanciera' => $account->entidad_financiera,
            'tipoCuentaBancaria' => $account->tipo_cuenta_bancaria,
            'numeroCuenta' => $includeSensitive ? $account->numero_cuenta : null,
            'numeroCuentaMascara' => $this->maskedNumber($account->numero_cuenta),
            'estado' => $account->estado->value,
            'saldoRegistrado' => bcsub($income, $expenses, 4),
            'totalIngresos' => $income,
            'totalEgresos' => $expenses,
            'movimientosCount' => (int) ($account->getAttribute('movimientos_count') ?? 0),
            'createdAt' => $account->created_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertUnique(string $buildingId, array $data, ?string $exceptId = null): void
    {
        $codeExists = CuentaTesoreriaEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->where('codigo', $data['codigo'])
            ->when($exceptId, static fn (Builder $query, string $id) => $query->whereKeyNot($id))
            ->exists();
        if ($codeExists) {
            throw ValidationException::withMessages(['codigo' => 'El código ya está registrado en el edificio.']);
        }

        if ($data['numero_cuenta'] === null) {
            return;
        }
        $numberExists = CuentaTesoreriaEloquentModel::query()
            ->where('edificio_id', $buildingId)
            ->where('numero_cuenta', $data['numero_cuenta'])
            ->when($exceptId, static fn (Builder $query, string $id) => $query->whereKeyNot($id))
            ->exists();
        if ($numberExists) {
            throw ValidationException::withMessages(['numero_cuenta' => 'El número de cuenta ya está registrado en el edificio.']);
        }
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 4);
    }

    private function maskedNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        return '****'.mb_substr($number, -4);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
