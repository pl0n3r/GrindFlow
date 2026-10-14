# Library: permisos por miembro e historial por asset (#417)

Decisiones de producto #53 y #52 del épico #405. Esta hoja es solo de construcción:
migración aditiva, sin datos reales, sin proveedores ni publicación externa.

## Matriz de capacidades

Siete capacidades: `view`, `upload`, `edit`, `approve`, `authorize_publication`,
`archive` y `delete`. La matriz efectiva de un miembro es la del **rol** menos sus
restricciones individuales. Las restricciones son **solo denegación** (tabla
`gf_library_member_permissions`): un miembro jamás recibe más de lo que permite su rol.

| Capacidad | Roles | Equivale a |
| --- | --- | --- |
| `view` | admin, studio, editor, model | `workspace_view` |
| `upload`, `edit`, `archive` | admin, studio, editor | `content_prepare` |
| `approve` | admin, studio, editor | `content_review_decide` |
| `authorize_publication` | admin, studio | `distribution_authorize` |
| `delete` | admin | eliminación definitiva explícita de un asset ya en papelera (#415); los demás roles, denegada |

Todo lo que no se puede probar (capacidad desconocida, membresía inexistente o inactiva,
otra organización) se deniega. `VaultController` aplica `view` en el contexto común,
`upload` en la subida, `edit` en nombre, nota, clasificación, vigencia y lote, y `archive`
en papelera/restauración. `approve` y `authorize_publication` siguen aplicándose por rol en
sus controladores, con la misma equivalencia que la tabla.
Consulta de la matriz propia: `GET /api/admin/library/permissions`.

Alcance diferido: no hay UI ni endpoint para administrar las restricciones por miembro; solo
el servicio (`LibraryPermissions::deny`). Las restricciones se comprueban al inicio de cada
petición, no dentro de la transacción de escritura.

## Historial por asset

`GET /api/admin/vault/{id}/history` (capacidad `view`): origen, archivado, revisiones de
contenido, autorizaciones de distribución, variantes (como maestro o variante), borradores de
agenda con su cancelación y traspasos manuales. Máximo 100 eventos, orden cronológico estable,
`truncated` si hay más. Los intentos de publicación externa no están ligados a un asset y no
aparecen.

Redacción: cada evento solo contiene `type`, `at`, `actor_ref` y un `detail` con valores
permitidos. `actor_ref` es una referencia opaca de 12 caracteres derivada de la organización
y el miembro: nunca nombre, correo, identificador de usuario, nota, pie de foto, destino,
huella, clave de almacenamiento ni token. Un asset de otra organización responde 404 igual que
uno inexistente.

Sin migración productiva, publicación, proveedores ni datos reales. `down()` de la migración
es solo para base descartable.
