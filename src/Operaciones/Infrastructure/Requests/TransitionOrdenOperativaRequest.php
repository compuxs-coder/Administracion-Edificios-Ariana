<?php

namespace Src\Operaciones\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class TransitionOrdenOperativaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::OPERACIONES_CAMBIAR_ESTADO]) ?? false);
    }

    public function rules(): array
    {
        return ['estado' => ['required', Rule::in(['en_revision', 'en_progreso', 'resuelta', 'cerrada'])]];
    }
}
