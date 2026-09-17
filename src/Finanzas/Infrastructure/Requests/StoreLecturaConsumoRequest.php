<?php

namespace Src\Finanzas\Infrastructure\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class StoreLecturaConsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::LECTURAS_REGISTRAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'departamento_id' => ['required', 'uuid'],
            'concepto_cobro_id' => ['required', 'uuid'],
            'periodo' => ['required', 'date_format:Y-m'],
            'fecha_lectura' => ['required', 'date_format:Y-m-d'],
            'lectura_anterior' => ['required', 'decimal:0,4', 'min:0', 'max:9999999999.9999'],
            'lectura_actual' => ['required', 'decimal:0,4', 'min:0', 'max:9999999999.9999'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $periodo = CarbonImmutable::createFromFormat('!Y-m', (string) $this->input('periodo'));
            $fecha = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('fecha_lectura'));
            if ($periodo !== false && $fecha !== false && ! $fecha->isSameMonth($periodo)) {
                $validator->errors()->add('fecha_lectura', 'La fecha de lectura debe pertenecer al período seleccionado.');
            }
            if (bccomp((string) $this->input('lectura_actual'), (string) $this->input('lectura_anterior'), 4) < 0) {
                $validator->errors()->add('lectura_actual', 'La lectura actual no puede ser menor que la lectura anterior.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'departamento_id' => strtolower(trim((string) $this->input('departamento_id'))),
            'concepto_cobro_id' => strtolower(trim((string) $this->input('concepto_cobro_id'))),
            'lectura_anterior' => trim((string) $this->input('lectura_anterior')),
            'lectura_actual' => trim((string) $this->input('lectura_actual')),
            'observacion' => $this->nullableTrim($this->input('observacion')),
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
