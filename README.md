# Administración de Edificios Ariana

Sistema multiedificio en desarrollo. Una instalación puede administrar uno o varios edificios con aislamiento de datos y acceso explícito por usuario.

## Estado actual

- Autenticación web mediante sesiones Laravel.
- Autenticación API mediante Laravel Sanctum.
- CRUD web de Edificios con búsqueda, detalle, estado y asignación automática del creador.
- Aislamiento de Edificios mediante membresías, roles múltiples y 53 permisos con alcance por edificio.
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
- Tipos ordinarios, extraordinarios, consumo, multa e interés con montos `numeric` y cálculos por alícuota, consumo o porcentaje.
- Lecturas acumulativas e inmutables por departamento, concepto y período, con continuidad histórica y consumo calculado en servidor.
- Generación y previsualización de cargos por período, lotes auditables, idempotencia, snapshots y anulación sin eliminación física.
- Registro de pagos con aplicación automática por antigüedad, pagos parciales, saldo a favor reutilizable, anulación trazable, recibos inmutables con consecutivo global anual y evidencias privadas PDF/JPG/PNG.
- Cartera paginada y estados de cuenta reconstruibles con saldo vencido/no vencido, antigüedad, KPIs y movimientos financieros cronológicos.
- Directorio global de proveedores con perfiles comerciales y estados independientes por edificio.
- Contratos y gastos con flujo borrador, registro, snapshots inmutables y anulación trazable sin eliminación física.
- Gastos de contado marcados como pagados y gastos a crédito con una única cuenta por pagar generada transaccionalmente.
- Desembolsos a proveedores con aplicación automática por vencimiento, pagos parciales, reversión trazable y saldos derivados de aplicaciones inmutables.
- Cuentas por pagar paginadas con estados pendiente, parcial, pagada y anulada; anticipos y adjuntos permanecen fuera de alcance.
- Tesorería por edificio con cuentas bancarias o cajas, movimientos manuales y conciliación uno-a-uno de egresos contra desembolsos registrados.
- Conciliaciones reversibles con historial inmutable, estado derivado y bloqueo de anulaciones mientras la relación permanezca vigente.
- Órdenes operativas para incidencias y solicitudes, con flujo trazable, responsables históricos, evidencias privadas y bitácora inmutable.
- Planes de mantenimiento preventivo con recurrencia anclada, ocurrencias auditables y generación programada de órdenes por actor sistema.
- Áreas comunes por edificio con reglas versionadas, horarios, aforo, buffers, aprobación configurable y reservas administrativas para ocupantes vigentes.
- Fechas de reservas interpretadas en la zona IANA congelada por regla, instantes almacenados en UTC y rechazo de horas locales ambiguas o inexistentes por cambios DST.
- Comunicados por edificio con borradores versionados, publicación inmutable, audiencia vigente de propietarios y residentes, documentos privados y entregas individuales por correo mediante outbox durable.
- Centro de Auditoría Unificado de sólo lectura sobre cinco bitácoras nativas, con selección multiedificio autorizada, referencias opacas y minimización de datos sensibles.
- Exportaciones CSV/PDF asíncronas del Centro de Auditoría, con alcance inmutable, archivos privados verificados, vencimiento y limpieza durable.
- Dashboard sin métricas simuladas.
- Contextos heredados Cliente, Categoria, Producto y Factura conservados sólo para preservar migraciones y datos; sus rutas HTTP están deshabilitadas.
- Tablas nuevas de Edificios, acceso, Propiedad, Finanzas, Gastos, Tesorería, Operaciones, Áreas comunes y Comunicación dentro del esquema PostgreSQL privado; Gobierno reutiliza sus bitácoras sin duplicarlas y sólo persiste el ciclo operativo de sus exportaciones.

La arquitectura objetivo y las reglas para incorporar módulos están en [`ARCHITECTURE.md`](ARCHITECTURE.md). La referencia técnica del estado actual está en [`DOCUMENTATION.md`](DOCUMENTATION.md).

