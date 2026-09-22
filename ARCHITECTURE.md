# Arquitectura del sistema

## Objetivo

Construir el sistema de administración de edificios por capacidades verificables, reutilizando autenticación e infraestructura existentes y retirando progresivamente los contextos heredados que no correspondan al dominio.

Edificios incluye su estructura física, Propiedad administra identidades compartidas, propietarios, residentes, titularidades y ocupaciones, Finanzas gestiona cuentas por cobrar y `Gastos` administra proveedores, contratos, gastos, cuentas por pagar y desembolsos. Los demás contextos del dominio se incorporarán únicamente cuando tengan un caso de uso ejecutable.

## Decisión multiedificio

El sistema será multiedificio. Una instalación podrá administrar uno o varios edificios y `Edificio` será la raíz del aislamiento administrativo.

El diseño de los primeros casos de uso deberá cumplir estas reglas:

1. Toda entidad dependiente de un edificio tendrá una relación obligatoria mediante `edificio_id`.
2. Las restricciones únicas de datos locales incluirán `edificio_id` cuando corresponda.
3. Un usuario accederá a edificios mediante una asignación explícita; no se confiará en un `edificio_id` recibido sin validarlo contra esa asignación.
4. Roles y permisos administrativos tendrán alcance por edificio. Cualquier rol global será explícito y excepcional.
5. Propietarios y residentes podrán relacionarse con unidades de distintos edificios sin convertirse por ello en roles de autorización.
6. Políticas, consultas, jobs, reportes, documentos y auditoría conservarán el contexto de edificio.
7. Las pruebas deberán demostrar que un usuario asignado a un edificio no puede leer ni modificar información de otro.
8. Las tablas globales, como el catálogo de edificios y la identidad de usuarios, se distinguirán expresamente de las tablas con alcance por edificio.

`edificio_usuario` es la membresía canónica y su revocación es lógica. El creador recibe el rol `administrador`; las policies y consultas validan permisos efectivos, no la mera existencia de una membresía. Las relaciones de propiedad u ocupación no conceden acceso.

## Identidad y acceso por edificio

ETAPA 11 incorpora autorización RBAC con alcance estricto por edificio:

1. Un usuario puede tener varios roles en un edificio y roles diferentes en edificios distintos.
2. Los roles de sistema son `administrador`, `gestor_propiedad`, `gestor_finanzas` y `consulta`.
3. Los 26 permisos se agrupan en edificio, miembros, estructura, propiedad, finanzas, gastos y desembolsos. El permiso efectivo es la unión de los permisos de los roles activos en ese edificio.
4. `administrador` tiene acceso completo; `gestor_propiedad` gestiona estructura y propiedad; `gestor_finanzas` gestiona conceptos, lecturas, cargos, pagos, comprobantes, evidencias, proveedores, gastos y desembolsos; `consulta` sólo dispone de lectura, incluidos `gastos.ver` y `desembolsos.ver`.
5. Sólo `miembros.gestionar` permite invitar, asignar roles o revocar membresías. En la matriz vigente ese permiso pertenece exclusivamente a `administrador`, por lo que un rol limitado no puede elevar sus privilegios.
6. Las invitaciones almacenan únicamente SHA-256 del token, normalizan el correo, vencen en 72 horas y sólo pueden aceptarse por un usuario autenticado con el correo invitado.
7. La aceptación puede reactivar una membresía revocada y reemplaza sus roles anteriores por el rol invitado.
8. Toda creación, invitación, aceptación, cambio de roles y revocación genera un evento inmutable. La base impide eliminar membresías y dejar un edificio sin administrador.
9. Los controllers y Form Requests aplican autorización, mientras los repositorios vuelven a filtrar o validar el edificio. La visibilidad frontend es sólo una ayuda de interfaz y no sustituye estas defensas.
10. Los procesos programados de generación de cargos pueden operar sin actor; ningún flujo HTTP utiliza ese bypass técnico.

## Estructura física

Torres, pisos, departamentos, parqueaderos y bodegas forman parte del contexto `Edificio`; no se separan en contextos vacíos. La jerarquía se protege con claves foráneas compuestas que incluyen `edificio_id`.

