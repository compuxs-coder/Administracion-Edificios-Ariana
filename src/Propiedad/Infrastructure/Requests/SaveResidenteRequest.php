<?php

namespace Src\Propiedad\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Illuminate\Validation\Rule;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Enums\TipoIdentificacion;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;

final class SaveResidenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');
        if ($edificio instanceof EdificioEloquentModel) {
            return $this->user()?->can('access', [$edificio, PermisoEdificio::PROPIEDAD_GESTIONAR]) ?? false;
        }

        $residente = $this->route('residente');

        return $residente instanceof ResidenteEloquentModel
            && ($this->user()?->can('update', $residente) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'min:2', 'max:120'],
            'apellidos' => ['required', 'string', 'min:2', 'max:120'],
            'tipo_identificacion' => ['required', Rule::enum(TipoIdentificacion::class)],
            'identificacion' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9.\/-]+$/'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'celular' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombres' => trim((string) $this->input('nombres')),
            'apellidos' => trim((string) $this->input('apellidos')),
            'identificacion' => mb_strtoupper(trim((string) $this->input('identificacion'))),
            'correo' => $this->input('correo') ? mb_strtolower(trim((string) $this->input('correo'))) : null,
        ]);
    }
}
