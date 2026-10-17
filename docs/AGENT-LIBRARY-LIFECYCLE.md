# Library: estados del asset y papelera (#415)

Decisiones de producto #44 y #54 del épico #405. Solo construcción: migración aditiva,
sin datos reales ni proveedores.

## Estados (#44)

Siete estados persistidos: `new` (por defecto), `ready`, `in_review`, `in_use`, `paused`,
`blocked` y `archived`. El octavo, `expired`, es **derivado**: sale de la ventana de vigencia UTC
(#407) y nunca se guarda; sustituye al estado persistido salvo en un asset `archived`.

| Desde | Transiciones válidas |
| --- | --- |
| `new` | `ready`, `in_review`, `blocked`, `archived` |
| `ready` | `in_review`, `in_use`, `paused`, `blocked`, `archived` |
| `in_review` | `ready`, `blocked`, `archived` |
| `in_use` | `ready`, `paused`, `blocked`, `archived` |
| `paused` | `ready`, `in_use`, `blocked`, `archived` |
| `blocked` | `ready`, `in_review`, `archived` |
| `archived` | `ready` |

`POST /api/admin/vault/{id}/lifecycle` (`{"to": "ready"}`; capacidad `edit` y CSRF de gestión)
aplica o rechaza la transición bajo el bloqueo del asset y **audita siempre** el intento en
`gf_vault_asset_lifecycle_events` (resultado `applied` o `rejected`, con motivo `invalid_transition`,
`same_state` o `derived_state`). Un rechazo responde 409 y no cambia el estado. `GET` devuelve el
estado efectivo, el persistido, las transiciones permitidas y los últimos eventos. Los intentos
aparecen también en el historial por asset (`lifecycle_transition`).

Efecto: `paused`, `blocked` y `archived` sacan el asset de la reserva (manual y automática) y de la
vista previa con los motivos `lifecycle_paused`, `lifecycle_blocked` y `lifecycle_archived`. Un valor
persistido desconocido falla cerrado como bloqueado. Ningún estado cambia solo (por ejemplo, no se
pasa a `in_use` al programar) y ningún estado autoriza publicar.

## Papelera y eliminación definitiva (#54)

- **Borrado reversible:** la papelera existente guarda actor y fecha y se puede restaurar; la
  restauración conserva el estado, las revisiones, las autorizaciones y todo el historial.
- **Eliminación definitiva:** `POST /api/admin/vault/{id}/purge` con `{"confirm_asset_id": "<id>"}`.
  Exige la capacidad `delete` (solo `admin`, reducible por miembro), el CSRF de gestión, la
  confirmación exacta del identificador y que el asset **ya esté en la papelera**. Borra el original
  privado y limpia nombre, nota y huella, pero **conserva la fila y todos los eventos y contadores**
  (borradores, revisiones, autorizaciones, variantes y estados): la respuesta lista los contadores
  conservados. Tras la eliminación el asset deja de contar en la cuota y la deduplicación, no aparece
  en el listado ni en la papelera y no se puede restaurar.
- Si el archivo del original no se pudo quitar del disco, la respuesta lo indica con
  `storage_removed: false` para que operaciones lo concilie; la fila ya está limpiada.

Sin migración productiva, publicación, proveedores ni datos reales. `down()` solo para base descartable.