Decisiones implementadas:

1. Cada edificio tiene una única torre predeterminada, creada automáticamente, para representar edificios pequeños sin bloques explícitos.
2. Un departamento guarda `edificio_id` y `piso_id`. Su torre se deriva del piso para evitar referencias contradictorias.
3. El código de departamentos, parqueaderos y bodegas es único dentro del edificio.
4. La alícuota usa `numeric(9,6)` y debe estar entre 0 y 100.
5. Parqueaderos y bodegas son entidades independientes. Su disponibilidad se deriva de una asignación temporal vigente, no de un estado duplicado.
6. Las asignaciones conservan `fecha_inicio` y `fecha_fin`; PostgreSQL impide asignaciones vigentes duplicadas e intervalos históricos superpuestos.
7. La inactivación reemplaza la eliminación física. Las FKs usan `RESTRICT` para proteger la jerarquía y el historial.
8. El estado administrativo del departamento no representa ocupación. Propietarios y residentes tienen relaciones temporales independientes, y un departamento ocupado no puede inactivarse hasta finalizar sus ocupaciones.

## Principios

1. Un sustantivo del negocio no implica automáticamente un bounded context.
2. Cada contexto debe partir de un caso de uso ejecutable, invariantes, autorización, persistencia y pruebas.
3. Las tablas nuevas del sistema pertenecen al esquema PostgreSQL `administracion_edificios`.
4. Las migraciones aplicadas no se editan; toda evolución utiliza migraciones nuevas.
5. Los límites de edificio y acceso se definen antes de crear datos administrativos.
6. Propietario y Residente son relaciones del dominio, no roles de autorización.
7. Reportes y auditoría son capacidades transversales hasta que sus casos de uso justifiquen un módulo propio.
8. No se introducen repositorios genéricos, CQRS, eventos o abstracciones base sin una necesidad concreta.

## Propietarios y titularidad

El contexto `Propiedad` separa identidades y perfiles de dominio:

1. `Tercero` es una identidad global natural o jurídica y no es un usuario de autenticación.
2. `Propietario`, `Residente` y `Proveedor` son perfiles de esa identidad; una persona natural puede cumplir varios sin duplicarse.
3. `propietario_edificio` y `residente_edificio` delimitan qué administradores pueden consultar cada perfil.
4. `departamento_propietarios` representa la titularidad temporal, su porcentaje y su historial.
5. `departamento_residentes` representa la ocupación temporal y su historial, sin alterar la titularidad.

Reglas implementadas:

- Una identidad se distingue globalmente por tipo e identificación.
- Un propietario puede vincularse a varios edificios y departamentos sin duplicarse.
- Un departamento admite varios copropietarios.
- La suma de participaciones no puede superar 100% en ningún intervalo histórico.
- Una transferencia completa distribuye exactamente 100%, finaliza todas las titularidades actuales y crea las nuevas dentro de una transacción.
- Los intervalos son semiabiertos: `[fecha_inicio, fecha_fin)`.
- Las titularidades finalizadas son inmutables y no pueden eliminarse.
- PostgreSQL impide inactivar propietarios con titularidades activas y crear titularidades activas de propietarios inactivos.
- Cada titularidad conserva un snapshot del nombre e identificación utilizados al iniciarla.
- Modificar una identidad global exige acceso administrativo a todos los edificios vinculados por Propiedad y Gastos.
- Ser propietario no concede acceso mediante `edificio_usuario` ni crea una cuenta en `users`.

## Residentes y ocupación

ETAPA 10 incorpora residentes dentro de `Propiedad`, porque identidad, titularidad y ocupación deben distinguirse pero diseñarse en conjunto.

