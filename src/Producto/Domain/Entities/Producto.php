<?php

namespace Src\Producto\Domain\Entities;

use DateTimeImmutable;

class Producto
{
    private string $id;
    private string $categoriaId;
    private string $codigo;
    private string $nombre;
    private ?string $descripcion;
    private float $precioUnitario;
    private int $stock;
    private string $tipo;
    private bool $activo;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $categoriaId,
        string $codigo,
        string $nombre,
        ?string $descripcion,
        float $precioUnitario,
        int $stock,
        string $tipo,
        bool $activo,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ) {
        $this->id = $id;
        $this->categoriaId = $categoriaId;
        $this->codigo = $codigo;
        $this->nombre = $nombre;
        $this->descripcion = $descripcion;
        $this->precioUnitario = $precioUnitario;
        $this->stock = $stock;
        $this->tipo = $tipo;
        $this->activo = $activo;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCategoriaId(): string
    {
        return $this->categoriaId;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function getPrecioUnitario(): float
    {
        return $this->precioUnitario;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updateCategoriaId(string $categoriaId): void
    {
        $this->categoriaId = $categoriaId;
    }

    public function updateCodigo(string $codigo): void
    {
        $this->codigo = $codigo;
    }

    public function updateNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function updateDescripcion(?string $descripcion): void
    {
        $this->descripcion = $descripcion;
    }

    public function updatePrecioUnitario(float $precioUnitario): void
    {
        $this->precioUnitario = $precioUnitario;
    }

    public function updateStock(int $stock): void
    {
        $this->stock = $stock;
    }

    public function updateTipo(string $tipo): void
    {
        $this->tipo = $tipo;
    }

    public function updateActivo(bool $activo): void
    {
        $this->activo = $activo;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'categoriaId' => $this->categoriaId,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'precioUnitario' => $this->precioUnitario,
            'stock' => $this->stock,
            'tipo' => $this->tipo,
            'activo' => $this->activo,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
