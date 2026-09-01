# Documentación técnica

## Alcance actual

El proyecto proporciona autenticación, frontend Inertia/Vue y persistencia PostgreSQL. Edificios, estructura física, propietarios y finanzas con cargos, pagos, cartera, recibos y evidencias son módulos funcionales. Residentes, gastos y operaciones todavía no están implementados.

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
- Torres, pisos, departamentos, parqueaderos, bodegas y sus historiales de asignación residen en `administracion_edificios`.
- Propietarios, su alcance por edificio y el historial de titularidades residen en `administracion_edificios`.
- Conceptos de cobro, tarifas y sus alcances por departamento residen en `administracion_edificios`.
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
- Cada edificio nuevo recibe una torre principal para soportar edificios sin bloques explícitos.

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

## Estructura física

La estructura física permanece dentro de `src/Edificio` y sigue el flujo `Controller -> Action -> RepositoryInterface -> EloquentRepository`.

Jerarquía y reglas:

- `Edificio -> Torre -> Piso -> Departamento`.
- El departamento se vincula al edificio y al piso; la torre se deriva del piso.
- Las FKs compuestas impiden relacionar elementos de edificios distintos.
- Los códigos son únicos dentro del edificio y se normalizan en mayúsculas.
- La alícuota utiliza seis decimales y acepta valores entre 0 y 100.
- Los estados administrativos son `activo` e `inactivo`; no existe eliminación web.
- Parqueaderos y bodegas pueden asignarse en grupos a un departamento.
- La disponibilidad `disponible/asignado/inactivo` se calcula desde el estado administrativo y la asignación vigente.
- Cambiar anexos cierra el intervalo anterior y conserva el historial.
- PostgreSQL impide intervalos superpuestos para un mismo anexo mediante guards transaccionales.
- Inactivar un departamento cierra sus asignaciones vigentes; la ocupación se modelará en una etapa posterior.

Superficies web principales:

```text
GET    /edificios/{edificio}/estructura
POST   /edificios/{edificio}/torres
POST   /edificios/{edificio}/pisos
POST   /edificios/{edificio}/parqueaderos
POST   /edificios/{edificio}/bodegas
PATCH  /edificios/{edificio}/estructura/{tipo}/{elemento}/estado
GET    /departamentos
GET    /departamentos/create
POST   /edificios/{edificio}/departamentos
GET    /edificios/{edificio}/departamentos/{departamento}/edit
PUT    /edificios/{edificio}/departamentos/{departamento}
PATCH  /edificios/{edificio}/departamentos/{departamento}/estado
```

Las operaciones de actualización de torres, pisos y anexos utilizan rutas `PUT` equivalentes. Todas las superficies requieren sesión y autorización sobre el edificio exacto.

## Módulo Propiedad

El contexto `src/Propiedad` implementa propietarios naturales y jurídicos, directorio multiedificio y titularidad temporal de departamentos. El contexto heredado `Cliente` no se reutiliza porque carece de estados, historial, aislamiento por edificio y copropiedad.

Tablas:

- `propietarios`: identidad, contacto, estado y observaciones.
- `propietario_edificio`: visibilidad administrativa de la identidad.
- `departamento_propietarios`: porcentaje, vigencia, estado y snapshot histórico.

Reglas de negocio:

- La identificación es única dentro de su tipo.
- Personas naturales requieren nombres y apellidos; jurídicas requieren razón social.
- Una asignación requiere propietario y departamento activos.
- Las participaciones pueden ser parciales, pero no superar 100% en ningún período.
- Las transferencias reemplazan el conjunto actual y deben distribuir exactamente 100%.
- Finalizar o transferir cierra las filas anteriores; nunca las elimina.
- PostgreSQL serializa operaciones por departamento y rechaza solapamientos, sobreparticipación, mutación histórica, borrado y titularidades activas de propietarios inactivos.
- La identidad histórica queda congelada dentro de cada titularidad.
- Sólo usuarios asignados al edificio pueden consultar o modificar sus relaciones.
- Una identidad compartida sólo puede editarse si el usuario administra todos sus edificios vinculados.

