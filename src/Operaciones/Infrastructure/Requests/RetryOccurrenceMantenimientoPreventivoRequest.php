<?php

namespace Src\Operaciones\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class RetryOccurrenceMantenimientoPreventivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_PROGRAMAR]) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
