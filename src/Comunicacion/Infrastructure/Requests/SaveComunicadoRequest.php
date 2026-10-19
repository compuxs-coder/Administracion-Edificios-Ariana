<?php

namespace Src\Comunicacion\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Comunicacion\Domain\Enums\AudienciaComunicado;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SaveComunicadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::COMUNICADOS_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'updated_at' => $this->isMethod('post') ? ['prohibited'] : ['required', 'date'],
            'titulo' => ['required', 'string', 'max:180'],
            'cuerpo' => ['required', 'string', 'max:30000'],
            'audiencias' => ['required', 'array', 'min:1', 'max:2'],
            'audiencias.*' => ['required', 'distinct', Rule::enum(AudienciaComunicado::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'titulo' => trim((string) $this->input('titulo')),
            'cuerpo' => trim((string) $this->input('cuerpo')),
        ]);
    }
}
