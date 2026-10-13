<?php

namespace Src\AreasComunes\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\AreasComunes\Domain\Enums\EstadoEspacioComun;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class ChangeEspacioComunStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::AREAS_COMUNES_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['estado' => ['required', Rule::enum(EstadoEspacioComun::class)]];
    }
}
