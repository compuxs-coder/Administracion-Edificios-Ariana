<?php

namespace Src\Finanzas\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Contracts\TarifaConceptoRepositoryInterface;
use Src\Finanzas\Domain\Enums\AlcanceTarifa;
use Src\Finanzas\Domain\Enums\BaseCalculoInteres;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;
use Src\Finanzas\Domain\Enums\FormaCalculoCobro;
use Src\Finanzas\Domain\Enums\PeriodicidadCobro;
use Src\Finanzas\Domain\Enums\TipoConceptoCobro;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\TarifaConceptoEloquentModel;

final class EloquentTarifaConceptoRepository implements TarifaConceptoRepositoryInterface
{
    public function __construct(private readonly AccesoEdificioRepositoryInterface $access) {}

    public function create(string $userId, string $edificioId, string $conceptoId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $conceptoId, $data): void {
            $this->authorizedEdificio($userId, $edificioId, true);
            $concepto = ConceptoCobroEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->lockForUpdate()
                ->findOrFail($conceptoId);
            if ($concepto->estado !== EstadoConceptoCobro::ACTIVO) {
                throw ValidationException::withMessages([
                    'concepto' => 'El concepto debe estar activo para registrar una tarifa.',
                ]);
            }

            $tarifa = $this->tarifaData($concepto, $data);
            $existentes = TarifaConceptoEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('concepto_cobro_id', $concepto->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->closeOrRejectOverlaps($existentes, $tarifa['fecha_inicio'], $tarifa['fecha_fin']);
            $departamentos = $this->validatedDepartments($edificioId, $tarifa['alcance'], $data['departamentos'] ?? []);

            $nuevaTarifa = new TarifaConceptoEloquentModel();
            $nuevaTarifa->edificio_id = $edificioId;
            $nuevaTarifa->concepto_cobro_id = $concepto->id;
            $nuevaTarifa->fill($tarifa)->save();

            foreach ($departamentos as $departamento) {
                $nuevaTarifa->departamentos()->attach($departamento->id, ['edificio_id' => $edificioId]);
            }
        });
    }

    private function authorizedEdificio(string $userId, string $edificioId, bool $lock): EdificioEloquentModel
    {
        $query = EdificioEloquentModel::query()
            ->whereKey($this->access->buildingIds($userId, PermisoEdificio::CONCEPTOS_GESTIONAR));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($edificioId);
    }

    /** @return array<string, mixed> */
    private function tarifaData(ConceptoCobroEloquentModel $concepto, array $data): array
    {
        $fechaInicio = $this->date($data['fecha_inicio'] ?? null, 'fecha_inicio');
        $fechaFin = ($data['fecha_fin'] ?? null) === null || $data['fecha_fin'] === ''
            ? null
            : $this->date($data['fecha_fin'], 'fecha_fin');
        $alcance = AlcanceTarifa::tryFrom((string) ($data['alcance'] ?? ''));
        if ($alcance === null) {
            throw ValidationException::withMessages(['alcance' => 'El alcance de la tarifa no es válido.']);
        }
        if ($fechaFin !== null && $fechaFin->lessThanOrEqualTo($fechaInicio)) {
            throw ValidationException::withMessages(['fecha_fin' => 'La fecha final debe ser posterior a la fecha de inicio.']);
        }

        $valor = $this->nullableDecimal($data['valor'] ?? null, 'valor', 4);
        $porcentaje = $this->nullableDecimal($data['porcentaje'] ?? null, 'porcentaje', 6);
        $montoTotal = $this->nullableDecimal($data['monto_total'] ?? null, 'monto_total', 4);
        $numeroCuotas = $this->nullablePositiveInteger($data['numero_cuotas'] ?? null, 'numero_cuotas');
        $unidad = $this->nullableTrim($data['unidad'] ?? null);
        $baseCalculo = $this->nullableTrim($data['base_calculo'] ?? null);
        $errors = [];

        if ($concepto->tipo === TipoConceptoCobro::CONSUMO && $concepto->forma_calculo !== FormaCalculoCobro::POR_CONSUMO) {
            $errors['forma_calculo'] = 'Los conceptos de consumo deben calcularse por consumo.';
        }
        if ($concepto->tipo === TipoConceptoCobro::INTERES && $concepto->forma_calculo !== FormaCalculoCobro::PORCENTAJE) {
            $errors['forma_calculo'] = 'Los intereses deben calcularse por porcentaje.';
        }

        if ($porcentaje !== null && $this->decimalUnits($porcentaje, 6) > 100_000_000) {
            $errors['porcentaje'] = 'El porcentaje debe estar entre 0 y 100.';
        }
        if (($montoTotal === null) !== ($numeroCuotas === null)) {
            $errors['monto_total'] = 'Monto total y número de cuotas deben registrarse juntos.';
            $errors['numero_cuotas'] = 'Monto total y número de cuotas deben registrarse juntos.';
        }
        if ($numeroCuotas !== null && $numeroCuotas > 1 && in_array($concepto->periodicidad, [PeriodicidadCobro::UNICO, PeriodicidadCobro::MANUAL], true)) {
            $errors['numero_cuotas'] = 'Una tarifa con varias cuotas requiere una periodicidad automática recurrente.';
        }
        if (in_array($concepto->forma_calculo, [
            FormaCalculoCobro::VALOR_FIJO,
            FormaCalculoCobro::POR_ALICUOTA,
            FormaCalculoCobro::POR_CONSUMO,
        ], true) && $valor === null) {
            $errors['valor'] = 'Esta forma de cálculo requiere un valor.';
        }
        if ($concepto->forma_calculo === FormaCalculoCobro::PORCENTAJE && $porcentaje === null) {
            $errors['porcentaje'] = 'Esta forma de cálculo requiere un porcentaje.';
        }
        if ($concepto->tipo === TipoConceptoCobro::CONSUMO && $unidad === null) {
            $errors['unidad'] = 'Los conceptos de consumo requieren una unidad.';
        }
        if ($concepto->tipo === TipoConceptoCobro::INTERES && $baseCalculo === null) {
            $errors['base_calculo'] = 'Los intereses requieren una base de cálculo.';
        }
        if ($baseCalculo !== null && BaseCalculoInteres::tryFrom($baseCalculo) === null) {
            $errors['base_calculo'] = 'La base de cálculo no es válida.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($concepto->forma_calculo !== FormaCalculoCobro::PORCENTAJE) {
            $porcentaje = null;
        }
        if (! in_array($concepto->forma_calculo, [
            FormaCalculoCobro::VALOR_FIJO,
            FormaCalculoCobro::POR_ALICUOTA,
            FormaCalculoCobro::POR_CONSUMO,
        ], true)) {
            $valor = null;
        }
        if ($concepto->tipo !== TipoConceptoCobro::CONSUMO) {
            $unidad = null;
        }
        if ($concepto->tipo !== TipoConceptoCobro::INTERES) {
            $baseCalculo = null;
        }
        if ($concepto->tipo !== TipoConceptoCobro::EXTRAORDINARIO) {
            $montoTotal = null;
            $numeroCuotas = null;
        }

        return [
            'valor' => $valor,
            'porcentaje' => $porcentaje,
            'monto_total' => $montoTotal,
            'numero_cuotas' => $numeroCuotas,
            'unidad' => $unidad,
            'base_calculo' => $baseCalculo,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'alcance' => $alcance,
            'observacion' => $this->nullableTrim($data['observacion'] ?? null),
        ];
    }

    /** @param Collection<int, TarifaConceptoEloquentModel> $tarifas */
    private function closeOrRejectOverlaps(Collection $tarifas, CarbonImmutable $fechaInicio, ?CarbonImmutable $fechaFin): void
    {
        $overlaps = $tarifas->filter(fn (TarifaConceptoEloquentModel $tarifa): bool => $tarifa->fecha_inicio->lessThan($fechaFin ?? CarbonImmutable::create(9999, 12, 31))
            && $fechaInicio->lessThan($tarifa->fecha_fin ?? CarbonImmutable::create(9999, 12, 31)));

        if ($overlaps->isEmpty()) {
            return;
        }

        $anterior = $overlaps->first();
        if ($overlaps->count() === 1 && $anterior->fecha_fin === null && $anterior->fecha_inicio->lessThan($fechaInicio)) {
            $anterior->fecha_fin = $fechaInicio;
            $anterior->save();

            return;
        }

        throw ValidationException::withMessages([
            'fecha_inicio' => 'La tarifa se superpone con una vigencia existente. Registre cambios en orden cronológico.',
        ]);
    }

    /** @param mixed $ids @return Collection<int, DepartamentoEloquentModel> */
    private function validatedDepartments(string $edificioId, AlcanceTarifa $alcance, mixed $ids): Collection
    {
        if (! is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map(static fn (mixed $id): string => Str::lower((string) $id), $ids)));

        if ($alcance === AlcanceTarifa::TODO_EL_EDIFICIO) {
            if ($ids !== []) {
                throw ValidationException::withMessages([
                    'departamentos' => 'El alcance para todo el edificio no admite departamentos específicos.',
                ]);
            }

            return collect();
        }
        if ($ids === [] || count($ids) !== count(array_unique($ids)) || collect($ids)->contains(static fn (string $id): bool => ! Str::isUuid($id))) {
            throw ValidationException::withMessages([
                'departamentos' => 'Seleccione departamentos válidos sin repetirlos.',
            ]);
        }

        $departamentos = DepartamentoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('estado', EstadoEstructura::ACTIVO->value)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($departamentos->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'departamentos' => 'Todos los departamentos deben pertenecer al edificio y estar activos.',
            ]);
        }

        return $departamentos;
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

    private function nullableDecimal(mixed $value, string $field, int $decimals): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,'.$decimals.'})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'El valor debe ser numérico y no negativo.']);
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $maxIntegerDigits = $field === 'porcentaje' ? 3 : 10;
        if (strlen($integer) > $maxIntegerDigits) {
            throw ValidationException::withMessages([$field => 'El valor supera la precisión permitida.']);
        }

        return $integer.'.'.str_pad($fraction, $decimals, '0');
    }

    private function decimalUnits(string $value, int $decimals): int
    {
        [$integer, $fraction] = explode('.', $value, 2);

        return ((int) $integer * (10 ** $decimals)) + (int) str_pad($fraction, $decimals, '0');
    }

    private function nullablePositiveInteger(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw ValidationException::withMessages([$field => 'El número de cuotas debe ser un entero positivo.']);
        }

        return (int) $value;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