- Un residente corresponde siempre a un tercero de tipo persona natural, con identificación global obligatoria.
- La misma identidad puede tener perfiles de propietario y residente. La migración evolutiva enlaza los propietarios históricos a terceros sin cambiar sus identificadores ni titularidades.
- Un residente puede ocupar simultáneamente varios departamentos, incluso en edificios distintos. Un departamento admite varios residentes.
- Las ocupaciones usan intervalos semiabiertos `[fecha_inicio, fecha_fin)` y sólo se impide el solapamiento de la misma pareja residente-departamento.
- Los tipos mínimos son `propietario_ocupante`, `arrendatario` y `otro`. El propietario ocupante requiere una titularidad vigente de la misma identidad al inicio, y un guard diferido impide que cambios posteriores reescriban esa correspondencia histórica.
- Cada ocupación congela nombre e identificación. Finalizarla conserva la fila; las ocupaciones finalizadas y sus datos de origen no se editan ni eliminan.
- Sólo edificio, departamento y residente activos admiten una nueva ocupación. Un residente o departamento con ocupaciones activas no puede inactivarse.
- Residente no es un usuario ni un rol de autorización. Las consultas y cambios siguen delimitados por permisos efectivos del usuario en cada edificio.
- Modificar una identidad compartida exige acceso a todos los edificios vinculados por sus perfiles de propietario y residente.

## Finanzas: conceptos y tarifas

El contexto `Finanzas` configura conceptos y tarifas, genera cargos, registra pagos y emite recibos; la conciliación bancaria permanece fuera de alcance.

1. `conceptos_cobro` es un catálogo local al edificio y define tipo, periodicidad, forma de cálculo y estado administrativo.
2. `tarifas_concepto` guarda valores monetarios, porcentajes y demás parámetros por intervalo temporal; una tarifa no se sobrescribe ni se elimina.
3. `tarifa_departamentos` conserva el alcance histórico cuando una tarifa se aplica a departamentos específicos.

Reglas implementadas:

- Los conceptos usan enums para tipo, periodicidad y forma de cálculo; el código es único dentro de cada edificio.
- Los importes usan `numeric(14,4)` y los porcentajes `numeric(9,6)`; no se usan `float` para persistir o transformar importes.
- Las vigencias son intervalos semiabiertos `[fecha_inicio, fecha_fin)`. `programada`, `vigente` y `finalizada` se derivan de esas fechas y no se almacenan de forma redundante.
- Registrar una tarifa nueva cierra transaccionalmente la única tarifa abierta anterior cuando corresponde; PostgreSQL bloquea solapamientos, edición histórica y eliminación directa.
- Tipo, periodicidad y forma de cálculo no pueden cambiar después de registrar una tarifa. Tipo y forma tampoco cambian después de registrar cargos; el alcance de una tarifa finalizada es inmutable.
- El alcance soportado es `todo_el_edificio` o `departamentos_especificos`. Las FKs compuestas impiden seleccionar unidades de otro edificio.
- La tabla de alcance es independiente de la tarifa, por lo que una extensión posterior puede añadir torre o piso sin reescribir tarifas históricas.
- Consumo requiere forma `por_consumo` y unidad; toda forma porcentual requiere porcentaje y base de cálculo; las cuotas extraordinarias de valor fijo o por alícuota admiten monto total y número de cuotas.

## Finanzas: lecturas, consumos y porcentajes

ETAPA 12 completa las formas variables dentro de `Finanzas`:

- `lecturas_consumo` conserva una secuencia acumulativa por edificio, departamento y concepto. Sólo existe una lectura por período y no se admite edición ni eliminación.
- La primera lectura recibe una línea base. Las siguientes deben usar como anterior la lectura actual más reciente y pertenecer a un período posterior; pueden existir períodos intermedios sin lectura.
- Lectura anterior, lectura actual y unidad se validan en servidor. `consumo = lectura_actual - lectura_anterior` se calcula con BCMath y no puede ser negativo.
- Una lectura sólo admite departamentos activos y conceptos activos de tipo consumo con forma `por_consumo`, tarifa vigente y alcance aplicable al departamento.
- `lecturas.registrar` pertenece a `administrador` y `gestor_finanzas`; la consulta del historial reutiliza `finanzas.ver`.
- Las FKs compuestas incluyen `edificio_id`, de modo que departamento, concepto, lectura y cargo no pueden cruzar edificios.
- El cargo por consumo multiplica el consumo por el precio unitario vigente y conserva la referencia de la lectura y un snapshot de sus valores, unidad y tarifa.
- Las bases porcentuales se reconstruyen al inicio del período con cargos vigentes en ese momento y aplicaciones de pagos creadas antes del corte. `saldo_vencido` incluye deuda vencida; `capital_vencido` excluye conceptos de interés; `saldo_total` incluye toda deuda emitida antes del corte. El saldo a favor no reduce estas bases.
- El porcentaje se aplica con BCMath y se redondea a cuatro decimales. El cargo congela base, tipo de base, porcentaje y fecha de corte.

