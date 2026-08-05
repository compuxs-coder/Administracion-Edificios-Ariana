# API REST MVC y DDD - Facturas

API CRUD construida con Laravel 12, MVC, DDD y autenticación mediante Laravel Sanctum.

## Requisitos

- PHP 8.2 o superior
- Composer
- SQLite, MySQL o PostgreSQL

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

La URL base de la API es `http://localhost:8000/api/v1`.

## Pruebas

```bash
php artisan test
```

## Arquitectura

El contexto `src/Factura` se divide en:

- `Domain`: entidad `Factura`, contrato del repositorio y excepción de dominio.
- `Application`: casos de uso para crear, actualizar, consultar, eliminar y listar; controlador MVC.
- `Infrastructure`: modelo Eloquent, mapper, repositorio, validaciones, recurso JSON y migración.

El flujo es `Route -> Controller -> Action -> RepositoryInterface -> EloquentRepository -> Database`. El dominio no depende de Laravel ni de Eloquent.

## Entidad Factura

| Campo | Tipo | Reglas |
|---|---|---|
| `id` | UUID | Generado por el servidor |
| `name` | string | Obligatorio |
| `ruc` | string | Obligatorio y único |
| `email` | string | Obligatorio y formato email |
| `phone` | string | Obligatorio |
| `address` | string | Obligatorio |
| `city` | string | Obligatorio |
| `country` | string | Obligatorio |
| `status` | string | Obligatorio |
| `created_at` | timestamp | Generado por el servidor |

## Endpoints

Todos los endpoints requieren el encabezado `Authorization: Bearer {token}`.

| Método | Endpoint | Operación |
|---|---|---|
| `GET` | `/api/v1/facturas` | Listar |
| `POST` | `/api/v1/facturas` | Crear |
| `GET` | `/api/v1/facturas/{id}` | Consultar |
| `PUT/PATCH` | `/api/v1/facturas/{id}` | Actualizar |
| `DELETE` | `/api/v1/facturas/{id}` | Eliminar |

Los endpoints de autenticación disponibles son `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `GET /api/v1/auth/me` y `POST /api/v1/auth/logout`.

## Ejemplo

```bash
curl -X POST http://localhost:8000/api/v1/facturas \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "name": "Acme Ecuador",
    "ruc": "1790012345001",
    "email": "billing@acme.test",
    "phone": "+593 2 555 0100",
    "address": "Av. Naciones Unidas 123",
    "city": "Quito",
    "country": "Ecuador",
    "status": "active"
  }'
```

Respuesta `201 Created`:

```json
{
  "data": {
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
}
```

La documentación ampliada está en [`DOCUMENTATION.md`](DOCUMENTATION.md).
