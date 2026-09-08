<?php

namespace Src\Edificio\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Illuminate\Validation\Rule;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SaveAnexoRequest extends FormRequest
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
        $parqueadero = $this->routeIs('edificios.parqueaderos.*');
        $table = $parqueadero ? 'parqueaderos' : 'bodegas';
        $routeParameter = $parqueadero ? 'parqueadero' : 'bodega';

        return [
            'torre_id' => ['nullable', 'uuid'],
            'codigo' => [
                'required',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique($table, 'codigo')
                    ->where('edificio_id', $edificio->id)
                    ->ignore($this->route($routeParameter)),
            ],
            'ubicacion' => ['nullable', 'string', 'max:150'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => mb_strtoupper(trim((string) $this->input('codigo'))),
            'torre_id' => $this->input('torre_id') ?: null,
        ]);
    }
}
