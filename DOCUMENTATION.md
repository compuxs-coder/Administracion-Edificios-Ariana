# Documentación de la API de Facturas

## Diseño MVC y DDD

La API organiza `Factura` como un bounded context independiente en `src/Factura`:

```text
src/Factura/
├── Domain/
│   ├── Contracts/FacturaRepositoryInterface.php
│   ├── Entities/Factura.php
│   └── Exceptions/FacturaNotFoundException.php
├── Application/
│   ├── Actions/
│   │   ├── CreateFacturaAction.php
│   │   ├── DeleteFacturaAction.php
│   │   ├── GetFacturaAction.php
│   │   ├── ListFacturasAction.php
│   │   └── UpdateFacturaAction.php
│   └── Controllers/FacturaController.php
└── Infrastructure/
    ├── Mappers/FacturaMapper.php
    ├── Migrations/2026_01_01_180035_create_facturas_table.php
    ├── Models/FacturaEloquentModel.php
    ├── Repositories/EloquentFacturaRepository.php
    ├── Requests/
    │   ├── StoreFacturaRequest.php
    │   └── UpdateFacturaRequest.php
    └── Resources/FacturaResource.php
```

Responsabilidades:

- **Modelo (MVC):** entidad de dominio, contrato del repositorio y persistencia Eloquent.
- **Vista (MVC):** representación JSON de `FacturaResource`.
- **Controlador (MVC):** recibe HTTP, delega al caso de uso y construye la respuesta.
- **Dominio (DDD):** representa `Factura` sin depender del framework.
- **Aplicación (DDD):** implementa un caso de uso por operación.
- **Infraestructura (DDD):** adapta HTTP, Laravel y la base de datos al dominio.

`BoundedContextServiceProvider` enlaza `FacturaRepositoryInterface` con `EloquentFacturaRepository`, aplicando inversión de dependencias.

## Autenticación

El recurso usa `auth:sanctum`. Primero se registra un usuario o se inicia sesión:

```http
POST /api/v1/auth/register
Content-Type: application/json
Accept: application/json

{
  "name": "Usuario API",
  "email": "usuario@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

El valor `data.access_token` de la respuesta se envía en las operaciones de facturas:

```http
Authorization: Bearer {access_token}
Accept: application/json
```

## Operaciones CRUD

### Crear

```http
POST /api/v1/facturas
```

```json
{
  "name": "Acme Ecuador",
  "ruc": "1790012345001",
  "email": "billing@acme.test",
  "phone": "+593 2 555 0100",
  "address": "Av. Naciones Unidas 123",
  "city": "Quito",
  "country": "Ecuador",
  "status": "active"
}
```

Devuelve `201 Created`. `id` y `created_at` se generan en el servidor y no se aceptan como entrada.

### Listar

```http
GET /api/v1/facturas
```

Devuelve `200 OK` con una colección ordenada desde la factura más reciente:

```json
{
  "data": [
    {
      "id": "7b9de03b-c58d-45d0-9ee8-e47b28db14a5",
      "name": "Acme Ecuador",
      "ruc": "1790012345001",
      "email": "billing@acme.test",
      "phone": "+593 2 555 0100",
      "address": "Av. Naciones Unidas 123",
      "city": "Quito",
      "country": "Ecuador",
      "status": "active",
      "created_at": "2026-08-04 12:00:00"
    }
  ]
}
```

### Consultar

```http
GET /api/v1/facturas/{id}
```

Devuelve `200 OK` o `404 Not Found` cuando el UUID no existe.

### Actualizar

```http
PATCH /api/v1/facturas/{id}
```

Se puede enviar uno o varios campos editables:

```json
{
  "email": "invoices@acme.test",
  "status": "inactive"
}
```

También se admite `PUT`. Devuelve `200 OK`, `404 Not Found` o `422 Unprocessable Entity`.

### Eliminar

```http
DELETE /api/v1/facturas/{id}
```

Respuesta `200 OK`:

```json
{
  "message": "Factura eliminada exitosamente."
}
```

## Validación

- Todos los campos son obligatorios al crear.
- `ruc` puede tener hasta 20 caracteres y no se puede repetir.
- `email` debe tener un formato válido.
- En una actualización sólo se validan los campos enviados.
- `created_at` no se puede modificar mediante la API.

Los errores de entrada usan el formato de validación estándar de Laravel y el código HTTP `422`.

## Ejecución local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh
php artisan serve
```

Para ejecutar la suite automatizada:

```bash
php artisan test
```