## Finanzas: cargos y lotes

ETAPA 6 convierte configuraciones vigentes en cargos de departamento. ETAPA 12 completa los cálculos por consumo y porcentaje. ETAPA 7 aplica pagos sobre esos cargos y deriva cartera sin tablas editables de deuda.

- `cargos` guarda una obligación concreta con saldo persistente, estados `pendiente`, `parcial`, `pagado` y `anulado`, y un snapshot de cálculo.
- `lotes_generacion_cargos` audita cada ejecución, sus totales, omisiones y advertencias.
- El período usa un `date` normalizado al primer día del mes y se presenta como `YYYY-MM`.
- Edificio no dispone de fecha de corte o vencimiento. La generación automática emite el primer día del período y vence el último; el cargo manual permite override explícito.
- Los cargos automáticos son idempotentes por edificio, departamento, concepto y período mediante transacción e índice parcial PostgreSQL.
- Valor fijo, por alícuota, por consumo y porcentual se calculan con BCMath y `numeric`; una lectura ausente, una configuración porcentual incompleta y un valor cero se omiten con advertencia.
- El cargo pertenece al departamento. Guarda propietario sólo si existe un titular único y congela titulares, tarifa, alícuota, lectura, base porcentual y parámetros en metadata.
- La anulación no elimina, exige motivo y sólo admite saldos íntegros pendientes.
- `finanzas:generar-cargos --periodo=YYYY-MM [--edificio=UUID] [--concepto=UUID] [--dry-run]` se ejecuta diariamente a las 01:10 con `withoutOverlapping`.

## Finanzas: pagos y cartera

- `pagos` conserva el valor recibido, departamento, snapshot de titulares, forma de pago, referencia, usuario y número global legible `PAG-AAAA-NNNNNN`.
- `aplicaciones_pago` es inmutable y relaciona un pago con uno o varios cargos. Un cargo admite aplicaciones de varios pagos.
- Sólo se almacenan los estados `registrado` y `anulado`. Aplicado, parcialmente aplicado y saldo a favor se derivan de las aplicaciones activas, evitando estados duplicados.
- El registro y la aplicación usan una única transacción y bloquean edificio, departamento y cargos ordenados por vencimiento, emisión, creación e identificador.
- Los pagos se aplican automáticamente a la deuda más antigua. El saldo no aplicado permanece como saldo a favor en el mismo pago y puede aplicarse después a nuevos cargos del departamento.
- La anulación no elimina pagos ni aplicaciones: revierte los saldos y estados de los cargos, marca el pago anulado con motivo, usuario y fecha, y no puede repetirse.
- PostgreSQL protege pagos y aplicaciones contra eliminación o edición destructiva, valida montos positivos, FKs compuestas entre edificio/departamento/cargo/pago y comprueba al commit la coherencia entre saldos de cargos y aplicaciones activas.
- La cartera es una consulta derivada: saldo bruto de cargos no anulados, aplicaciones, saldo a favor y saldo neto. No existe una tabla de cartera editable.
- El pago pertenece al departamento; un propietario único queda referenciado y todos los titulares vigentes quedan en el snapshot. La copropiedad no divide el pago.
- Cada pago registrado emite dentro de la misma transacción un único recibo inmutable, con consecutivo global por año. El recibo congela edificio, departamento, titulares, valor, forma, referencia y aplicaciones iniciales.
- Anular un pago anula su recibo con el mismo motivo, usuario y fecha; ninguno se elimina ni se reemite. Las aplicaciones posteriores de saldo a favor no reescriben el snapshot del recibo.
- Las evidencias de pago son archivos privados PDF, JPG o PNG de hasta 10 MB. Se adjuntan sólo a pagos registrados, se validan por contenido, guardan SHA-256, no se editan ni eliminan y su descarga conserva la autorización del edificio.

