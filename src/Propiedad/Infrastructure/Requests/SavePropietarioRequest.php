<?php

namespace Src\Propiedad\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Enums\TipoIdentificacion;
use Src\Propiedad\Domain\Enums\TipoPersona;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class SavePropietarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');
        if ($edificio instanceof EdificioEloquentModel) {
            return $this->user()?->can('update', $edificio) ?? false;
        }

        $propietario = $this->route('propietario');

        return $propietario instanceof PropietarioEloquentModel
            && ($this->user()?->can('update', $propietario) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $propietario = $this->route('propietario');

        return [
            'tipo_persona' => ['required', Rule::enum(TipoPersona::class)],
            'nombres' => ['nullable', 'required_if:tipo_persona,persona_natural', 'string', 'min:2', 'max:120'],
            'apellidos' => ['nullable', 'required_if:tipo_persona,persona_natural', 'string', 'min:2', 'max:120'],
            'razon_social' => ['nullable', 'required_if:tipo_persona,persona_juridica', 'string', 'min:2', 'max:180'],
            'tipo_identificacion' => ['required', Rule::enum(TipoIdentificacion::class)],
            'identificacion' => [
                'required',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9.\/-]+$/',
                Rule::unique('propietarios', 'identificacion')
                    ->where('tipo_identificacion', $this->input('tipo_identificacion'))
                    ->ignore($propietario instanceof PropietarioEloquentModel ? $propietario->id : null),
            ],
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
            'nombres' => $this->trimOrNull($this->input('nombres')),
            'apellidos' => $this->trimOrNull($this->input('apellidos')),
            'razon_social' => $this->trimOrNull($this->input('razon_social')),
            'identificacion' => mb_strtoupper(trim((string) $this->input('identificacion'))),
            'correo' => $this->input('correo') ? mb_strtolower(trim((string) $this->input('correo'))) : null,
        ]);
    }

    private function trimOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
