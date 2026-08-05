<?php

namespace Src\Factura\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFacturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'ruc' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('facturas', 'ruc')->ignore($this->route('factura')),
            ],
            'email' => 'sometimes|required|email|max:255',
            'phone' => 'sometimes|required|string|max:30',
            'address' => 'sometimes|required|string|max:500',
            'city' => 'sometimes|required|string|max:100',
            'country' => 'sometimes|required|string|max:100',
            'status' => 'sometimes|required|string|max:50',
        ];
    }
}
