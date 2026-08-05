<?php

namespace Src\Factura\Infrastructure\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FacturaResource extends JsonResource
{
    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