## ETAPA 16 implementada

**Operaciones: incidencias, solicitudes y mantenimiento correctivo** parte de una única orden operativa, de tipo incidencia o solicitud, con número `OPR-AAAA-NNNNNN`, título y descripción. Comprende registro administrativo, ubicación, prioridad, fecha objetivo manual opcional, responsable interno o proveedor, flujo trazable, evidencias privadas y bitácora append-only.

La etapa incorpora el rol `gestor_operaciones` y permisos separados para consulta, gestión, cambio de estado, asignación, cancelación y reapertura. No incluye autoservicio de residentes, mantenimiento preventivo, reservas de áreas comunes, chat, SLA, escalamiento automático ni generación de documentos financieros.

El módulo dispone de páginas Inertia para listado, creación, edición y detalle; rutas de transición, asignación, cancelación, reapertura, actuaciones y evidencias; y garantías equivalentes en PostgreSQL y SQLite para aislamiento, estados e historia.

## ETAPA 17 implementada

**Mantenimiento preventivo y programación recurrente** amplía `Operaciones` con planes por edificio, código normalizado único, ubicación, prioridad, proveedor y contrato opcionales, anticipación y recurrencia diaria, semanal, mensual o anual. Los planes nacen inactivos; su activación exige una primera fecha y su configuración sólo puede editarse mientras estén inactivos.

Cada vencimiento crea una ocurrencia idempotente y una orden `mantenimiento_preventivo` en estado `reportada`, con origen `programacion_preventiva` y actor `sistema`. Una dependencia inválida bloquea la ocurrencia sin avanzar el calendario; puede reintentarse después de corregirla u omitirse con motivo. Pausar omite la ocurrencia abierta y reactivar inicia un calendario nuevo, sin recuperar pausas deliberadas.

El comando `php artisan operaciones:generar-mantenimiento-preventivo` admite edificio, fecha operativa, límite de lote y `--dry-run`. El scheduler lo ejecuta diariamente a las `02:00` en la zona horaria de la aplicación, con exclusión mutua y ejecución en un solo servidor. ETAPA 17 no incorpora catálogo de activos, inventario, repuestos ni genera gastos, cuentas por pagar, desembolsos o movimientos de tesorería.

## ETAPA 18 implementada

**Áreas comunes y reservas** incorpora el contexto `AreasComunes` para administrar espacios compartidos, reglas históricas, horarios y reservas por edificio. Una reserva se registra para un residente con ocupación vigente, valida horario, duración, antelación, aforo y buffers, y queda confirmada o pendiente según la regla aplicable. La aprobación revalida la ocupación y su finalización queda bloqueada mientras existan reservas vigentes posteriores.

Cada edificio usa una zona IANA. La zona queda congelada en la regla y en el snapshot de la reserva, los instantes se almacenan en UTC, los filtros respetan el día local y se rechazan horas inexistentes o repetidas por cambios DST. Las confirmaciones se serializan por espacio para impedir solapamientos concurrentes y la bitácora es append-only.

La etapa añade el rol `gestor_areas_comunes` y seis permisos específicos. No incluye autoservicio de residentes, cobros, cargos, notificaciones ni integración financiera.

## ETAPA 19 implementada

**Comunicación y notificaciones** incorpora el contexto `Comunicacion` para preparar comunicados por edificio, adjuntar documentos privados y publicarlos por correo a propietarios vigentes, residentes vigentes o ambos. La audiencia se calcula con el día local del edificio, normaliza y deduplica correos, conserva snapshots por identidad y registra explícitamente destinatarios omitidos por correo ausente o inválido.

Publicar es una transición irreversible: congela contenido, documentos y destinatarios dentro de una transacción y crea una entrega durable por dirección normalizada. Los workers reclaman entregas mediante lease, recuperan procesos vencidos y realizan hasta tres intentos con backoff; un fallo terminal puede reintentarse manualmente. La semántica es al menos una vez, por lo que un fallo después de que el proveedor acepte el correo puede producir un envío duplicado.

