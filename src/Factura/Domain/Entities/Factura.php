<?php

namespace Src\Factura\Domain\Entities;

use DateTimeImmutable;

final class Factura
{
    public function __construct(
        private readonly string $id,
        private string $name,
        private string $ruc,
        private string $email,
        private string $phone,
        private string $address,
        private string $city,
        private string $country,
        private string $status,
        private readonly DateTimeImmutable $createdAt,
    ) {}

    public function update(
        string $name,
        string $ruc,
        string $email,
        string $phone,
        string $address,
        string $city,
        string $country,
        string $status,
    ): void {
        $this->name = $name;
        $this->ruc = $ruc;
        $this->email = $email;
        $this->phone = $phone;
        $this->address = $address;
        $this->city = $city;
        $this->country = $country;
        $this->status = $status;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function ruc(): string
    {
        return $this->ruc;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function address(): string
    {
        return $this->address;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function country(): string
    {
        return $this->country;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ruc' => $this->ruc,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'status' => $this->status,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