## Finanzas: cartera y estado de cuenta

- ETAPA 8 usa un read repository reconstruible desde cargos, pagos, aplicaciones y titulares; no existe una tabla editable de cartera ni saldos materializados.
- Saldo bruto es la suma de `cargos.saldo` no anulados. Saldo vencido considera únicamente esos saldos con vencimiento anterior a la fecha de consulta; saldo no vencido es la diferencia.
- Saldo a favor es el remanente de pagos registrados sin aplicación. Saldo neto se presenta firmado y también separado entre posición deudora y acreedora para no compensar departamentos distintos.
- `al_dia` significa saldo vencido cero, incluso con cargos futuros. `moroso` requiere saldo vencido positivo. La antigüedad se deriva del vencimiento pendiente más antiguo; no genera intereses.
- Los KPIs se calculan antes de paginar. Morosidad es departamentos con saldo vencido dividido por departamentos incluidos en el filtro.
- El estado de cuenta es cronológico y contable: cargo es débito, pago es crédito y cada anulación revierte su documento. Las aplicaciones se muestran como trazabilidad del pago y no se suman otra vez.

## Gastos, proveedores y desembolsos

ETAPA 13 incorpora el contexto `Gastos` para administrar egresos comprometidos:

- `proveedores` enlaza un único perfil global con `terceros`; `proveedor_edificio` conserva estado y condiciones comerciales independientes por edificio.
- `contratos_proveedor` y `gastos` usan el ciclo `borrador -> registrado -> anulado`. Sólo el borrador es editable y ninguna entidad dispone de eliminación física.
- Registrar congela snapshots del proveedor y del contrato opcional. Los documentos registrados permanecen inmutables salvo su anulación trazable con usuario, fecha y motivo.
- Cada gasto registrado recibe un consecutivo global anual `GAS-AAAA-NNNNNN`, asignado mediante un contador bloqueado dentro de la transacción.
- Un gasto de contado queda pagado al registrarse y no genera una entidad de pago. Un gasto a crédito exige vencimiento y crea exactamente una `cuenta_por_pagar` pendiente por el monto completo.
- Anular el gasto anula también su cuenta por pagar, conserva saldo y monto histórico, y no crea movimientos de desembolso.
- FKs compuestas entre edificio, proveedor, contrato, gasto y cuenta por pagar impiden relaciones cruzadas. Repositorios y requests vuelven a validar el permiso y el edificio exactos.
- Los permisos son `gastos.ver`, `gastos.gestionar` y `gastos.anular`. Administrador y gestor financiero reciben los tres; consulta sólo lectura; gestor de propiedad ninguno.
- La edición de una identidad global compartida exige permisos de gestión sobre todos los edificios vinculados por perfiles de propietario, residente y proveedor.
- PostgreSQL protege importes, fechas, transiciones, snapshots, inmutabilidad y correspondencia diferida entre gasto y cuenta por pagar. SQLite conserva guards equivalentes para la suite donde la comprobación diferida no es viable.

ETAPA 14 completa el pago de obligaciones mediante desembolsos sin incorporar conciliación bancaria:

