<?php

namespace Src\Finanzas\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;
use Src\Finanzas\Domain\Enums\FormaCalculoCobro;
use Src\Finanzas\Domain\Enums\PeriodicidadCobro;
use Src\Finanzas\Domain\Enums\TipoConceptoCobro;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;

final class SaveConceptoCobroRequest extends FormRequest
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
        $edificio = $this->route('edificio');
        $concepto = $this->route('concepto');
        $unique = Rule::unique((new ConceptoCobroEloquentModel())->getTable(), 'codigo');
        if ($edificio instanceof EdificioEloquentModel) {
            $unique->where('edificio_id', $edificio->id);
        }
        if ($concepto instanceof ConceptoCobroEloquentModel) {
            $unique->ignore($concepto->id);
        }

        return [
            'codigo' => ['required', 'string', 'max:40', $unique],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'tipo' => ['required', Rule::enum(TipoConceptoCobro::class)],
            'periodicidad' => ['required', Rule::enum(PeriodicidadCobro::class)],
            'forma_calculo' => ['required', Rule::enum(FormaCalculoCobro::class)],
            'estado' => ['required', Rule::enum(EstadoConceptoCobro::class)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('tipo') === TipoConceptoCobro::CONSUMO->value
                && $this->input('forma_calculo') !== FormaCalculoCobro::POR_CONSUMO->value) {
                $validator->errors()->add('forma_calculo', 'Los conceptos de consumo deben calcularse por consumo.');
            }
            if ($this->input('tipo') === TipoConceptoCobro::INTERES->value
                && $this->input('forma_calculo') !== FormaCalculoCobro::PORCENTAJE->value) {
                $validator->errors()->add('forma_calculo', 'Los intereses deben calcularse por porcentaje.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => strtoupper(trim((string) $this->input('codigo'))),
            'nombre' => trim((string) $this->input('nombre')),
            'descripcion' => $this->nullableTrim($this->input('descripcion')),
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
