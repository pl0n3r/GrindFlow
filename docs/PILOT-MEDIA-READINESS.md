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

Después de un deploy autorizado se ejecuta, desde el runtime objetivo:

```bash
php scripts/media-pilot-readiness.php
```

El probe es **read-only respecto del producto**: no crea assets, no modifica DB, no publica y no llama providers. Observa únicamente:

- decoder GD requerido para JPEG/PNG;
- directorio temporal disponible y writable;
- Vault privado resoluble, legible, no symlink y con permisos privados.

La salida JSON solo expone `ready/not_ready`; **no imprime paths, secretos, hashes ni metadata de archivos**. Ejemplo de forma, no evidencia real:

```json
{"contract":"media-pilot-readiness-v1","status":"ready","checks":{"decoder":"ready","temporary_storage":"ready","private_vault":"ready"},"evidence_scope":"observed_target_environment","ci_equivalent":false}
```

Un resultado de CI sobre este script o sus tests **no acredita el hosting**. El cierre operativo de #307 requiere una ejecución observada después del deploy autorizado sobre el entorno objetivo.

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
