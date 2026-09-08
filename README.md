# Administración de Edificios Ariana

Sistema multiedificio en desarrollo. Una instalación puede administrar uno o varios edificios con aislamiento de datos y acceso explícito por usuario.

## Estado actual

- Autenticación web mediante sesiones Laravel.
- Autenticación API mediante Laravel Sanctum.
- CRUD web de Edificios con búsqueda, detalle, estado y asignación automática del creador.
- Aislamiento de Edificios mediante membresías, roles múltiples y 19 permisos con alcance por edificio.
- Administración de miembros, invitaciones por correo con vencimiento, revocación lógica e historial inmutable de cambios de acceso.
- Estructura `Edificio -> Torre -> Piso -> Departamento`, con torre principal automática para edificios pequeños.
- CRUD web de torres, pisos, departamentos, parqueaderos y bodegas, sin eliminación física.
- Alícuotas con seis decimales, filtros de departamentos e historial temporal de anexos.
- Disponibilidad de parqueaderos y bodegas derivada de sus asignaciones vigentes.
- Gestión de propietarios naturales y jurídicos con búsqueda, filtros, detalle y estado.
- Copropiedad por porcentajes, historial inmutable y transferencias atómicas de departamentos.
- Aislamiento de propietarios por edificios administrados, sin convertirlos en usuarios o roles.
- Identidad global compartida para propietarios y residentes, sin duplicar personas ni acoplarlas a autenticación.
- Directorio de residentes y ocupación histórica por departamento con múltiples ocupantes, vigencias, snapshots e inmutabilidad.
- Configuración financiera de conceptos de cobro, tarifas históricas, vigencias y alcance por edificio o departamento.
- Tipos ordinarios, extraordinarios, consumo, multa e interés con montos `numeric`, porcentajes y cálculo por alícuota.
- Generación y previsualización de cargos por período, lotes auditables, idempotencia, snapshots y anulación sin eliminación física.
- Registro de pagos con aplicación automática por antigüedad, pagos parciales, saldo a favor reutilizable, anulación trazable, recibos inmutables con consecutivo global anual y evidencias privadas PDF/JPG/PNG.
- Cartera paginada y estados de cuenta reconstruibles con saldo vencido/no vencido, antigüedad, KPIs y movimientos financieros cronológicos.
- Dashboard sin métricas simuladas.
- CRUD web y API de Cliente, conservado temporalmente sólo como módulo heredado.
- APIs heredadas de Categoria, Producto y Factura, pendientes de retiro o rediseño.
- Tablas nuevas de Edificios, acceso, Propiedad y Finanzas dentro del esquema PostgreSQL privado.

La arquitectura objetivo y las reglas para incorporar módulos están en [`ARCHITECTURE.md`](ARCHITECTURE.md). La referencia técnica del estado actual está en [`DOCUMENTATION.md`](DOCUMENTATION.md).

## Tecnologías

- PHP 8.2 o superior y Laravel 12.
- PostgreSQL.
- Vue 3, Inertia.js, Nuxt UI y Tailwind CSS.
- Vite y npm.
- Laravel Sanctum.
- PHPUnit.

## Requisitos

- PHP 8.2 o superior con las extensiones requeridas por Laravel, PostgreSQL y SQLite para pruebas.
- Composer.
- PostgreSQL.
- Node.js 22.19 o superior.
- npm 11.

## Instalación

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

Configure las credenciales únicamente en `.env`. Este archivo está ignorado por Git y no debe versionarse.

Para desarrollo local:

```bash
composer run dev
```

## PostgreSQL

`DB_SCHEMA` define el esquema privado para los módulos nuevos y utiliza `administracion_edificios` como valor predeterminado. Durante la transición, el `search_path` es:

```text
administracion_edificios,public
```

Las tablas heredadas continúan temporalmente en `public`. La tabla `public.migrations` permanece calificada explícitamente para evitar que Laravel repita las migraciones históricas cuando el esquema privado pasa a ser el esquema actual.

Después de aplicar la migración del esquema, `DB_SCHEMA` se considera una decisión persistente. Cambiarlo requiere una migración nueva y una transición controlada; no basta con editar la variable. Los comandos de migración deben ejecutarse con la conexión PostgreSQL como conexión predeterminada, no alternándola mediante `--database`.

No use `migrate:fresh`, `migrate:reset`, `migrate:refresh` ni rollback que atraviese la migración del esquema sobre una base con datos que deban conservarse.

## Verificación

```bash
composer validate
php artisan about
php artisan route:list
php artisan migrate:status
php artisan test
npm run build
```

Las pruebas utilizan SQLite en memoria y requieren `pdo_sqlite` en PHP CLI.

## Desarrollo de módulos

No cree contextos vacíos para cada concepto del negocio. Un contexto se registra cuando tiene un primer caso de uso ejecutable, reglas de autorización, persistencia definida y pruebas.

El comando `make:ddd` crea la estructura base y sólo genera archivos de rutas cuando se solicita explícitamente:

```bash
php artisan make:ddd Edificio --api --web
```
