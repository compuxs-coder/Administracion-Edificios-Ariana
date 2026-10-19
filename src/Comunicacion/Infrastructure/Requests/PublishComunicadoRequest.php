<?php

namespace Src\Comunicacion\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class PublishComunicadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::COMUNICADOS_PUBLICAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['updated_at' => ['required', 'date']];
    }
}
