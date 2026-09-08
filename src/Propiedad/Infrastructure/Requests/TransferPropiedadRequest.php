<?php

namespace Src\Propiedad\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Illuminate\Validation\Validator;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class TransferPropiedadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::PROPIEDAD_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_transferencia' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'propietarios' => ['required', 'array', 'min:1', 'max:20'],
            'propietarios.*' => ['required', 'array'],
            'propietarios.*.propietario_id' => ['required', 'uuid', 'distinct:strict'],
            'propietarios.*.porcentaje' => ['required', 'numeric', 'decimal:0,6', 'gt:0', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $units = collect($this->input('propietarios', []))->sum(
                static fn (mixed $item): int => is_array($item)
                    ? (int) round((float) ($item['porcentaje'] ?? 0) * 1_000_000)
                    : 0,
            );

            if ($units !== 100_000_000) {
                $validator->errors()->add('propietarios', 'La transferencia debe distribuir exactamente el 100%.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $propietarios = $this->input('propietarios');
        if (! is_array($propietarios)) {
            return;
        }

        $this->merge(['propietarios' => array_map(static function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $item['propietario_id'] = strtolower((string) ($item['propietario_id'] ?? ''));

            return $item;
        }, $propietarios)]);
    }
}
