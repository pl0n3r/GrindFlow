# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=drpipe1098-commits_GrindFlow"><img alt="Sonar Quality Gate (configuración tras traslado sin verificar)" src="https://sonarcloud.io/api/project_badges/measure?project=drpipe1098-commits_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Snapshot PR v0.1.20: solo el deploy actual.** Base `main` v0.1.19 `9785632d82a2ac2372af54df7bd951fe62bfdbec`. CI [#35469623096](https://github.com/pl0n3r/GrindFlow/actions/runs/35469623096) falló en release-version: traslado sin incremento (v0.1.19 → v0.1.19). Último Smoke antiguo observó v0.1.14; no hay observación nueva de Hostinger.

## Progress convention
- ✅ ~~Completado~~ = concluido y verificado por las compuertas aplicables.
- 🚧 Pendiente = por hacer o en curso, sin tachado.
- ⛔ Bloqueado = dependencia externa real.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Gobierno](docs/GOVERNANCE.md) · [Especificaciones](docs/GRINDFLOW-SPEC.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **Gobierno GitHub tras traslado** | enlaces, CI y roadmap |
| Base exacta | ✅ **main v0.1.19** | `9785632d82a2ac2372af54df7bd951fe62bfdbec` |
| Version | 🚧 **v0.1.20 objetivo** | config/version.php |
| Version observada en producción | ⛔ **sin comprobación nueva** | histórico anterior: v0.1.14 |
| CI del PR | 🚧 **pendiente** | validate |
| Sonar | 🚧 **integración tras traslado no verificada** | proyecto original |
| CodeRabbit | 🚧 **revisión pendiente** | full review |
| CI del SHA exacto de main | ⛔ **falló gate de versión** | #35469623096 |
| Production Smoke | ⛔ **GET autenticados omitidos** | [Issue #1](https://github.com/pl0n3r/GrindFlow/issues/1): falta secret |
| Deploy v0.1.20 | 🚧 **no confirmado** | Hostinger pendiente |
| Migraciones | ✅ **sin SQL nuevo** | cambios de gobierno/documentación |
| ZIP | ✅ **sin entregables ZIP** | cambios GitHub |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **12** | **+106** | **−71** | **+35** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · php-quality · PHPUnit · MariaDB · browser · legacy** |
| Gobierno | Enlaces y validadores apuntan al repositorio real y al Issue #2 |
| Release | v0.1.20 incrementa v0.1.19; no se desactiva el gate |
| Historial | Roadmap previo #88 no se presume migrado; nueva fuente #2 |
| Producción | Se corrige el estado del smoke; sin modificar secretos, migraciones ni checkout Hostinger |

## Flujo de entrega
```mermaid
flowchart LR
 A["PR + snapshot exacto"] --> P["preflight"]
 P --> F["fast contracts"]
 A --> S["Sonar"]
 A --> C["CodeRabbit review"]
 F --> V["validate"]
 V --> M["Squash merge"]
 M --> X["CI exact-main"]
 X --> R["Production Smoke separado"]
```

## Qué se hizo
- Rutas del repositorio, roadmap y validadores actualizadas después del traslado a `pl0n3r/GrindFlow`.
- Referencias canónicas `#2` uniformes y detección de enlaces activos obsoletos al antiguo `#88`.
- Issue #2 restablece el seguimiento técnico sin inventar historial de Issues del propietario anterior.
- Versión humana v0.1.20 para una entrega trazable con el gate de release intacto.
- Se explicita que un Smoke verde por credenciales ausentes no verifica rutas autenticadas.
- Smoke de producción ahora falla explícitamente cuando falta la credencial; contrato Ruby previene una regresión.

## Archivos modificados en este deploy
- `.coderabbit.yaml` — referencia al Issue #2.
- `.github/workflows/production-smoke.yml` — evita éxito falso sin credenciales.
- `AGENTS.md` — roadmap actual.
- `GLOSARIO.md` — enlace al roadmap general.
- `README.md` — snapshot veraz del traslado.
- `ROADMAP.md` — acceso al Issue #2.
- `config/version.php` — v0.1.20.
- `docs/GOVERNANCE.md` — identidad y registro del traslado.
- `docs/GRINDFLOW-SPEC.md` — enlace al roadmap general.
- `scripts/readme-dashboard.py` — validar roadmap vigente.
- `scripts/validate-governance.py` — validar enlace al roadmap vigente.
- `scripts/workflow-syntax-check.rb` — comprueba que el smoke no pase sin autenticación.

## Validación
- En base main, PHPUnit, MariaDB, PHP quality y navegador fueron verdes en #35469623096; validate falló por transición v0.1.19 → v0.1.19.
- El nuevo candidato requiere CI/Sonar/CodeRabbit; producción se verifica por separado.
- Smoke #35469623049 fue success con credenciales faltantes y no prueba login ni checkout Hostinger; ahora la ausencia producirá un workflow fallido.

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 Recuperar gates y roadmap; [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2) |
| **NEXT** | 🚧 Configurar credencial de smoke; [#1](https://github.com/pl0n3r/GrindFlow/issues/1) |
| **LATER** | 🚧 Verificar Sonar/CodeRabbit tras traslado |
| **BLOCKED / EXTERNAL** | 🚧 SHA de checkout Hostinger no observado |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~Gobierno base restaurado en rama~~ | ✅ ~~enlaces y roadmap actualizados~~ |
| **NOW** | 🚧 CI v0.1.20 | 🚧 pruebas y gates |
| **NEXT** | 🚧 Smoke autenticado | 🚧 secreto de repositorio nuevo |
| **LATER** | 🚧 Deploy reciente | 🚧 sin comprobación remota |
| **BLOCKED / EXTERNAL** | 🚧 Checkout Hostinger | 🚧 sin prueba |
