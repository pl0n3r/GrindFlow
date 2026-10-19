# Library: reglas por red y reutilización (#416)

Decisiones de producto #45 y #46 del épico #405. Solo construcción: migración aditiva,
sin datos reales, sin llamadas a redes ni a proveedores de IA.

## Reglas por asset y red (#45)

`gf_vault_asset_network_rules`: un `allow` o `deny` explícito por asset y red
(`facebook_page`, `instagram_reels`), independiente de las colecciones. Un `deny` gana
siempre y bloquea con `network_denied`:

- la reserva de borradores, manual y automática (`ScheduleDraftApplicationService::reserve`);
- la vista previa semanal;
- la asignación de proveedor a un borrador y la publicación de Facebook Page.

## Política de reutilización (#46)

`gf_vault_asset_reuse_policies`: por asset y red (o `any` para todas) con modo `single_use`,
`max_n` (1 a 1000 usos) o `unlimited`, más un intervalo mínimo en horas (0 a 8760). Cuenta los
borradores no cancelados del asset en esa red; la política de una red concreta gana a `any`.
El mismo horario cuenta como el mismo borrador (la reserva repetida es idempotente). Motivos:
`reuse_exhausted` y `reuse_interval`. El planificador no descarta de por vida un asset cuyo
bloqueo depende del horario (`asset_not_yet_valid`, `asset_expired`, `reuse_interval`).

## Valores seguros documentados

- **Sin regla de red:** permitido (el comportamiento anterior no cambia).
- **Sin política de reutilización:** ilimitada y sin intervalo mínimo.
- **Red desconocida, instante mal formado o cualquier fallo de evaluación:** denegado
  (`network_unknown`).

## API

`GET /api/admin/vault/{id}/network-rules` (capacidad `view`), `POST .../network-rules`
(`{"network", "effect": "allow|deny|null"}`) y `POST .../reuse-policy`
(`{"network", "mode", "max_uses", "min_interval_hours"}`; `mode: null` quita la política).
Las escrituras exigen la capacidad `edit`, el CSRF de gestión del Vault y un cuerpo estricto;
otra organización responde 404. Una regla restringe la programación: nunca autoriza publicar.
Sin UI en esta hoja. La red de un borrador sin proveedor es `facebook_page`, el único hoy.