Rutas principales:

```text
GET    /propietarios
GET    /propietarios/create
POST   /edificios/{edificio}/propietarios
GET    /propietarios/{propietario}
GET    /propietarios/{propietario}/edit
PUT    /propietarios/{propietario}
PATCH  /propietarios/{propietario}/estado
GET    /edificios/{edificio}/departamentos/{departamento}
POST   /edificios/{edificio}/departamentos/{departamento}/propietarios
PATCH  /edificios/{edificio}/departamentos/{departamento}/propietarios/{titularidad}/finalizar
POST   /edificios/{edificio}/departamentos/{departamento}/propietarios/transferir
```

No existen rutas de eliminación para propietarios o titularidades.

## Módulo Finanzas

El contexto `src/Finanzas` configura conceptos, tarifas, cargos, pagos, cartera, recibos y evidencias por edificio. La conciliación bancaria permanece fuera de alcance.

Tablas:

- `conceptos_cobro`: código único por edificio, nombre, tipo, periodicidad, forma de cálculo y estado.
- `tarifas_concepto`: valores `numeric(14,4)`, porcentajes `numeric(9,6)`, configuración de consumo/interés/cuotas extraordinarias y vigencia.
- `tarifa_departamentos`: alcance histórico de tarifas aplicables a departamentos específicos.
- `cargos`: obligación concreta, período mensual, fechas, valor original, saldo, estado, origen y snapshot de cálculo.
- `lotes_generacion_cargos`: auditoría de generación masiva, contadores, total y advertencias.
- `pagos`: valor recibido, número legible, forma de pago, referencia, usuario, estado y snapshot de titulares.
- `aplicaciones_pago`: aplicación inmutable de un pago a uno o varios cargos.
- `consecutivos_pago`: contador técnico bloqueado para asignar números de pago únicos sin usar `MAX() + 1`.
- `pago_titulares`: snapshot relacional inmutable de titulares para historial y filtros de copropiedad, sin repartir el pago.
- `consecutivos_recibo`: contador técnico global bloqueado por año para recibos.
- `recibos_pago`: recibo único, inmutable y trazable de un pago, con snapshots de emisión.
- `evidencias_pago`: archivos privados inmutables adjuntos a un pago.

Reglas de negocio:

