<?php

namespace Src\Categoria\Application\Controllers;

use App\Http\Controllers\Controller;
use Src\Categoria\Infrastructure\Requests\StoreCategoriaRequest;
use Src\Categoria\Infrastructure\Requests\UpdateCategoriaRequest;
use Src\Categoria\Infrastructure\Resources\CategoriaResource;
use Src\Categoria\Infrastructure\Models\CategoriaEloquentModel;

class CategoriaController extends Controller
{
    public function index()
    {
        $categorias = CategoriaEloquentModel::all();
        return CategoriaResource::collection($categorias);
    }

    public function store(StoreCategoriaRequest $request)
    {
        $categoria = CategoriaEloquentModel::create($request->validated());
        return new CategoriaResource($categoria);
    }

    public function show(string $id)
    {
        $categoria = CategoriaEloquentModel::find($id);

        if (!$categoria) {
            return response()->json([
                'success' => false,
                'message' => 'Categoria no encontrada'
            ], 404);
        }

        return new CategoriaResource($categoria);
    }

    public function update(UpdateCategoriaRequest $request, string $id)
    {
        $categoria = CategoriaEloquentModel::find($id);

        if (!$categoria) {
            return response()->json([
                'success' => false,
                'message' => 'Categoria no encontrada'
            ], 404);
        }

        $categoria->update($request->validated());
        return new CategoriaResource($categoria);
    }

    public function destroy(string $id)
    {
        $categoria = CategoriaEloquentModel::find($id);

        if (!$categoria) {
            return response()->json([
                'success' => false,
                'message' => 'Categoria no encontrada'
            ], 404);
        }

        $categoria->delete();

        return response()->json([
            'success' => true,
            'message' => 'Categoria eliminada exitosamente'
        ], 200);
    }
}
