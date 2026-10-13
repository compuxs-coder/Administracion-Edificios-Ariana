<?php

namespace Src\Edificio\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class UpdateEdificioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('update', $edificio) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var EdificioEloquentModel $edificio */
        $edificio = $this->route('edificio');

        return [
            'nombre' => ['required', 'string', 'min:2', 'max:150'],
            'ruc' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('edificios', 'ruc')->ignore($edificio->id),
            ],
            'direccion' => ['required', 'string', 'min:3', 'max:255'],
            'ciudad' => ['required', 'string', 'min:2', 'max:100'],
            'timezone' => ['sometimes', 'required', Rule::exists('zonas_horarias', 'nombre')],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'responsable' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var EdificioEloquentModel $building */
            $building = $this->route('edificio');
            $timezone = $this->input('timezone');
            if ($timezone === null || $timezone === $building->timezone || $validator->errors()->has('timezone')) {
                return;
            }
            $table = DB::connection()->getDriverName() === 'pgsql'
                ? config('database.application_schema').'.reglas_espacio_comun'
                : 'reglas_espacio_comun';
            if (DB::getSchemaBuilder()->hasTable($table) && DB::table($table)->where('edificio_id', $building->id)->exists()) {
                $validator->errors()->add('timezone', 'La zona horaria no puede cambiar después de registrar reglas de áreas comunes.');
            }
        });
    }
}