- Tipos disponibles: ordinario, extraordinario, consumo, multa, interés y otro.
- Periodicidades: mensual, trimestral, semestral, anual, único y manual.
- Formas de cálculo: valor fijo, por alícuota, porcentaje, por consumo y manual.
- El estado de concepto es `activo` o `inactivo`; el estado de tarifa se deriva de fechas como `programada`, `vigente` o `finalizada`.
- Una nueva tarifa preserva la anterior cerrando su intervalo dentro de la misma transacción.
- PostgreSQL bloquea tarifas solapadas, elimina la edición de una tarifa finalizada, evita el borrado de su historial y protege su alcance por departamento.
- Tipo, periodicidad y forma de cálculo no se modifican cuando el concepto ya tiene tarifas.
- El alcance soporta todo el edificio o departamentos específicos. FKs compuestas y revalidación transaccional rechazan departamentos de otro edificio.
- Los conceptos de consumo requieren unidad y precio por unidad. Los intereses requieren porcentaje y base de cálculo. Las tarifas extraordinarias pueden registrar monto total y número de cuotas.
- Los cargos automáticos son únicos por edificio, departamento, concepto y período; reintentos registran omitidos en un lote y no duplican deuda.
- Valor fijo usa la tarifa; por alícuota usa la base de tarifa y `departamento.alicuota` con BCMath. Consumo sin lectura, porcentaje sin base, concepto manual y valor cero se omiten con advertencia.
- Todo el edificio incluye sólo departamentos activos; el alcance específico respeta `tarifa_departamentos` y sus FKs compuestas.
- El cargo pertenece al departamento. En copropiedad no divide deuda y conserva un snapshot de todos los titulares.
- Edificio no tiene configuración de vencimiento: automático usa el último día del período y manual recibe fecha explícita.
- Anular conserva el cargo, exige motivo y sólo permite saldo íntegro pendiente.
- El pago se registra por departamento; el backend obtiene titulares y cargos, nunca acepta saldos ni propietario desde el frontend.
- La aplicación automática es determinista: fecha de vencimiento, fecha de emisión, creación e identificador. Sólo usa cargos `pendiente` o `parcial` con saldo positivo.
- Un pago puede cubrir varios cargos y un cargo puede recibir varios pagos. Los importes se calculan con BCMath y `numeric(14,4)`.
- El excedente queda como saldo a favor derivado de `monto_recibido - aplicaciones` y puede aplicarse posteriormente, sin sobrescribir el pago original.
- Un pago `registrado` puede anularse una única vez. La anulación conserva su historial, restaura cargos y exige motivo, fecha y usuario en una transacción.
- `registrado` y `anulado` son los únicos estados persistidos; aplicado o parcialmente aplicado se derivan de las aplicaciones activas.
- La cartera es una lectura derivada de cargos, aplicaciones y saldo a favor; no admite edición manual.
- La cartera paginada calcula saldo bruto, vencido, no vencido, saldo a favor y saldo neto en backend. Sus estados son derivados: `al_dia`, `moroso` y `saldo_a_favor`.
- Los buckets de antigüedad son 1–30, 31–60, 61–90 y más de 90 días desde el vencimiento pendiente más antiguo.
- El estado de cuenta de departamento usa saldo inicial anterior al rango y movimientos de cargos, pagos y anulaciones. Las aplicaciones se adjuntan al pago sin duplicar débitos o créditos.
- Registrar un pago emite un único recibo en la misma transacción. Su número es global `REC-AAAA-NNNNNN` y el consecutivo se reinicia únicamente al cambiar de año.
- El recibo guarda snapshots del pago y de su aplicación inicial; una aplicación posterior de saldo a favor no lo altera. Anular el pago anula el recibo con usuario, fecha y motivo, sin eliminar registros.
- Las evidencias son PDF, JPG o PNG de hasta 10 MB, se almacenan en el disco privado no servible `evidence`, validan estructura y SHA-256, sólo se aceptan para pagos registrados y se descargan tras validar acceso al edificio.

Rutas principales:

```text
GET    /conceptos
GET    /conceptos/create
POST   /edificios/{edificio}/conceptos
GET    /edificios/{edificio}/conceptos/{concepto}
GET    /edificios/{edificio}/conceptos/{concepto}/edit
PUT    /edificios/{edificio}/conceptos/{concepto}
PATCH  /edificios/{edificio}/conceptos/{concepto}/estado
POST   /edificios/{edificio}/conceptos/{concepto}/tarifas
GET    /cargos
GET    /cargos/generar
POST   /edificios/{edificio}/cargos/generar
GET    /cargos/create
POST   /edificios/{edificio}/cargos
GET    /edificios/{edificio}/cargos/{cargo}
PATCH  /edificios/{edificio}/cargos/{cargo}/anular
GET    /pagos
GET    /pagos/create
POST   /edificios/{edificio}/pagos
GET    /edificios/{edificio}/pagos/{pago}
POST   /edificios/{edificio}/pagos/{pago}/aplicar-saldo-favor
PATCH  /edificios/{edificio}/pagos/{pago}/anular
GET    /edificios/{edificio}/pagos/{pago}/recibo
POST   /edificios/{edificio}/pagos/{pago}/evidencias
GET    /edificios/{edificio}/pagos/{pago}/evidencias/{evidencia}
GET    /cartera
GET    /edificios/{edificio}/departamentos/{departamento}/estado-cuenta
```

El comando `php artisan finanzas:generar-cargos --dry-run` previsualiza sin crear cargos ni lotes. El scheduler lo ejecuta diariamente a las 01:10 y la idempotencia impide duplicación.

## Módulos transitorios

| Contexto | Estado | Decisión pendiente |
|---|---|---|
| Auth | Reutilizable | Añadir roles, permisos y políticas |
| Cliente | Web y API heredadas activas | Retirar después de inventariar consumidores; no se usa para Propiedad |
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
