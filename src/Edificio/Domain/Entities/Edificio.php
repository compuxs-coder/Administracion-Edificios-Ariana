<?php

namespace Src\Edificio\Domain\Entities;

use DateTimeImmutable;
use Src\Edificio\Domain\Enums\EstadoEdificio;

final class Edificio
{
    public function __construct(
        private readonly string $id,
        private string $nombre,
        private ?string $ruc,
        private string $direccion,
        private string $ciudad,
        private ?string $telefono,
        private ?string $correo,
        private ?string $responsable,
        private EstadoEdificio $estado,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    /** @param array<string, mixed> $data */
    public function actualizar(array $data): void
    {
        $this->nombre = $data['nombre'];
        $this->ruc = $data['ruc'] ?? null;
        $this->direccion = $data['direccion'];
        $this->ciudad = $data['ciudad'];
        $this->telefono = $data['telefono'] ?? null;
        $this->correo = $data['correo'] ?? null;
        $this->responsable = $data['responsable'] ?? null;
        $this->updatedAt = new DateTimeImmutable;
    }

    public function cambiarEstado(EstadoEdificio $estado): void
    {
        $this->estado = $estado;
        $this->updatedAt = new DateTimeImmutable;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function ruc(): ?string
    {
        return $this->ruc;
    }

    public function direccion(): string
    {
        return $this->direccion;
    }

    public function ciudad(): string
    {
        return $this->ciudad;
    }

    public function telefono(): ?string
    {
        return $this->telefono;
    }

    public function correo(): ?string
    {
        return $this->correo;
    }

    public function responsable(): ?string
    {
        return $this->responsable;
    }

    public function estado(): EstadoEdificio
    {
        return $this->estado;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'ruc' => $this->ruc,
            'direccion' => $this->direccion,
            'ciudad' => $this->ciudad,
            'telefono' => $this->telefono,
            'correo' => $this->correo,
            'responsable' => $this->responsable,
            'estado' => $this->estado->value,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
