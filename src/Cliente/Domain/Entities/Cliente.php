<?php

namespace Src\Cliente\Domain\Entities;
use DateTimeImmutable;

class Cliente
{
    private string $id;
    private string $tipoDocumento;
    private string $numeroDocumento;
    private string $razonSocial;
    private string $direccion;
    private string $telefono;
    private string $email;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $tipoDocumento,
        string $numeroDocumento,
        string $razonSocial,
        string $direccion,
        string $telefono,
        string $email,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ) {
        $this->id = $id;
        $this->tipoDocumento = $tipoDocumento;
        $this->numeroDocumento = $numeroDocumento;
        $this->razonSocial = $razonSocial;
        $this->direccion = $direccion;
        $this->telefono = $telefono;
        $this->email = $email;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTipoDocumento(): string
    {
        return $this->tipoDocumento;
    }

    public function getNumeroDocumento(): string
    {
        return $this->numeroDocumento;
    }

    public function getRazonSocial(): string
    {
        return $this->razonSocial;
    }

    public function getDireccion(): string
    {
        return $this->direccion;
    }

    public function getTelefono(): string
    {
        return $this->telefono;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updateTipoDocumento(string $tipoDocumento): void
    {
        $this->tipoDocumento = $tipoDocumento;
    }

    public function updateNumeroDocumento(string $numeroDocumento): void
    {
        $this->numeroDocumento = $numeroDocumento;
    }

    public function updateRazonSocial(string $razonSocial): void
    {
        $this->razonSocial = $razonSocial;
    }

    public function updateDireccion(string $direccion): void
    {
        $this->direccion = $direccion;
    }

    public function updateTelefono(string $telefono): void
    {
        $this->telefono = $telefono;
    }

    public function updateEmail(string $email): void
    {
        $this->email = $email;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tipoDocumento' => $this->tipoDocumento,
            'numeroDocumento' => $this->numeroDocumento,
            'razonSocial' => $this->razonSocial,
            'direccion' => $this->direccion,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
