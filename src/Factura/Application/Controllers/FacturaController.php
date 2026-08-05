<?php

namespace Src\Factura\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Factura\Application\Actions\CreateFacturaAction;
use Src\Factura\Application\Actions\DeleteFacturaAction;
use Src\Factura\Application\Actions\GetFacturaAction;
use Src\Factura\Application\Actions\ListFacturasAction;
use Src\Factura\Application\Actions\UpdateFacturaAction;
use Src\Factura\Infrastructure\Requests\StoreFacturaRequest;
use Src\Factura\Infrastructure\Requests\UpdateFacturaRequest;
use Src\Factura\Infrastructure\Resources\FacturaResource;

final class FacturaController extends Controller
{
    public function __construct(
        private readonly ListFacturasAction $listFacturas,
        private readonly CreateFacturaAction $createFactura,
        private readonly GetFacturaAction $getFactura,
        private readonly UpdateFacturaAction $updateFactura,
        private readonly DeleteFacturaAction $deleteFactura,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return FacturaResource::collection($this->listFacturas->execute());
    }

    public function store(StoreFacturaRequest $request): JsonResponse
    {
        $factura = $this->createFactura->execute($request->validated());

        return (new FacturaResource($factura))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): FacturaResource
    {
        return new FacturaResource($this->getFactura->execute($id));
    }

    public function update(UpdateFacturaRequest $request, string $id): FacturaResource
    {
        return new FacturaResource(
            $this->updateFactura->execute($id, $request->validated()),
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->deleteFactura->execute($id);

        return response()->json([
            'message' => 'Factura eliminada exitosamente.',
        ]);
    }
}
