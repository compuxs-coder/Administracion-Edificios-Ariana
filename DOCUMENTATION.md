# Documentación técnica

## Alcance actual

El proyecto proporciona la base de Laravel, autenticación, frontend Inertia/Vue y persistencia PostgreSQL. Edificios es el primer módulo funcional. Unidades, propietarios, residentes, cuotas, pagos, gastos y operaciones todavía no están implementados.

La arquitectura objetivo es multiedificio. Una misma instalación deberá administrar uno o varios edificios, con consultas y permisos delimitados por edificio.

Los contextos Cliente, Categoria, Producto y Factura proceden de la aplicación anterior. Se mantienen sólo cuando aún tienen rutas, pruebas o dependencias activas y no representan el modelo definitivo del sistema.

## Organización

```text
app/                 Integración Laravel, middleware y providers
bootstrap/           Arranque de Laravel
config/              Configuración versionada sin credenciales
database/            Migraciones transversales, factories y seeders
resources/js/        Aplicación Vue/Inertia
routes/              Rutas generales
src/<Context>/       Contextos funcionales
tests/               Pruebas automatizadas
```

Los contextos pueden contener:

```text
Application/         Casos de uso y controladores
Domain/              Entidades, contratos y excepciones
Infrastructure/      Eloquent, repositorios, requests, recursos y migraciones
api.php              Rutas API opcionales
web.php              Rutas web opcionales
```

`BoundedContextServiceProvider` mantiene una lista explícita de contextos activos. Esta decisión evita cargar accidentalmente rutas o migraciones por la sola existencia de un directorio.

## Rutas

Las rutas generales se encuentran en `routes/`. Las rutas de cada contexto se cargan desde `src/<Context>/api.php` y `src/<Context>/web.php`.

Las rutas API de contexto se publican bajo `/api/v1` y utilizan el middleware `api`. Cada contexto decide si requiere `auth:sanctum`.

## Autenticación

La aplicación conserva dos flujos:

- Web: sesión Laravel, protección CSRF y regeneración de sesión.
- API: tokens personales de Laravel Sanctum.

Auth y usuarios son infraestructura reutilizable. Edificios aplica una policy basada en la asignación explícita `edificio_usuario`; los roles y permisos detallados por edificio continúan pendientes.

## PostgreSQL y esquema privado

El esquema de aplicación se configura mediante `DB_SCHEMA` y por defecto es `administracion_edificios`.

La migración `2026_08_20_000000_create_administracion_edificios_schema.php` crea el esquema únicamente en PostgreSQL. En SQLite no ejecuta DDL específico, por lo que la suite puede continuar usando una base en memoria.

Durante la transición:

- El `search_path` es `administracion_edificios,public`.
- Las tablas históricas permanecen en `public` y siguen siendo accesibles como fallback.
- Las tablas nuevas sin calificar se crearán en `administracion_edificios`.
- El repositorio de migraciones continúa en `public.migrations`.
- `edificios` y `edificio_usuario` residen en `administracion_edificios`.
- No se movieron ni duplicaron datos históricos.

`DB_SCHEMA` debe conservar el mismo valor después de aplicar la migración. Un cambio posterior requiere una migración nueva que cree y verifique el nuevo esquema. Los comandos de migración deben usar PostgreSQL como conexión predeterminada; no debe alternarse el driver con `--database`, porque Laravel utiliza un único nombre global para el repositorio de migraciones.

No debe cambiarse el `search_path` sin conservar la calificación de `public.migrations`. Laravel consulta el esquema actual al comprobar la existencia del repositorio y podría considerar pendientes todas las migraciones históricas.

No use `migrate:fresh`, `migrate:reset` ni `migrate:refresh` para esta transición. Pueden eliminar tablas de ambos esquemas y recrear las tablas históricas en una ubicación diferente. Tampoco debe ejecutarse un rollback que atraviese la migración que crea el esquema privado.

## Módulo Edificios

El contexto `src/Edificio` implementa entidad, estado de dominio, repositorio, mapper, casos de uso, policy, requests, controlador web y migración.

Reglas actuales:

- El creador queda asignado al edificio dentro de la misma transacción.
- El listado sólo devuelve edificios asignados al usuario autenticado.
- La consulta, actualización y modificación de estado requieren una asignación existente.
- La interfaz no acepta eliminación física; utiliza los estados `activo` e `inactivo`.
- El RUC es opcional, pero no puede repetirse cuando está informado.
- La asignación referencia `public.users` mientras Auth permanezca como infraestructura heredada reutilizable.

Rutas web:

```text
GET    /edificios
GET    /edificios/create
POST   /edificios
GET    /edificios/{edificio}
GET    /edificios/{edificio}/edit
PUT    /edificios/{edificio}
PATCH  /edificios/{edificio}/estado
```

## Módulos transitorios

| Contexto | Estado | Decisión pendiente |
|---|---|---|
| Auth | Reutilizable | Añadir roles, permisos y políticas |
| Cliente | Web y API activas | Rediseñar como personas y relaciones con unidades |
| Categoria | API activa | Retirar o adaptar cuando se defina el catálogo real |
| Producto | API activa | Retirar o adaptar cuando se definan servicios y conceptos |
| Factura | API heredada activa | Sustituir por comprobantes correctamente modelados |

Las páginas Vue de Factura incompatibles con su API y los dashboards de demostración fueron retirados. El backend heredado permanece porque todavía tiene rutas y pruebas funcionales.

## Verificaciones

```bash
composer validate
php artisan about
php artisan route:list
php artisan migrate:status
php artisan test
npm run build
```

Para ejecutar pruebas con base de datos, PHP CLI debe tener disponible `pdo_sqlite`.

## Restricciones de mantenimiento

- No versionar `.env` ni credenciales.
- No editar migraciones históricas aplicadas.
- Usar migraciones nuevas para cambios estructurales.
- No retirar contextos activos sin resolver primero rutas, datos y dependencias.
- No generar datos demostrativos en el seeder principal.
- No presentar métricas simuladas como información administrativa.
