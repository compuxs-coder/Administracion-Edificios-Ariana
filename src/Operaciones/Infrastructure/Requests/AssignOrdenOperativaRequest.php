<?php

namespace Src\Operaciones\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Operaciones\Domain\Enums\TipoResponsableOrdenOperativa;

final class AssignOrdenOperativaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::OPERACIONES_ASIGNAR]) ?? false);
    }

    public function rules(): array
    {
        return [
            'tipo_responsable' => ['required', Rule::enum(TipoResponsableOrdenOperativa::class)],
            'responsable_id' => ['required', 'uuid'],
        ];
    }
}
