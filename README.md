# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Candidato v0.1.115: checkout de CI sin credenciales Git persistidas.** Base exacta `main` v0.1.114 `dd6ccee58c5cf301e8f4e375a09846cc47128712`; todos los jobs que leen el repositorio desactivan la persistencia del token de `actions/checkout` y CI bloquea regresiones.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Version objetivo | 🚧 **v0.1.115** | `config/version.php`; no publicada |
| Base exacta | ✅ ~~main v0.1.114~~ | `dd6ccee58c5cf301e8f4e375a09846cc47128712` |
| CI del PR | 🚧 Pendiente | Exigir `validate` del HEAD final en `success` |
| Sonar del PR | 🚧 Pendiente | Exigir `SonarCloud Code Analysis` del HEAD final en `success` |
| CodeRabbit del PR | 🚧 Pendiente | Exigir revisión CodeRabbit completada del HEAD final |
| CI del SHA exacto de main | ✅ **VALIDATED IN CODE** | `35861799371` success sobre `dd6ccee58c5cf301e8f4e375a09846cc47128712` |
| Deploy Observer | ✅ ~~Marcador humano observado~~ | `35861799404` success; no acredita SHA remoto |
| Production Smoke | ⛔ Login E2E no validado | `35861799383` failure, #73; independiente |
| Symfony en Hostinger | ⛔ NO desplegado | Sin cutover |
| Datos productivos | ✅ ~~No tocados~~ | Cambio de workflows/CI; sin cuentas ni datos reales |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **12** | **+79** | **−16** | **+63** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[operational contracts + automation syntax + README dashboard] · php-quality · PHPUnit · MariaDB · browser · real-stack · legacy · symfony-preview** |
| Gate agregador obligatorio | **validate**: todos los seleccionados; Sonar y CodeRabbit aparte |
| Alcance | GF-OPS-014: no persistir el token Git de checkout en ningún workflow |
| Revisiones | CI/Sonar/CodeRabbit HEAD; exact-main, Observer y Smoke separados |

## Flujo de entrega
```mermaid
flowchart LR
 A["PR + snapshot exacto"] --> P["preflight"]
 P --> F["fast + parity tests"]
 P --> H["gates completos"]
 F --> V["validate"]
 H --> V
 A --> S["Sonar"]
 A --> C["CodeRabbit"]
 V --> M["Squash merge"]
 S --> M
 C --> M
 M --> X["CI exact-main"]
 X --> O["Observer release"]
 O --> T["Smoke separado"]
```

## Qué se hizo
- Cada `actions/checkout` declara `persist-credentials: false`; los procesos posteriores ya no heredan el token inyectado en `.git/config`.
- `scripts/workflow-syntax-check.rb` recorre todos los jobs y falla si un checkout nuevo omite el opt-out o vuelve a persistir credenciales.
- Los workflows que escriben Issues o comentarios conservan permisos explícitos y usan `github.token`/`GITHUB_TOKEN`; no se habilita ningún push Git desde CI.
- GF-OPS-014 documenta el contrato durable. No cambia aplicación, datos, triggers, secretos, Hostinger ni cutover Symfony.

## Archivos modificados en esta entrega candidata
Inventario exclusivo de esta entrega candidata; no prueba publicación:
<!-- grindflow:changed-files -->
- `.github/workflows/ci-health.yml`
- `.github/workflows/grindflow-ci.yml`
- `.github/workflows/production-deploy-observer.yml`
- `.github/workflows/production-diagnostics.yml`
- `.github/workflows/production-migration.yml`
- `.github/workflows/production-smoke.yml`
- `.github/workflows/sincronizar-gobierno.yml`
- `.github/workflows/sonar-pr-details.yml`
- `README.md`
- `config/version.php`
- `docs/REQUIREMENTS.md`
- `scripts/workflow-syntax-check.rb`

## Validación
- Exigir `validate`, Sonar y revisión CodeRabbit completada del HEAD final; después CI exact-main.
- La matriz completa debe demostrar checkout, pruebas y automatizaciones con credenciales Git no persistidas.
- Production Smoke #73 permanece separado; esta entrega no autoriza cambios de producción ni reintentos de credenciales.

## Qué sigue
[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **NOW** | 🚧 GF-OPS-014: checkout sin credenciales persistidas | 🚧 v0.1.115 candidata |
| **NEXT** | 🚧 Siguiente slice seguro priorizado en roadmap | 🚧 tras exact-main |
| **BLOCKED / EXTERNAL** | ⛔ Smoke autenticado Laravel | ⛔ #73 |
| **LATER** | 🚧 Cutover Symfony por módulo | 🚧 sin deploy |
