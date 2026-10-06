# Readiness multimedia del piloto

Este documento fija el contrato técnico **pre-piloto** de GrindFlow. Separa estrictamente lo que puede probarse en repositorio/CI de lo que solo puede acreditarse observando el entorno objetivo después de un deploy autorizado.

## Estados

- `BUILD_AHEAD_READY`: código, política fail-closed y regresiones están listos. **No** afirma que el hosting objetivo esté listo.
- `BLOCKED_TARGET_ENV`: falta una o más evidencias requeridas del entorno objetivo. Ausencia, error, stale o desconocido mantienen este estado.

CI puede demostrar `BUILD_AHEAD_READY`, pero **CI no sustituye la observación del entorno objetivo**.

## Camino inicial del piloto

La primera ruta multimedia del piloto es deliberadamente pequeña:

`Quick Upload → Vault privado local → SHA-256/tamaño verificados → decode real → re-encode temporal → provider autorizado`

Contrato:

- únicamente **JPEG y PNG**;
- máximo **8 MiB** por original;
- vídeo queda fuera del primer piloto;
- el original permanece privado, intacto y nunca se entrega directamente al provider;
- la copia entregable es efímera, re-encoded y se elimina tanto en éxito como en error;
- Direct Upload/S3 y GrindFlow #146 siguen siendo una dependencia **condicional**. No son blocker mientras Quick Upload + Vault local soporten de forma observada el volumen del piloto.

Si capacidad/rendimiento real exige Direct Upload/S3, #146 pasa a blocker únicamente con esa evidencia.

## Barrera para archivos no confiables

Todo original recibido se trata como no confiable aunque su tamaño y SHA-256 coincidan con la base.

Antes de fijar `delivery_locked_at` y antes de cualquier I/O del provider, la ruta Facebook Page Photos debe:

1. revalidar original privado, tamaño y SHA-256;
2. aceptar solo MIME declarado `image/jpeg` o `image/png`;
3. exigir decoder GD real disponible;
4. validar dimensiones máximas **8192 × 8192** y máximo **32.000.000 píxeles**;
5. rechazar framing inválido y bytes posteriores al final JPEG/PNG;
6. decodificar el contenido, no confiar solo en extensión/MIME;
7. re-encode al mismo tipo permitido en una copia temporal privada `0600`;
8. validar de nuevo tipo, dimensiones y framing de la copia;
9. entregar únicamente esa copia al provider;
10. borrar la copia temporal mediante cleanup garantizado.

Corrupción, polyglot/trailing payload, decoder ausente, dimensiones fuera de límite, fallo de temp o fallo de re-encode ⇒ **fail closed antes del lock y antes del provider**. No se crea una falsa entrega `published` y no se publica el original como fallback.

Esta barrera **no es un antivirus**. Un scanner futuro sería defensa adicional, no sustituto de aislamiento, allowlist, decode y re-encode.

## Scheduling y procesamiento previo

Se conserva el gate ya existente: un asset con procesamiento fallido, stale o de versión no vigente **no es elegible para Scheduling**. La barrera de publicación no crea un tercer pipeline multimedia ni relaja tenant isolation.

## Video

**Video está fuera del piloto inicial.** No se habilita por inferencia desde CI ni por la mera presencia de código FFmpeg.

Para incorporarlo se requerirá evidencia del entorno objetivo de FFmpeg/ffprobe, límites compatibles, procesamiento controlado y fallo seguro. Hasta entonces no forma parte de este contrato.

## Readiness del entorno objetivo

El probe CLI sirve únicamente como diagnóstico de build-ahead:

```bash
php scripts/media-pilot-readiness.php
```

Su salida usa `evidence_scope=cli_diagnostic`. Aunque devuelva `ready`, **no acredita el runtime web** y nunca basta para cerrar #307 ni para declarar el hosting listo, porque CLI y PHP-FPM pueden tener extensiones, `memory_limit`, directorio temporal o permisos distintos.

Después de un deploy autorizado, la evidencia objetivo debe obtenerse desde el **mismo runtime web que ejecuta `publishFacebook()`**, mediante una sesión administrativa autenticada y tenant seleccionado:

```text
GET /api/admin/schedules/media-readiness
```

El endpoint es read-only respecto del producto y providers: no crea assets, no modifica DB, no publica y no llama Facebook. Verifica en ese proceso web:

- decoder GD requerido para JPEG/PNG;
- directorio temporal disponible y writable;
- Vault privado resoluble, legible, no symlink y con permisos privados.

La respuesta solo expone `ready/not_ready`; **no imprime paths, secretos, hashes ni metadata de archivos**. Forma esperada:

```json
{"data":{"contract":"media-pilot-readiness-v1","status":"ready","checks":{"decoder":"ready","temporary_storage":"ready","private_vault":"ready"},"evidence_scope":"web_runtime","ci_equivalent":false}}
```

