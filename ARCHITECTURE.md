# Arquitectura del sistema

## Objetivo

Construir el sistema de administración de edificios por capacidades verificables, reutilizando autenticación e infraestructura existentes y retirando progresivamente los contextos heredados que no correspondan al dominio.

Edificios incluye su estructura física, Propiedad administra identidades de propietarios y titularidades, y Finanzas configura conceptos y tarifas. Los demás contextos del dominio se incorporarán únicamente cuando tengan un caso de uso ejecutable.

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

La asignación inicial se implementa mediante `edificio_usuario`. El creador recibe acceso al edificio y las policies validan esa asignación. Roles detallados y relaciones de ocupación se definirán en las siguientes etapas.

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
8. El estado administrativo del departamento no representa ocupación. Los propietarios tienen vigencia propia; residentes se incorporarán posteriormente.

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

El contexto `Propiedad` separa tres conceptos:

1. `Propietario` es una identidad global natural o jurídica y no es un usuario de autenticación.
2. `propietario_edificio` delimita qué administradores pueden consultar la identidad.
3. `departamento_propietarios` representa la titularidad temporal, su porcentaje y su historial.

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
- Modificar una identidad global exige acceso administrativo a todos los edificios vinculados.
- Ser propietario no concede acceso mediante `edificio_usuario` ni crea una cuenta en `users`.

## Finanzas: conceptos y tarifas

El contexto `Finanzas` configura conceptos y tarifas, genera cargos y registra pagos; recibos definitivos y conciliación bancaria permanecen fuera de alcance.

1. `conceptos_cobro` es un catálogo local al edificio y define tipo, periodicidad, forma de cálculo y estado administrativo.
2. `tarifas_concepto` guarda valores monetarios, porcentajes y demás parámetros por intervalo temporal; una tarifa no se sobrescribe ni se elimina.
3. `tarifa_departamentos` conserva el alcance histórico cuando una tarifa se aplica a departamentos específicos.

Reglas implementadas:

- Los conceptos usan enums para tipo, periodicidad y forma de cálculo; el código es único dentro de cada edificio.
- Los importes usan `numeric(14,4)` y los porcentajes `numeric(9,6)`; no se usan `float` para persistir o transformar importes.
- Las vigencias son intervalos semiabiertos `[fecha_inicio, fecha_fin)`. `programada`, `vigente` y `finalizada` se derivan de esas fechas y no se almacenan de forma redundante.
- Registrar una tarifa nueva cierra transaccionalmente la única tarifa abierta anterior cuando corresponde; PostgreSQL bloquea solapamientos, edición histórica y eliminación directa.
- Tipo, periodicidad y forma de cálculo no pueden cambiar después de registrar una tarifa; el alcance de una tarifa finalizada también es inmutable.
- El alcance soportado es `todo_el_edificio` o `departamentos_especificos`. Las FKs compuestas impiden seleccionar unidades de otro edificio.
- La tabla de alcance es independiente de la tarifa, por lo que una extensión posterior puede añadir torre o piso sin reescribir tarifas históricas.
- Consumo requiere forma `por_consumo` y unidad; interés requiere porcentaje y base de cálculo; las cuotas extraordinarias admiten monto total y número de cuotas.

## Finanzas: cargos y lotes

ETAPA 6 convierte configuraciones vigentes en cargos de departamento. ETAPA 7 aplica pagos sobre esos cargos y deriva cartera sin tablas editables de deuda.

- `cargos` guarda una obligación concreta con saldo persistente, estados `pendiente`, `parcial`, `pagado` y `anulado`, y un snapshot de cálculo.
- `lotes_generacion_cargos` audita cada ejecución, sus totales, omisiones y advertencias.
- El período usa un `date` normalizado al primer día del mes y se presenta como `YYYY-MM`.
- Edificio no dispone de fecha de corte o vencimiento. La generación automática emite el primer día del período y vence el último; el cargo manual permite override explícito.
- Los cargos automáticos son idempotentes por edificio, departamento, concepto y período mediante transacción e índice parcial PostgreSQL.
- Valor fijo y por alícuota se calculan con BCMath y `numeric`; porcentaje sin base legítima, consumo sin lectura y valor cero se omiten con advertencia.
- El cargo pertenece al departamento. Guarda propietario sólo si existe un titular único y congela titulares, tarifa, alícuota y parámetros en metadata.
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

## Finanzas: cartera y estado de cuenta

- ETAPA 8 usa un read repository reconstruible desde cargos, pagos, aplicaciones y titulares; no existe una tabla editable de cartera ni saldos materializados.
- Saldo bruto es la suma de `cargos.saldo` no anulados. Saldo vencido considera únicamente esos saldos con vencimiento anterior a la fecha de consulta; saldo no vencido es la diferencia.
- Saldo a favor es el remanente de pagos registrados sin aplicación. Saldo neto se presenta firmado y también separado entre posición deudora y acreedora para no compensar departamentos distintos.
- `al_dia` significa saldo vencido cero, incluso con cargos futuros. `moroso` requiere saldo vencido positivo. La antigüedad se deriva del vencimiento pendiente más antiguo; no genera intereses.
- Los KPIs se calculan antes de paginar. Morosidad es departamentos con saldo vencido dividido por departamentos incluidos en el filtro.
- El estado de cuenta es cronológico y contable: cargo es débito, pago es crédito y cada anulación revierte su documento. Las aplicaciones se muestran como trazabilidad del pago y no se suman otra vez.

## Capacidades previstas

| Área | Conceptos que deben diseñarse en conjunto |
|---|---|
| Propiedad y ocupación | Edificios, estructura física, alícuotas y propietarios implementados; residentes pendientes |
| Identidad y acceso | Usuarios, roles, permisos y alcance por edificio |
| Cuentas por cobrar | Conceptos, tarifas, cargos, pagos, saldo a favor y cartera preliminar implementados; recibos y comprobantes pendientes |
| Gastos y proveedores | Proveedores, contratos, gastos y cuentas por pagar |
| Operaciones | Mantenimiento, incidencias y solicitudes |
| Áreas comunes | Espacios, reglas y reservas |
| Comunicación | Comunicados, documentos y notificaciones |
| Gobierno | Reportes, trazabilidad y auditoría |

## Decisiones pendientes

- Definir roles y permisos específicos dentro de cada edificio.
- Definir identidad reutilizable para residentes, inquilinos y proveedores sin acoplarla a autenticación.
- Definir vigencia e historial de ocupación.
- Definir si la suma de alícuotas debe exigirse en 100% para distribuir sin diferencias de redondeo.
- Definir si comprobante significa recibo emitido, evidencia de pago o ambos.
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

Los módulos nuevos deben evitar nombres que dupliquen tablas heredadas en `public`. Las tablas de Edificio, Propiedad y Finanzas (`conceptos_cobro`, `tarifas_concepto`, `tarifa_departamentos`) pertenecen al esquema privado. Cualquier modificación posterior de una tabla heredada debe considerar su esquema de forma explícita.

El nombre configurado en `DB_SCHEMA` es persistente después de ejecutar la migración que crea el esquema. Cambiarlo exige una migración y un despliegue controlados.
