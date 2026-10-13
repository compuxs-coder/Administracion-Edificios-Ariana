<?php

namespace Src\AreasComunes\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class TransitionReservaEspacioComunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');
        $permission = $this->routeIs('reservas-areas-comunes.cancel')
            ? PermisoEdificio::RESERVAS_AREAS_COMUNES_CANCELAR
            : PermisoEdificio::RESERVAS_AREAS_COMUNES_APROBAR;

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, $permission]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo' => $this->routeIs('reservas-areas-comunes.approve')
                ? ['prohibited']
                : ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->routeIs('reservas-areas-comunes.approve')) {
            $this->merge(['motivo' => trim((string) $this->input('motivo'))]);
        }
    }
}
