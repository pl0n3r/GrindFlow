# GrindFlow — Último deploy

<p align="center">
  <a href="https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
  <a href="https://sonarcloud.io/dashboard?id=drpipe1098-commits_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=drpipe1098-commits_GrindFlow&metric=alert_status"></a>
  <a href="https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Development dashboard** · snapshot profesional de **solo el deploy actual**. CI, deploy y validacion en produccion son evidencias distintas.

## Estado del deploy

| Señal | Estado actual | Evidencia |
| --- | --- | --- |
| Work line | 🟢 **GF-FR-003 · ffprobe metadata** | VALIDATED IN CODE · CI #221 |
| Base exacta | ✅ **main** | `62499a5963218db53239c54a5c302caf79e38324` |
| Produccion actual | ✅ **smoke verde** | Production Smoke #31 sobre la base exacta |
| Migraciones | ✅ **0 pendientes** | no hay cambios de schema en este slice |
| Feature gate | 🔒 **off por defecto** | `MEDIA_FFPROBE_ENABLED=false` |

## Huella del cambio

<!-- grindflow:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **9** | **+530** | **−40** | **+490** |

La huella se calcula con `git diff --numstat`; CI rechaza este dashboard si queda desactualizado.

## Calidad y entrega

<!-- grindflow:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · php-quality · PHPUnit · MariaDB · browser · legacy** |
| GrindFlow CI | `validate` exige success real para cada gate seleccionado |
| Sonar | análisis independiente + comentario estable de detalles del PR |
| CodeRabbit | full review sobre el head estable |
| Exact-main | CI vuelve a validar el SHA exacto despues del squash merge |
| Produccion | ffprobe no se habilita automaticamente aunque el codigo se despliegue |

## Flujo de entrega

```mermaid
flowchart LR
    A["PR + snapshot exacto"] --> P["preflight"]
    P --> F["fast contracts"]
    P --> Q["php-quality"]
    P --> T["PHPUnit"]
    P --> D["MariaDB"]
    P --> B["browser"]
    P --> L["legacy"]
    A --> S["Sonar"]
    A --> C["CodeRabbit full review"]
    F --> V["validate"]
    Q --> V
    T --> V
    D --> V
    B --> V
    L --> V
    S --> H["head estable"]
    C --> H
    V --> H
    H --> M["Squash merge"]
    M --> X["CI del SHA exacto de main"]
    M --> R["Production Smoke"]
```

## Qué se hizo

- Porta el slice útil del PR #59 sobre la arquitectura actual de GF-FR-003.
- Eleva el procesador determinista a `probe_v2` sin cambiar su contrato idempotente.
- Añade `FfprobeMediaInspector` detrás de un feature gate apagado por defecto.
- Copia el objeto a un temporal, hace `fflush` y ejecuta `ffprobe` con timeout acotado.
- Persiste solo metadata técnica allowlisted: duración, formato, conteo de streams, codecs, resolución y audio básico.
- Descarta tags arbitrarios y no persiste stderr ni payloads crudos.
- Mapea fallo, salida inválida y timeout a códigos de error seguros y acotados.
- Añade cobertura del inspector, incluido un fallo con stderr sensible que no se propaga.

## Archivos modificados en este deploy

- `.env.example` — feature gate, binario y timeout de ffprobe.
- `README.md` — snapshot exacto del slice actual.
- `app/Services/Media/FfprobeMediaInspector.php` — inspección técnica normalizada.
- `app/Services/Media/MediaAssetProcessor.php` — `probe_v2` y metadata técnica opcional.
- `app/Services/Media/MediaProcessingException.php` — errores seguros del probe.
- `config/grindflow.php` — configuración ffprobe.
- `docs/REQUIREMENTS.md` — verificación actualizada de GF-FR-003.
- `tests/Feature/FfprobeMediaInspectorTest.php` — normalización y fallos seguros.
- `tests/Feature/MediaProcessingJobTest.php` — contrato v2 con feature gate desactivado.

## Validación

- Estado actual: **VALIDATED IN CODE** sobre el head funcional `d39060d8b3269739d9f2b4080fe3fbf0d23c0b11`.
- GrindFlow CI #221 pasó fast, PHP quality, PHPUnit, MariaDB, browser, legacy y validate.
- SonarQube Cloud: Quality Gate OK, 0 issues y 0 Security Hotspots.
- Las pruebas usan Laravel Process fakes y bloquean procesos no simulados.
- Antes del merge se exige matriz completa, Sonar, revisión externa aplicable y recheck de `main`.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | Revalidar el head final, revisar CodeRabbit y hacer squash merge con recheck de `main`. |
| **NEXT** | Tras merge, validar exact-main + Production Smoke; habilitar ffprobe solo cuando el hosting confirme el binario. |
| **BLOCKED / EXTERNAL** | Object storage S3-compatible sigue sin configurar; disponibilidad real de ffprobe en hosting aun no esta validada. |
| **LATER** | Derivados FFmpeg detrás del mismo contrato y luego continuar P2. |

## Panorama general pendiente

| Lane | Frente | Estado |
| --- | --- | --- |
| **NOW** | Media processing | `probe_v2` + ffprobe feature-gated VALIDATED IN CODE |
| **NEXT** | Derivados | thumbnails/previews/normalización con FFmpeg |
| **NEXT** | Media Vault producción | Quick Upload disponible; Direct Upload espera object storage |
| **BLOCKED / EXTERNAL** | Hosting / storage | ffprobe real y S3-compatible requieren configuración externa |
| **LATER** | Operacion | queues, scheduler, retries y backups |
| **LATER** | Legacy retirement | solo tras GF-MIG-003 / GF-MIG-004 |
| **LATER** | Producto P2 | distribución, atribución y finanzas |
