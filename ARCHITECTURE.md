# Arquitectura del sistema

## Objetivo

Construir el sistema de administración de edificios por capacidades verificables, reutilizando autenticación e infraestructura existentes y retirando progresivamente los contextos heredados que no correspondan al dominio.

Edificios es el primer contexto funcional. Los demás contextos del dominio se incorporarán únicamente cuando tengan un caso de uso ejecutable.

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

## Principios

1. Un sustantivo del negocio no implica automáticamente un bounded context.
2. Cada contexto debe partir de un caso de uso ejecutable, invariantes, autorización, persistencia y pruebas.
3. Las tablas nuevas del sistema pertenecen al esquema PostgreSQL `administracion_edificios`.
4. Las migraciones aplicadas no se editan; toda evolución utiliza migraciones nuevas.
5. Los límites de edificio y acceso se definen antes de crear datos administrativos.
6. Propietario y Residente son relaciones del dominio, no roles de autorización.
7. Reportes y auditoría son capacidades transversales hasta que sus casos de uso justifiquen un módulo propio.
8. No se introducen repositorios genéricos, CQRS, eventos o abstracciones base sin una necesidad concreta.

## Capacidades previstas

| Área | Conceptos que deben diseñarse en conjunto |
|---|---|
| Propiedad y ocupación | Edificios, unidades, alícuotas, propietarios y residentes |
| Identidad y acceso | Usuarios, roles, permisos y alcance por edificio |
| Cuentas por cobrar | Cuotas, cargos, pagos, saldos y comprobantes |
| Gastos y proveedores | Proveedores, contratos, gastos y cuentas por pagar |
| Operaciones | Mantenimiento, incidencias y solicitudes |
| Áreas comunes | Espacios, reglas y reservas |
| Comunicación | Comunicados, documentos y notificaciones |
| Gobierno | Reportes, trazabilidad y auditoría |

## Decisiones pendientes

- Definir roles y permisos específicos dentro de cada edificio.
- Definir la diferencia entre persona, propietario, residente e inquilino.
- Definir vigencia e historial de ocupación.
- Definir la precisión, moneda y reglas de alícuotas.
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

Los módulos nuevos deben evitar nombres que dupliquen tablas heredadas en `public`. Cualquier modificación posterior de una tabla heredada debe considerar su esquema de forma explícita.

El nombre configurado en `DB_SCHEMA` es persistente después de ejecutar la migración que crea el esquema. Cambiarlo exige una migración y un despliegue controlados.
