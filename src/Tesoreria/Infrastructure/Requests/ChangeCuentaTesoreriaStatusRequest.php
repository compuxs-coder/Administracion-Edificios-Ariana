<?php

namespace Src\Tesoreria\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Tesoreria\Domain\Enums\EstadoCuentaTesoreria;

final class ChangeCuentaTesoreriaStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['estado' => ['required', Rule::enum(EstadoCuentaTesoreria::class)]];
    }
}