Los documentos PDF/JPG/PNG admiten hasta 10 MB por archivo y 10 MB acumulados, validan estructura y SHA-256 y permanecen fuera del directorio público. El correo entrega enlaces firmados temporales vinculados a la entrega. La etapa añade el rol `gestor_comunicacion` y los permisos `comunicados.ver`, `comunicados.gestionar`, `comunicados.publicar` y `comunicados.entregas.ver`; este último protege correos, omisiones y errores de entrega.

Producción debe ejecutar un worker de cola y el scheduler de Laravel. El scheduler recupera cada minuto las entregas disponibles o con lease vencido:

```bash
php artisan queue:work --tries=3
php artisan schedule:work
```

ETAPA 19 no incluye portal de residentes, opt-out, respuestas, chat, SMS, WhatsApp, push, campañas programadas, segmentación por torre/piso/departamento ni automatizaciones desde otros módulos.

## ETAPA 20 implementada

**Gobierno y auditoría administrativa** incorpora un Centro de Auditoría Unificado que pagina eventos nativos de acceso, operaciones, mantenimiento preventivo, áreas comunes y comunicación. Cada fuente conserva su tabla canónica y su permiso de lectura; la consulta no crea eventos, no copia historiales y rechaza cualquier selección que incluya un edificio no autorizado.

La etapa añade `gestor_gobierno`, `gobierno.ver` y `gobierno.detalles.ver`. `consulta` no recibe acceso por defecto. El frontend nunca obtiene el JSON histórico ni UUID internos; actores y referencias opacas requieren el permiso de detalle, mientras correos, identificaciones, destinatarios, cuerpos, descripciones e importes permanecen fuera de la respuesta.

La retención de eventos puede declararse por fuente y tipo, pero no elimina bitácoras nativas. Los documentos financieros derivados, la auditoría legal y los reportes programados quedan fuera.

## ETAPA 20.1 implementada

**Exportaciones seguras del Centro de Auditoría** añade el permiso `gobierno.exportar` para `administrador` y `gestor_gobierno`. Una solicitud exige el permiso en todos los edificios, congela nombres, zonas y permisos fuente y limita el rango UTC a 90 días con precisión de segundos. El job procesa todos los chunks desde un único snapshot transaccional y usa cursor keyset; la descarga vuelve a exigir Gobierno, cada permiso fuente capturado y el permiso de detalle cuando correspondía, bajo el mismo protocolo de bloqueo que las mutaciones RBAC.

CSV admite hasta 50.000 filas y neutraliza fórmulas; PDF admite hasta 2.000 eventos y usa Dompdf sin red, JavaScript, PHP ni HTML libre. Los archivos se guardan en `audit-exports`, nunca se sirven directamente, se verifican por tamaño y SHA-256 y expiran como máximo en 24 horas. Leases mayores al timeout del worker, temporales privados con limpieza por antigüedad, intents durables y bitácora append-only permiten recuperar procesos interrumpidos sin publicar rutas internas.

Producción debe ejecutar el worker general, el worker dedicado y el scheduler:

```bash
php artisan queue:work --tries=3
php artisan queue:work database-exports --queue=gobierno-exportaciones --tries=5 --timeout=600
php artisan schedule:work
```

## Tecnologías

- PHP 8.2 o superior y Laravel 12.
- PostgreSQL.
- Vue 3, Inertia.js, Nuxt UI y Tailwind CSS.
- Vite y npm.
- Laravel Sanctum.
- PHPUnit.

## Requisitos

- PHP 8.2 o superior con las extensiones requeridas por Laravel, PostgreSQL y SQLite para pruebas.
- PHP web y cualquier proxy frontal configurados con límites de carga de al menos 12 MB y cuerpo de al menos 13 MB para admitir evidencias de 10 MB. Cada orden admite hasta 100 evidencias y 500 MB acumulados; las cargas y descargas están limitadas por usuario.
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
