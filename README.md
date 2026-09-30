# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Candidata v0.1.145 · Issue #187.** Restaura el caller gobernado de coordinación Factory para reservas, validación de PRs y recuperación segura de trabajo.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| SHA exacto de main (base) | ✅ **4e28fbb277c3642e48535a005540d159c2c5c21e** | v0.1.144 |
| CI del SHA exacto de main (base) | ✅ **success** | GrindFlow CI 36587515565 |
| Deploy Observer / Production Smoke base | ✅ **success / success** | 36587515637 / 36587515612 |
| Version objetivo | 🚧 **v0.1.145** | coordinación Factory #187 |
| CI/Sonar/CodeRabbit del PR | 🚧 pendiente | PR #188 · Issue #187 · v0.1.145 |
| Producción objetivo | 🚧 sin desplegar | cambio de coordinación GitHub; runtime productivo no mutado |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **4** | **+245** | **−64** | **+181** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[operational contracts + automation syntax + README dashboard] · php-quality · PHPUnit** |
| PR + snapshot exacto | **PR #188 · Issue #187 · v0.1.145**; README debe coincidir con HEAD final |
| Gate agregador obligatorio | **validate** exige todos los gates seleccionados; Sonar y CodeRabbit separados |
| Seguridad | bootstrap limitado al PR exacto same-repo; reserva/validación delegadas a Factory v1 |
| CI local canónico | **GrindFlow CI / validate** |
| Rol del PR | **Infraestructura · Ingeniería de software · Seguridad · QA** |

## Flujo de entrega
~~~mermaid
flowchart LR
  A["main v0.1.144"] --> P["#187 · caller de coordinación"]
  P --> Q["Factory v1 + tests deterministas"]
  Q --> V["CI / validate · Sonar · CodeRabbit"]
  V --> M["merge serial"]
  M --> X["exact-main + coordinación operativa"]
~~~

## Qué se hizo
- Añade el caller .github/workflows/work-coordination.yml consumiendo exclusivamente pl0n3r/factory/.github/workflows/coordinacion.yml@v1.
- Revalida PRs en opened, synchronize, edited, cambios draft/ready y cierre.
- Conserva la cola de coordinación con cancel-in-progress: false y queue: max.
- Delega /tomar al parser canónico para aceptar whitespace final sin fabricar reservas localmente.
- Limita la excepción de bootstrap a factory/bootstrap-coordination-187 del mismo repositorio y valida el resto de PRs.
- Añade regresiones deterministas para los seis jobs, sus condiciones y operaciones.

## Archivos modificados en esta entrega candidata
Inventario de solo el deploy actual:
<!-- grindflow:changed-files -->
- `.github/workflows/work-coordination.yml`
- `README.md`
- `config/version.php`
- `tests/test_factory_coordination_adoption.py`

## Validación
- workflow-syntax-check.rb valida el caller y las Actions oficiales.
- test_factory_coordination_adoption.py fija eventos, condiciones y operaciones del caller.
- Factory CI valida el reusable publicado sin copiar scripts a GrindFlow.
- El bootstrap no cambia DB, runtime de producto ni producción.
- Estado final exige GrindFlow CI, Factory CI, Sonar y CodeRabbit del mismo HEAD antes del merge.

## Qué sigue
[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **NOW** | 🚧 #187 · coordinación Factory | 🚧 validando v0.1.145 |
| **NEXT** | 🚧 #127 · reserva canónica | 🚧 desbloquear tras merge |
| **BLOCKED / EXTERNAL** | ⛔ #139 Dependabot + dependencias externas | ⛔ pendiente |
| **LATER** | 🚧 roadmap de producto | 🚧 preservado |
