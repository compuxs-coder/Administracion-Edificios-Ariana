<?php

namespace Src\Operaciones\Infrastructure\Repositories;

use Illuminate\Support\Facades\DB;
use Src\Operaciones\Domain\Contracts\ProveedoresOperacionesReadInterface;

final class EloquentProveedoresOperacionesRead implements ProveedoresOperacionesReadInterface
{
    public function options(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return ['proveedores' => [], 'contratos' => []];
        }

        $contracts = DB::table($this->table('contratos_proveedor').' as contract')
            ->join($this->table('proveedor_edificio').' as association', static function ($join): void {
                $join->on('association.edificio_id', '=', 'contract.edificio_id')
                    ->on('association.proveedor_id', '=', 'contract.proveedor_id');
            })
            ->whereIn('contract.edificio_id', $buildingIds)
            ->where('contract.estado', 'registrado')
            ->where('association.estado', 'activo')
            ->orderBy('contract.referencia')
            ->get([
                'contract.id', 'contract.edificio_id', 'contract.proveedor_id', 'contract.referencia',
                'contract.objeto', 'contract.fecha_inicio', 'contract.fecha_fin',
            ])
            ->map(static fn (object $contract): array => [
                'id' => $contract->id,
                'edificioId' => $contract->edificio_id,
                'proveedorId' => $contract->proveedor_id,
                'referencia' => $contract->referencia,
                'objeto' => $contract->objeto,
                'fechaInicio' => $contract->fecha_inicio,
                'fechaFin' => $contract->fecha_fin,
            ])->all();

        return ['proveedores' => $this->providers($buildingIds), 'contratos' => $contracts];
    }

    public function providers(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        return $this->providerQuery()
            ->whereIn('association.edificio_id', $buildingIds)
            ->where('association.estado', 'activo')
            ->orderBy('association.edificio_id')
            ->orderBy('association.nombre_comercial')
            ->get($this->providerColumns())
            ->map(fn (object $provider): array => $this->serializeProviderOption($provider))
            ->all();
    }

    public function activeProvider(string $buildingId, string $providerId, bool $lockForUpdate = false): ?array
    {
        $query = $this->providerQuery()
            ->where('association.edificio_id', $buildingId)
            ->where('association.proveedor_id', $providerId)
            ->where('association.estado', 'activo');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $provider = $query->first($this->providerColumns());

        return $provider === null ? null : $this->serializeProvider($provider);
    }

    public function provider(string $buildingId, string $providerId): ?array
    {
        $provider = $this->providerQuery()
            ->where('association.edificio_id', $buildingId)
            ->where('association.proveedor_id', $providerId)
            ->first($this->providerColumns());
        if ($provider === null) {
            return null;
        }
        return $this->serializeProviderOption($provider);
    }

    public function registeredContract(string $buildingId, string $providerId, string $contractId, bool $lockForUpdate = false): ?array
    {
        $query = DB::table($this->table('contratos_proveedor'))
            ->where('id', $contractId)
            ->where('edificio_id', $buildingId)
            ->where('proveedor_id', $providerId)
            ->where('estado', 'registrado');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $contract = $query->first(['id', 'edificio_id', 'proveedor_id', 'referencia', 'objeto', 'fecha_inicio', 'fecha_fin']);

        return $contract === null ? null : $this->serializeContract($contract);
    }

    public function contract(string $buildingId, string $providerId, string $contractId): ?array
    {
        $contract = DB::table($this->table('contratos_proveedor'))
            ->where('id', $contractId)
            ->where('edificio_id', $buildingId)
            ->where('proveedor_id', $providerId)
            ->first(['id', 'edificio_id', 'proveedor_id', 'referencia', 'objeto', 'fecha_inicio', 'fecha_fin']);

        return $contract === null ? null : $this->serializeContract($contract);
    }

    private function providerQuery()
    {
        return DB::table($this->table('proveedor_edificio').' as association')
            ->join($this->table('proveedores').' as provider', 'provider.id', '=', 'association.proveedor_id')
            ->join($this->table('terceros').' as identity', 'identity.id', '=', 'provider.tercero_id');
    }

    /** @return list<string> */
    private function providerColumns(): array
    {
        return [
            'association.edificio_id', 'provider.id', 'provider.tercero_id', 'association.nombre_comercial',
            'association.contacto', 'association.telefono as telefono_comercial', 'association.correo as correo_comercial',
            'identity.tipo_persona', 'identity.nombres', 'identity.apellidos', 'identity.razon_social',
            'identity.tipo_identificacion', 'identity.identificacion', 'identity.telefono', 'identity.celular', 'identity.correo',
        ];
    }

    /** @return array<string, mixed> */
    private function serializeProvider(object $provider): array
    {
        $identityName = $provider->tipo_persona === 'persona_juridica'
            ? $provider->razon_social
            : trim($provider->nombres.' '.$provider->apellidos);

        return [
            'edificioId' => $provider->edificio_id,
            'proveedorId' => $provider->id,
            'terceroId' => $provider->tercero_id,
            'nombre' => $provider->nombre_comercial ?: $identityName,
            'nombreIdentidad' => $identityName,
            'tipoIdentificacion' => $provider->tipo_identificacion,
            'identificacion' => $provider->identificacion,
            'contacto' => $provider->contacto,
            'telefono' => $provider->telefono_comercial ?? $provider->telefono ?? $provider->celular,
            'correo' => $provider->correo_comercial ?? $provider->correo,
        ];
    }

    /** @return array{edificioId: string, proveedorId: string, nombre: string, identificacion: string} */
    private function serializeProviderOption(object $provider): array
    {
        $serialized = $this->serializeProvider($provider);

        return [
            'edificioId' => $serialized['edificioId'],
            'proveedorId' => $serialized['proveedorId'],
            'nombre' => $serialized['nombre'],
            'identificacion' => $serialized['identificacion'],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeContract(object $contract): array
    {
        return [
            'id' => $contract->id,
            'edificioId' => $contract->edificio_id,
            'proveedorId' => $contract->proveedor_id,
            'referencia' => $contract->referencia,
            'objeto' => $contract->objeto,
            'fechaInicio' => $contract->fecha_inicio,
            'fechaFin' => $contract->fecha_fin,
        ];
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? config('database.application_schema').'.'.$name : $name;
    }
}
