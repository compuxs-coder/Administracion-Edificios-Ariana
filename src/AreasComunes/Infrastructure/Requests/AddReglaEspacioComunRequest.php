<?php

namespace Src\AreasComunes\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class AddReglaEspacioComunRequest extends FormRequest
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
        return [
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'requiere_aprobacion' => ['required', 'boolean'],
            'duracion_minima_minutos' => ['required', 'integer', 'min:1', 'max:1440'],
            'duracion_maxima_minutos' => ['required', 'integer', 'min:1', 'max:1440', 'gte:duracion_minima_minutos'],
            'antelacion_minima_minutos' => ['required', 'integer', 'min:0', 'max:43200'],
            'antelacion_maxima_dias' => ['required', 'integer', 'min:1', 'max:365'],
            'buffer_antes_minutos' => ['required', 'integer', 'min:0', 'max:1440'],
            'buffer_despues_minutos' => ['required', 'integer', 'min:0', 'max:1440'],
            'aforo_maximo' => ['required', 'integer', 'min:1', 'max:9999'],
            'reglamento' => ['nullable', 'string', 'max:20000'],
            'horarios' => ['required', 'array', 'min:1', 'max:49'],
            'horarios.*.dia_semana' => ['required', 'integer', 'between:1,7'],
            'horarios.*.hora_inicio' => ['required', 'date_format:H:i'],
            'horarios.*.hora_fin' => ['required', 'date_format:H:i', 'after:horarios.*.hora_inicio'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ((int) $this->input('antelacion_minima_minutos') > ((int) $this->input('antelacion_maxima_dias') * 1440)) {
                $validator->errors()->add('antelacion_minima_minutos', 'La antelación mínima no puede superar la antelación máxima.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $regulations = trim((string) $this->input('reglamento'));
        $this->merge(['reglamento' => $regulations === '' ? null : $regulations]);
    }
}
