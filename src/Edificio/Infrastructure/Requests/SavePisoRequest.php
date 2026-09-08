<?php

namespace Src\Edificio\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Illuminate\Validation\Rule;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SavePisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::ESTRUCTURA_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var EdificioEloquentModel $edificio */
        $edificio = $this->route('edificio');
        $ignore = $this->route('piso');

        return [
            'torre_id' => ['required', 'uuid'],
            'numero' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('pisos', 'numero')
                    ->where('edificio_id', $edificio->id)
                    ->where('torre_id', $this->input('torre_id'))
                    ->ignore($ignore),
            ],
            'nombre' => ['nullable', 'string', 'max:100'],
            'orden' => [
                'required',
                'integer',
                'between:-20,300',
                Rule::unique('pisos', 'orden')
                    ->where('edificio_id', $edificio->id)
                    ->where('torre_id', $this->input('torre_id'))
                    ->ignore($ignore),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['numero' => mb_strtoupper(trim((string) $this->input('numero')))]);
    }
}
