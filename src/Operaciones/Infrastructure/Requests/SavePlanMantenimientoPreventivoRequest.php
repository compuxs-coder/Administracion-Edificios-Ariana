<?php

namespace Src\Operaciones\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Operaciones\Domain\Enums\PrioridadOrdenOperativa;
use Src\Operaciones\Domain\Enums\UnidadRecurrenciaMantenimiento;

final class SavePlanMantenimientoPreventivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'updated_at' => $this->isMethod('post') ? ['prohibited'] : ['required', 'date'],
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'titulo' => ['required', 'string', 'max:180'],
            'descripcion' => ['required', 'string', 'max:10000'],
            'prioridad' => ['required', Rule::enum(PrioridadOrdenOperativa::class)],
            'unidad_recurrencia' => ['required', Rule::enum(UnidadRecurrenciaMantenimiento::class)],
            'intervalo_recurrencia' => ['required', 'integer', 'min:1', 'max:99'],
            'dias_anticipacion' => ['required', 'integer', 'min:0', 'max:365'],
            'torre_id' => ['nullable', 'uuid'],
            'piso_id' => ['nullable', 'uuid'],
            'departamento_id' => ['nullable', 'uuid'],
            'parqueadero_id' => ['nullable', 'uuid'],
            'bodega_id' => ['nullable', 'uuid'],
            'ubicacion_detalle' => ['nullable', 'string', 'max:500'],
            'proveedor_id' => ['nullable', 'uuid', 'required_with:contrato_id'],
            'contrato_id' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = ['torre_id', 'piso_id', 'departamento_id', 'parqueadero_id', 'bodega_id'];
            $selected = array_filter($fields, fn (string $field): bool => $this->input($field) !== null);
            if (count($selected) > 1) {
                $validator->errors()->add('ubicacion', 'Seleccione como máximo un elemento estructural.');
            }
            if ($selected === [] && trim((string) $this->input('ubicacion_detalle')) === '') {
                $validator->errors()->add('ubicacion_detalle', 'Indique un elemento estructural o un detalle de ubicación.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['torre_id', 'piso_id', 'departamento_id', 'parqueadero_id', 'bodega_id', 'ubicacion_detalle', 'proveedor_id', 'contrato_id'] as $field) {
            $value = trim((string) $this->input($field));
            $normalized[$field] = $value === '' ? null : $value;
        }
        $this->merge([
            ...$normalized,
            'codigo' => mb_strtoupper(trim((string) $this->input('codigo'))),
            'titulo' => trim((string) $this->input('titulo')),
            'descripcion' => trim((string) $this->input('descripcion')),
        ]);
    }
}
