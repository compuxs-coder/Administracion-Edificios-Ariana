<?php

namespace Src\Finanzas\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\EstadoEdificio;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Contracts\LecturaConsumoRepositoryInterface;
use Src\Finanzas\Domain\Enums\AlcanceTarifa;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;
use Src\Finanzas\Domain\Enums\FormaCalculoCobro;
use Src\Finanzas\Domain\Enums\TipoConceptoCobro;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\LecturaConsumoEloquentModel;
use Src\Finanzas\Infrastructure\Models\TarifaConceptoEloquentModel;

final class EloquentLecturaConsumoRepository implements LecturaConsumoRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function list(string $userId, array $filters): array
    {
        $query = LecturaConsumoEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::FINANZAS_VER))
            ->with(['edificio', 'departamento', 'concepto', 'registradoPor'])
            ->orderByDesc('periodo')
            ->orderBy('departamento_id')
            ->orderBy('concepto_cobro_id');

        foreach (['edificio_id', 'departamento_id', 'concepto_cobro_id'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['periodo'] ?? null) !== null) {
            $query->whereDate('periodo', $this->period($filters['periodo'])->format('Y-m-d'));
        }

        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()
                ->map(fn (LecturaConsumoEloquentModel $lectura): array => $this->serialize($lectura))
                ->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function options(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array
    {
        $forRegistration = $permission === PermisoEdificio::LECTURAS_REGISTRAR;
        $edificios = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, $permission))
            ->when($forRegistration, static fn (Builder $query) => $query
                ->where('estado', EstadoEdificio::ACTIVO->value))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
        $buildingIds = $edificios->pluck('id')->all();

        $ultimasLecturas = $permission === PermisoEdificio::LECTURAS_REGISTRAR
            ? LecturaConsumoEloquentModel::query()
                ->whereIn('edificio_id', $buildingIds)
                ->orderByDesc('periodo')
                ->orderByDesc('created_at')
                ->get()
                ->unique(static fn (LecturaConsumoEloquentModel $lectura): string => implode(':', [
                    $lectura->edificio_id,
                    $lectura->departamento_id,
                    $lectura->concepto_cobro_id,
                ]))
                ->values()
            : collect();

        return [
            'edificios' => $edificios->map(static fn (EdificioEloquentModel $edificio): array => [
                'id' => $edificio->id,
                'nombre' => $edificio->nombre,
            ])->all(),
            'departamentos' => DepartamentoEloquentModel::query()
                ->whereIn('edificio_id', $buildingIds)
                ->when($forRegistration, static fn (Builder $query) => $query
                    ->where('estado', EstadoEstructura::ACTIVO->value))
                ->orderBy('codigo')
                ->get()
                ->map(static fn (DepartamentoEloquentModel $departamento): array => [
                    'id' => $departamento->id,
                    'edificioId' => $departamento->edificio_id,
                    'codigo' => $departamento->codigo,
                    'nombre' => $departamento->nombre,
                ])->all(),
            'conceptos' => ConceptoCobroEloquentModel::query()
                ->whereIn('edificio_id', $buildingIds)
                ->when($forRegistration, static fn (Builder $query) => $query
                    ->where('estado', EstadoConceptoCobro::ACTIVO->value))
                ->where('tipo', TipoConceptoCobro::CONSUMO->value)
                ->where('forma_calculo', FormaCalculoCobro::POR_CONSUMO->value)
                ->orderBy('codigo')
                ->get()
                ->map(static fn (ConceptoCobroEloquentModel $concepto): array => [
                    'id' => $concepto->id,
                    'edificioId' => $concepto->edificio_id,
                    'codigo' => $concepto->codigo,
                    'nombre' => $concepto->nombre,
                ])->all(),
            'ultimasLecturas' => $ultimasLecturas->map(static fn (LecturaConsumoEloquentModel $lectura): array => [
                'edificioId' => $lectura->edificio_id,
                'departamentoId' => $lectura->departamento_id,
                'conceptoId' => $lectura->concepto_cobro_id,
                'periodo' => $lectura->periodo->format('Y-m'),
                'lecturaActual' => $lectura->lectura_actual,
                'unidad' => $lectura->unidad,
            ])->all(),
        ];
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedEdificio($userId, $edificioId);
            $departamento = DepartamentoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoEstructura::ACTIVO->value)
                ->lockForUpdate()
                ->find($data['departamento_id'] ?? null);
            if ($departamento === null) {
                throw ValidationException::withMessages([
                    'departamento_id' => 'El departamento no pertenece al edificio o está inactivo.',
                ]);
            }

            $concepto = ConceptoCobroEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoConceptoCobro::ACTIVO->value)
                ->where('tipo', TipoConceptoCobro::CONSUMO->value)
                ->where('forma_calculo', FormaCalculoCobro::POR_CONSUMO->value)
                ->lockForUpdate()
                ->find($data['concepto_cobro_id'] ?? null);
            if ($concepto === null) {
                throw ValidationException::withMessages([
                    'concepto_cobro_id' => 'El concepto no pertenece al edificio o no admite lecturas de consumo.',
                ]);
            }

            $periodo = $this->period($data['periodo'] ?? null);
            $fechaLectura = $this->date($data['fecha_lectura'] ?? null, 'fecha_lectura');
            if (! $fechaLectura->isSameMonth($periodo)) {
                throw ValidationException::withMessages([
                    'fecha_lectura' => 'La fecha de lectura debe pertenecer al período seleccionado.',
                ]);
            }

            $ultima = LecturaConsumoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('departamento_id', $departamento->id)
                ->where('concepto_cobro_id', $concepto->id)
                ->orderByDesc('periodo')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();
            $lecturaInformada = $this->measurement($data['lectura_anterior'] ?? null, 'lectura_anterior');
            if ($ultima !== null) {
                if (! $periodo->greaterThan($ultima->periodo)) {
                    throw ValidationException::withMessages([
                        'periodo' => 'El período debe ser posterior a la última lectura registrada.',
                    ]);
                }
                if (bccomp($lecturaInformada, $ultima->lectura_actual, 4) !== 0) {
                    throw ValidationException::withMessages([
                        'lectura_anterior' => 'La lectura anterior debe coincidir con la última lectura válida: '.$ultima->lectura_actual.'.',
                    ]);
                }
                $lecturaAnterior = $ultima->lectura_actual;
            } else {
                $lecturaAnterior = $lecturaInformada;
            }

            $lecturaActual = $this->measurement($data['lectura_actual'] ?? null, 'lectura_actual');
            if (bccomp($lecturaActual, $lecturaAnterior, 4) < 0) {
                throw ValidationException::withMessages([
                    'lectura_actual' => 'La lectura actual no puede ser menor que la lectura anterior.',
                ]);
            }

            $tarifa = $this->tarifaVigente($edificioId, $concepto->id, $periodo);
            if ($tarifa === null || $tarifa->valor === null || $tarifa->unidad === null) {
                throw ValidationException::withMessages([
                    'concepto_cobro_id' => 'El concepto no tiene una tarifa de consumo completa para el período.',
                ]);
            }
            if ($ultima !== null && $ultima->unidad !== trim($tarifa->unidad)) {
                throw ValidationException::withMessages([
                    'concepto_cobro_id' => 'La unidad de la tarifa no coincide con la secuencia histórica de lecturas.',
                ]);
            }
            if ($tarifa->alcance === AlcanceTarifa::DEPARTAMENTOS_ESPECIFICOS
                && ! $tarifa->departamentos()->whereKey($departamento->id)->exists()) {
                throw ValidationException::withMessages([
                    'departamento_id' => 'La tarifa del período no aplica al departamento seleccionado.',
                ]);
            }

            $lectura = new LecturaConsumoEloquentModel();
            $lectura->edificio_id = $edificioId;
            $lectura->departamento_id = $departamento->id;
            $lectura->concepto_cobro_id = $concepto->id;
            $lectura->registrado_por = $userId;
            $lectura->fill([
                'periodo' => $periodo,
                'fecha_lectura' => $fechaLectura,
                'lectura_anterior' => $lecturaAnterior,
                'lectura_actual' => $lecturaActual,
                'consumo' => bcsub($lecturaActual, $lecturaAnterior, 4),
                'unidad' => trim($tarifa->unidad),
                'observacion' => $this->nullableTrim($data['observacion'] ?? null),
            ]);

            try {
                $lectura->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'periodo' => 'Ya existe una lectura para este departamento, concepto y período.',
                ]);
            }

            return ['id' => $lectura->id];
        });
    }

    private function authorizedEdificio(string $userId, string $edificioId): EdificioEloquentModel
    {
        return EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, PermisoEdificio::LECTURAS_REGISTRAR))
            ->where('estado', EstadoEdificio::ACTIVO->value)
            ->lockForUpdate()
            ->findOrFail($edificioId);
    }

    private function tarifaVigente(string $edificioId, string $conceptoId, CarbonImmutable $periodo): ?TarifaConceptoEloquentModel
    {
        return TarifaConceptoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('concepto_cobro_id', $conceptoId)
            ->whereDate('fecha_inicio', '<=', $periodo->format('Y-m-d'))
            ->where(static fn (Builder $query) => $query
                ->whereNull('fecha_fin')
                ->orWhereDate('fecha_fin', '>', $periodo->format('Y-m-d')))
            ->orderByDesc('fecha_inicio')
            ->lockForUpdate()
            ->first();
    }

    /** @return array<string, mixed> */
    private function serialize(LecturaConsumoEloquentModel $lectura): array
    {
        return [
            'id' => $lectura->id,
            'edificioId' => $lectura->edificio_id,
            'edificio' => $lectura->edificio?->nombre,
            'departamentoId' => $lectura->departamento_id,
            'departamento' => $lectura->departamento?->codigo,
            'conceptoId' => $lectura->concepto_cobro_id,
            'concepto' => $lectura->concepto?->nombre,
            'codigoConcepto' => $lectura->concepto?->codigo,
            'periodo' => $lectura->periodo->format('Y-m'),
            'fechaLectura' => $lectura->fecha_lectura->format('Y-m-d'),
            'lecturaAnterior' => $lectura->lectura_anterior,
            'lecturaActual' => $lectura->lectura_actual,
            'consumo' => $lectura->consumo,
            'unidad' => $lectura->unidad,
            'observacion' => $lectura->observacion,
            'registradoPor' => $lectura->registradoPor?->name,
            'createdAt' => $lectura->created_at?->toISOString(),
        ];
    }

    private function period(mixed $value): CarbonImmutable
    {
        $value = (string) $value;
        $period = CarbonImmutable::createFromFormat('!Y-m', $value);
        if ($period === false || $period->format('Y-m') !== $value) {
            throw ValidationException::withMessages(['periodo' => 'El período debe tener formato YYYY-MM.']);
        }

        return $period;
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        $value = (string) $value;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'La fecha debe tener formato YYYY-MM-DD.']);
        }

        return $date;
    }

    private function measurement(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => 'La lectura debe ser un decimal no negativo con hasta cuatro decimales.',
            ]);
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        if (strlen($integer) > 10) {
            throw ValidationException::withMessages([$field => 'La lectura supera la precisión permitida.']);
        }

        return $integer.'.'.str_pad($fraction, 4, '0');
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