- Un desembolso pertenece a un edificio y un proveedor, se registra inmediatamente y recibe el consecutivo global anual `DES-AAAA-NNNNNN`.
- El monto se distribuye automáticamente entre las cuentas abiertas del proveedor por vencimiento, creación e identificador. Admite liquidaciones parciales y múltiples cuentas, pero se rechazan sobrepagos y anticipos.
- La previsualización congela un fingerprint del plan. La transacción vuelve a bloquear y calcular las cuentas; si cambió el orden o algún saldo, exige previsualizar otra vez.
- `aplicaciones_desembolso` es historial inmutable. El saldo de la cuenta y el estado de pago del gasto corresponden a la suma de aplicaciones cuyos desembolsos siguen registrados.
- Pendiente, parcial y pagada son lecturas derivadas de saldo y monto original. La columna histórica de la cuenta conserva únicamente vigencia `pendiente` o `anulada`.
- Anular un desembolso conserva todas sus aplicaciones, restaura los saldos una sola vez y recalcula cada gasto. Un gasto no puede anularse mientras conserve desembolsos activos.
- Los gastos de contado mantienen el comportamiento de ETAPA 13: quedan pagados al registrarse y no generan desembolso.
- Los permisos son `desembolsos.ver`, `desembolsos.registrar` y `desembolsos.anular`. Administrador y gestor financiero reciben los tres; consulta sólo lectura; gestor de propiedad ninguno.
- Las FKs compuestas incluyen edificio y proveedor; repositorios, requests y filtros revalidan el permiso y edificio exactos. PostgreSQL comprueba de forma diferida monto aplicado, saldos y correspondencia con el gasto.
- ETAPA 14 conserva forma de pago, referencia, actor, proveedor snapshot y anulación. Anticipos, cuentas bancarias, adjuntos, flujo de aprobación y conciliación permanecen fuera de alcance.

## Capacidades previstas

| Área | Conceptos que deben diseñarse en conjunto |
|---|---|
| Propiedad y ocupación | Edificios, estructura física, alícuotas, identidades compartidas, propietarios, residentes e historial de ocupación implementados |
| Identidad y acceso | Usuarios, roles múltiples, permisos, invitaciones y auditoría por edificio implementados |
| Cuentas por cobrar | Conceptos, tarifas, lecturas, consumos, cálculos porcentuales, cargos, pagos, recibos, evidencias, saldo a favor y cartera implementados |
| Gastos y proveedores | Proveedores, contratos, gastos, cuentas por pagar y desembolsos implementados; conciliación bancaria fuera de alcance |
| Operaciones | Mantenimiento, incidencias y solicitudes |
| Áreas comunes | Espacios, reglas y reservas |
| Comunicación | Comunicados, documentos y notificaciones |
| Gobierno | Reportes, trazabilidad y auditoría |

## Decisiones pendientes

- Definir si la suma de alícuotas debe exigirse en 100% para distribuir sin diferencias de redondeo.
- Definir requerimientos mínimos de auditoría y conservación documental.

## Convención de contexto

```text
src/<Context>/
  Application/
    Actions/
    Controllers/
  Domain/
    Contracts/
    Entities/
    Exceptions/
  Infrastructure/
    Mappers/
    Migrations/
    Models/
    Repositories/
    Requests/
    Resources/
  api.php             Opcional
  web.php             Opcional
```

No todas las carpetas son obligatorias. Deben existir sólo cuando el primer caso de uso las necesita.

La activación de un contexto requiere:

1. Añadirlo a `BoundedContextServiceProvider::BOUNDED_CONTEXTS`.
2. Registrar bindings sólo si existen contratos reales.
3. Incorporar rutas sólo para superficies implementadas.
4. Crear migraciones nuevas dentro del contexto o en `database/migrations` si son transversales.
5. Añadir pruebas de autorización, persistencia y comportamiento.

## Transición desde los contextos heredados

Auth se conserva como base reutilizable. Cliente, Categoria, Producto y Factura no se usarán como modelo implícito para el nuevo dominio.

Un contexto heredado sólo puede retirarse después de comprobar:

- Rutas y consumidores activos.
- Claves foráneas y datos que deban conservarse.
- Pruebas y documentación asociadas.
- Sustitución funcional, si corresponde.
- Migración o archivo de datos aprobado.

## Esquema PostgreSQL

El esquema privado es `administracion_edificios`. `public` permanece temporalmente en el `search_path` para resolver tablas históricas, mientras `public.migrations` conserva el historial aplicado.

Los módulos nuevos deben evitar nombres que dupliquen tablas heredadas en `public`. Las tablas de Edificio, acceso (`roles`, `permisos`, asignaciones, invitaciones y eventos), Propiedad, Finanzas y Gastos pertenecen al esquema privado. Cualquier modificación posterior de una tabla heredada debe considerar su esquema de forma explícita.

El nombre configurado en `DB_SCHEMA` es persistente después de ejecutar la migración que crea el esquema. Cambiarlo exige una migración y un despliegue controlados.
