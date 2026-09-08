<?php

namespace Src\Edificio\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class ChangeEstructuraStatusRequest extends FormRequest
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
        return ['estado' => ['required', Rule::enum(EstadoEstructura::class)]];
    }
}