Ausencia del endpoint, autenticación inválida, estado `not_ready`, error o evidencia stale ⇒ `BLOCKED_TARGET_ENV`. El cierre operativo de #307 requiere una observación `web_runtime` después del deploy autorizado; CI y el diagnóstico CLI solo pueden demostrar `BUILD_AHEAD_READY`.


### Diagnóstico allowlisted del bridge S4

Cuando el Production Smoke no alcanza el endpoint multimedia y clasifica `MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200`, la reconciliación puede publicar **solo** un estado S4 si existe exactamente un marker válido y único en el log local del workflow. La ausencia, duplicidad o un valor fuera de allowlist conserva únicamente la causa genérica.

Estados del bridge y siguiente acción segura:

- `runtime_unavailable`: verificar que el runtime/dependencias Symfony del checkout desplegado estén disponibles. No autoriza cambiar el front controller global ni degradar a Laravel.
- `config_missing`: completar la configuración requerida fuera de Git y volver a ejecutar el smoke exact-deploy. No autoriza copiar secretos a Issues/logs.
- `schema_missing`: aplicar el procedimiento gobernado de schema/Doctrine correspondiente y después revalidar. **No autoriza migraciones automáticas** desde este smoke.
- `identity_unavailable`: reconciliar la identidad/membership sintética por el mecanismo gobernado existente. **No autoriza crear identidades automáticamente** desde este reconciliador.
- `ready_for_web_probe`: el bridge superó su preflight y puede continuar la autenticación Symfony y el probe multimedia; no equivale por sí solo a readiness multimedia.

Estado de autenticación S4, cuando existe exactamente un marker válido y único:

- `identity_unavailable`: el flujo Symfony no pudo acreditar login/membership/selección de organización; corregir esa frontera y revalidar.
- `ready`: autenticación y selección Symfony quedaron listas para continuar al endpoint de readiness; tampoco demuestra que decoder/temp/Vault estén `ready`.

Estos markers son diagnóstico read-only. No exponen body remoto, headers, redirects completos, paths, cookies, hashes, credenciales ni secretos; **no autoriza publicación externa** ni provider I/O.

El Production Smoke emite una causa allowlisted: `observed`, `endpoint_unreachable`, `http_non_200` o `contract_invalid`; si el marcador o los sub-checks esperados faltan, la reconciliación clasifica `markers_absent`. Cuando no existe diagnóstico web válido, publica **una sola vez por SHA+run** un comentario «Media web runtime: diagnóstico no disponible» con el run, SHA y causa clasificada. Nunca copia body remoto, paths, cookies, headers, hashes ni secretos. Los eventos `push` y `workflow_dispatch` usan el mismo smoke y los mismos marcadores.


### Diagnóstico allowlisted de Private Vault

Cuando el runtime S4 alcanza el endpoint multimedia y el check principal devuelve `private_vault=not_ready`, el diagnóstico puede publicar **solo** un estado allowlisted, sin exponer la ruta física ni metadata del filesystem:

- `root_unavailable`: la raíz configurada está vacía o no es resoluble de forma segura; revisar configuración fuera de Git.
- `missing`: la raíz esperada no existe en el runtime web desplegado.
- `unreadable`: la raíz existe pero el proceso web no puede leerla.
- `permissions_unavailable`: el runtime no pudo inspeccionar permisos de forma fiable.
- `permissions_not_private`: group/other conservan bits de acceso; el Vault no cumple privacidad mínima.
- `ready`: la raíz existe, es legible, no es symlink y sus permisos son privados.

El marker operativo es `S4_PRIVATE_VAULT_STATE=<estado>`. Debe aparecer una sola vez y pertenecer exactamente a la allowlist; ausencia, duplicidad o un valor fuera de dominio mantienen el contrato en fail-closed. La evidencia publicada en #307 contiene únicamente SHA/run, los sub-checks `ready/not_ready` y este estado allowlisted. Nunca copia path, mode numérico, UID/GID, nombres de archivo, body remoto, cookies, hashes ni secretos.

Estos estados son **solo diagnóstico read-only**: ninguno autoriza escritura automática, creación de directorios, `chmod`, movimiento de Vault, cambio de owner/group ni configuración SSH/Hostinger. La reparación correspondiente debe ejecutarse por un mecanismo gobernado separado y volver a acreditarse con Production Smoke exact-main.


## Autoridad y límites

Este build-ahead no autoriza:

- pruebas contra producción/Hostinger desde CI;
- credenciales, secretos o configuración de provider;
- configurar S3/CORS;
- contratar o activar scanner externo;
- gasto;
- contenido, PII o datos reales del piloto;
- publicación externa;
- cambio de `APP_PHASE`, live o go-live.

Hasta existir evidencia objetivo válida, el máximo estado demostrable desde repositorio es `BUILD_AHEAD_READY` y la operación real permanece `BLOCKED_TARGET_ENV`.
