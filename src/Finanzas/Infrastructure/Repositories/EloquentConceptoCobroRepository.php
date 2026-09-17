<?php

namespace Src\Finanzas\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;
use Src\Finanzas\Domain\Enums\EstadoTarifa;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\TarifaConceptoEloquentModel;

final class EloquentConceptoCobroRepository implements ConceptoCobroRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function list(string $userId, array $filters): array
    {
        $today = CarbonImmutable::today()->format('Y-m-d');
        $query = ConceptoCobroEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::FINANZAS_VER))
            ->with([
                'edificio',
                'tarifas' => static fn ($query) => $query
                    ->whereDate('fecha_inicio', '<=', $today)
                    ->where(static fn (Builder $query) => $query
                        ->whereNull('fecha_fin')
                        ->orWhereDate('fecha_fin', '>', $today)),
            ])
            ->orderBy('codigo');

        if (($filters['buscar'] ?? null) !== null) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['buscar']).'%';
            $query->where(static fn (Builder $query) => $query
                ->where('codigo', 'like', $term)
                ->orWhere('nombre', 'like', $term));
        }
        foreach (['edificio_id', 'tipo', 'periodicidad', 'forma_calculo', 'estado'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, $filters[$field]);
            }
        }

        $paginator = $query->paginate(12, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()
                ->map(fn (ConceptoCobroEloquentModel $model): array => $this->serializeConcept($model, $model->tarifas->first()))
                ->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $conceptoId): array
    {
        $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::FINANZAS_VER);
        $concepto = ConceptoCobroEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with([
                'edificio',
                'tarifas' => static fn ($query) => $query
                    ->with(['departamentos' => static fn ($query) => $query->orderBy('codigo')])
                    ->orderByDesc('fecha_inicio'),
            ])
            ->findOrFail($conceptoId);
        $today = CarbonImmutable::today();
        $tarifas = $concepto->tarifas
            ->map(fn (TarifaConceptoEloquentModel $tarifa): array => $this->serializeTarifa($tarifa, $today))
            ->all();

        return [
            ...$this->serializeConcept($concepto, $this->currentTarifa($concepto->tarifas, $today)),
            'tarifas' => $tarifas,
            'departamentosDisponibles' => DepartamentoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', 'activo')
                ->orderBy('codigo')
                ->get()
                ->map(static fn (DepartamentoEloquentModel $departamento): array => [
                    'id' => $departamento->id,
                    'codigo' => $departamento->codigo,
                    'nombre' => $departamento->nombre,
                ])
                ->all(),
        ];
    }

    public function buildingOptions(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array
    {
        return EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(static fn (EdificioEloquentModel $edificio): array => [
                'id' => $edificio->id,
                'nombre' => $edificio->nombre,
            ])
            ->all();
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::CONCEPTOS_GESTIONAR, true);
            $concepto = new ConceptoCobroEloquentModel();
            $concepto->edificio_id = $edificioId;
            $concepto->fill($this->conceptData($data));

            try {
                $concepto->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'codigo' => 'El código ya está registrado en este edificio.',
                ]);
            }

            return ['id' => $concepto->id];
        });
    }

    public function update(string $userId, string $edificioId, string $conceptoId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $conceptoId, $data): void {
            $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::CONCEPTOS_GESTIONAR, true);
            $concepto = $this->lockedConcepto($edificioId, $conceptoId);
            $conceptData = $this->conceptData($data);
            $this->assertConfigurationCanChange($concepto, $conceptData);
            $concepto->fill($conceptData);

            try {
                $concepto->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'codigo' => 'El código ya está registrado en este edificio.',
                ]);
            }
        });
    }

    public function changeStatus(
        string $userId,
        string $edificioId,
        string $conceptoId,
        EstadoConceptoCobro $estado,
    ): void {
        DB::transaction(function () use ($userId, $edificioId, $conceptoId, $estado): void {
            $this->authorizedEdificio($userId, $edificioId, PermisoEdificio::CONCEPTOS_GESTIONAR, true);
            $this->lockedConcepto($edificioId, $conceptoId)
                ->fill(['estado' => $estado])
                ->save();
        });
    }

    private function authorizedEdificio(
        string $userId,
        string $edificioId,
        PermisoEdificio $permission,
        bool $lock = false,
    ): EdificioEloquentModel {
        $query = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($edificioId);
    }

    private function lockedConcepto(string $edificioId, string $conceptoId): ConceptoCobroEloquentModel
    {
        return ConceptoCobroEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->lockForUpdate()
            ->findOrFail($conceptoId);
    }

    /** @param array<string, mixed> $data */
    private function assertConfigurationCanChange(ConceptoCobroEloquentModel $concepto, array $data): void
    {
        $changed = [];
        foreach (['tipo', 'periodicidad', 'forma_calculo'] as $field) {
            if ($concepto->{$field}->value !== $data[$field]) {
                $changed[$field] = 'No se puede cambiar esta configuración después de registrar tarifas.';
            }
        }
        if ($changed === []) {
            return;
        }

        $hasTarifas = TarifaConceptoEloquentModel::query()
            ->where('edificio_id', $concepto->edificio_id)
            ->where('concepto_cobro_id', $concepto->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first() !== null;
        if ($hasTarifas) {
            throw ValidationException::withMessages($changed);
        }

        $cargoSensitiveChanges = array_intersect_key($changed, array_flip(['tipo', 'forma_calculo']));
        if ($cargoSensitiveChanges !== [] && CargoEloquentModel::query()
            ->where('edificio_id', $concepto->edificio_id)
            ->where('concepto_cobro_id', $concepto->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first() !== null) {
            throw ValidationException::withMessages(array_map(
                static fn (): string => 'No se puede cambiar esta configuración después de registrar cargos.',
                $cargoSensitiveChanges,
            ));
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function conceptData(array $data): array
    {
        return [
            'codigo' => Str::upper(trim((string) ($data['codigo'] ?? ''))),
            'nombre' => trim((string) ($data['nombre'] ?? '')),
            'descripcion' => $this->nullableTrim($data['descripcion'] ?? null),
            'tipo' => $data['tipo'] ?? null,
            'periodicidad' => $data['periodicidad'] ?? null,
            'forma_calculo' => $data['forma_calculo'] ?? null,
            'estado' => $data['estado'] ?? EstadoConceptoCobro::ACTIVO,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeConcept(
        ConceptoCobroEloquentModel $model,
        ?TarifaConceptoEloquentModel $tarifa,
    ): array {
        return [
            'id' => $model->id,
            'edificioId' => $model->edificio_id,
            'edificio' => $model->edificio?->nombre,
            'codigo' => $model->codigo,
            'nombre' => $model->nombre,
            'descripcion' => $model->descripcion,
            'tipo' => $model->tipo->value,
            'periodicidad' => $model->periodicidad->value,
            'formaCalculo' => $model->forma_calculo->value,
            'estado' => $model->estado->value,
            'tarifaVigente' => $tarifa === null ? null : $this->serializeTarifa($tarifa, CarbonImmutable::today()),
            'createdAt' => $model->created_at?->toISOString(),
            'updatedAt' => $model->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTarifa(TarifaConceptoEloquentModel $model, CarbonImmutable $today): array
    {
        return [
            'id' => $model->id,
            'valor' => $model->valor,
            'porcentaje' => $model->porcentaje,
            'montoTotal' => $model->monto_total,
            'numeroCuotas' => $model->numero_cuotas,
            'unidad' => $model->unidad,
            'baseCalculo' => $model->base_calculo?->value,
            'fechaInicio' => $model->fecha_inicio->format('Y-m-d'),
            'fechaFin' => $model->fecha_fin?->format('Y-m-d'),
            'alcance' => $model->alcance->value,
            'observacion' => $model->observacion,
            'estado' => $this->tarifaEstado($model, $today)->value,
            'departamentos' => $model->relationLoaded('departamentos')
                ? $model->departamentos->map(static fn (DepartamentoEloquentModel $departamento): array => [
                    'id' => $departamento->id,
                    'codigo' => $departamento->codigo,
                    'nombre' => $departamento->nombre,
                ])->all()
                : [],
        ];
    }

    /** @param iterable<TarifaConceptoEloquentModel> $tarifas */
    private function currentTarifa(iterable $tarifas, CarbonImmutable $today): ?TarifaConceptoEloquentModel
    {
        foreach ($tarifas as $tarifa) {
            if ($this->tarifaEstado($tarifa, $today) === EstadoTarifa::VIGENTE) {
                return $tarifa;
            }
        }

        return null;
    }

    private function tarifaEstado(TarifaConceptoEloquentModel $tarifa, CarbonImmutable $today): EstadoTarifa
    {
        if ($tarifa->fecha_inicio->greaterThan($today)) {
            return EstadoTarifa::PROGRAMADA;
        }

        if ($tarifa->fecha_fin !== null && $tarifa->fecha_fin->lessThanOrEqualTo($today)) {
            return EstadoTarifa::FINALIZADA;
        }

        return EstadoTarifa::VIGENTE;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
