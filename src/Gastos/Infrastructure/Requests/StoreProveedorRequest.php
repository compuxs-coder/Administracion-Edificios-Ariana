<?php

namespace Src\Gastos\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Enums\TipoIdentificacion;
use Src\Propiedad\Domain\Enums\TipoPersona;

final class StoreProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::GASTOS_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo_persona' => ['required', Rule::enum(TipoPersona::class)],
            'nombres' => ['nullable', 'required_if:tipo_persona,persona_natural', 'string', 'min:2', 'max:120'],
            'apellidos' => ['nullable', 'required_if:tipo_persona,persona_natural', 'string', 'min:2', 'max:120'],
            'razon_social' => ['nullable', 'required_if:tipo_persona,persona_juridica', 'string', 'min:2', 'max:180'],
            'tipo_identificacion' => ['required', Rule::enum(TipoIdentificacion::class)],
            'identificacion' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9.\/-]+$/'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'celular' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            ...$this->commercialRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombres' => $this->trimOrNull($this->input('nombres')),
            'apellidos' => $this->trimOrNull($this->input('apellidos')),
            'razon_social' => $this->trimOrNull($this->input('razon_social')),
            'identificacion' => mb_strtoupper(trim((string) $this->input('identificacion'))),
            'correo' => $this->lowerOrNull($this->input('correo')),
            'correo_comercial' => $this->lowerOrNull($this->input('correo_comercial')),
        ]);
    }

    /** @return array<string, mixed> */
    private function commercialRules(): array
    {
        return [
            'nombre_comercial' => ['nullable', 'string', 'max:180'],
            'contacto' => ['nullable', 'string', 'max:180'],
            'telefono_comercial' => ['nullable', 'string', 'max:30'],
            'correo_comercial' => ['nullable', 'email', 'max:255'],
            'direccion_comercial' => ['nullable', 'string', 'max:255'],
            'dias_credito' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function lowerOrNull(mixed $value): ?string
    {
        $value = $this->trimOrNull($value);

        return $value === null ? null : mb_strtolower($value);
    }
}
