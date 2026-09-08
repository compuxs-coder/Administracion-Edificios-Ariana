<?php

namespace Src\Finanzas\Infrastructure\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Enums\AlcanceTarifa;
use Src\Finanzas\Domain\Enums\BaseCalculoInteres;
use Src\Finanzas\Domain\Enums\FormaCalculoCobro;
use Src\Finanzas\Domain\Enums\PeriodicidadCobro;
use Src\Finanzas\Domain\Enums\TipoConceptoCobro;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;

final class CreateTarifaConceptoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::CONCEPTOS_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'valor' => ['nullable', 'decimal:0,4', 'min:0'],
            'porcentaje' => ['nullable', 'decimal:0,6', 'min:0', 'max:100'],
            'monto_total' => ['nullable', 'decimal:0,4', 'min:0'],
            'numero_cuotas' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'unidad' => ['nullable', 'string', 'max:30'],
            'base_calculo' => ['nullable', Rule::enum(BaseCalculoInteres::class)],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
            'alcance' => ['required', Rule::enum(AlcanceTarifa::class)],
            'departamentos' => ['nullable', 'array', 'max:500'],
            'departamentos.*' => ['required', 'uuid', 'distinct:strict'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fechaInicio = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('fecha_inicio'));
            $fechaFin = $this->input('fecha_fin') === null
                ? null
                : CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('fecha_fin'));
            if ($fechaFin !== null && $fechaFin->lessThanOrEqualTo($fechaInicio)) {
                $validator->errors()->add('fecha_fin', 'La fecha final debe ser posterior a la fecha de inicio.');
            }

            $concepto = $this->route('concepto');
            $edificio = $this->route('edificio');
            if (! $concepto instanceof ConceptoCobroEloquentModel
                || ! $edificio instanceof EdificioEloquentModel
                || $concepto->edificio_id !== $edificio->id) {
                return;
            }
            if (in_array($concepto->forma_calculo, [
                FormaCalculoCobro::VALOR_FIJO,
                FormaCalculoCobro::POR_ALICUOTA,
                FormaCalculoCobro::POR_CONSUMO,
            ], true) && $this->input('valor') === null) {
                $validator->errors()->add('valor', 'Esta forma de cálculo requiere un valor.');
            }
            if ($concepto->forma_calculo === FormaCalculoCobro::PORCENTAJE && $this->input('porcentaje') === null) {
                $validator->errors()->add('porcentaje', 'Esta forma de cálculo requiere un porcentaje.');
            }
            if ($concepto->tipo === TipoConceptoCobro::CONSUMO && $this->input('unidad') === null) {
                $validator->errors()->add('unidad', 'Los conceptos de consumo requieren una unidad.');
            }
            if ($concepto->tipo === TipoConceptoCobro::INTERES && $this->input('base_calculo') === null) {
                $validator->errors()->add('base_calculo', 'Los intereses requieren una base de cálculo.');
            }
            if (($this->input('monto_total') === null) !== ($this->input('numero_cuotas') === null)) {
                $validator->errors()->add('monto_total', 'Monto total y número de cuotas deben registrarse juntos.');
                $validator->errors()->add('numero_cuotas', 'Monto total y número de cuotas deben registrarse juntos.');
            }
            if ((int) $this->input('numero_cuotas') > 1 && in_array($concepto->periodicidad, [PeriodicidadCobro::UNICO, PeriodicidadCobro::MANUAL], true)) {
                $validator->errors()->add('numero_cuotas', 'Una tarifa con varias cuotas requiere una periodicidad automática recurrente.');
            }

            $departamentos = $this->input('departamentos', []);
            if ($this->input('alcance') === AlcanceTarifa::DEPARTAMENTOS_ESPECIFICOS->value
                && (! is_array($departamentos) || $departamentos === [])) {
                $validator->errors()->add('departamentos', 'Seleccione al menos un departamento.');
            }
            if ($this->input('alcance') === AlcanceTarifa::TODO_EL_EDIFICIO->value
                && is_array($departamentos) && $departamentos !== []) {
                $validator->errors()->add('departamentos', 'El alcance para todo el edificio no admite departamentos específicos.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $departamentos = $this->input('departamentos');
        $this->merge([
            'valor' => $this->nullableTrim($this->input('valor')),
            'porcentaje' => $this->nullableTrim($this->input('porcentaje')),
            'monto_total' => $this->nullableTrim($this->input('monto_total')),
            'numero_cuotas' => $this->nullableTrim($this->input('numero_cuotas')),
            'unidad' => $this->nullableTrim($this->input('unidad')),
            'base_calculo' => $this->nullableTrim($this->input('base_calculo')),
            'fecha_fin' => $this->nullableTrim($this->input('fecha_fin')),
            'observacion' => $this->nullableTrim($this->input('observacion')),
            'departamentos' => is_array($departamentos)
                ? array_map(static fn (mixed $id): string => strtolower((string) $id), $departamentos)
                : $departamentos,
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
