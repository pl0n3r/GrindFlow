# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Snapshot v0.1.39: solo el deploy actual, paginación real del Vault S2 en código, Symfony aún sin despliegue.** Base `main` v0.1.38 `8256cf0725a6981681d798ca3917238a41bd960b`. Biblioteca privada con 30 imágenes por página, total SQL por organización, navegación móvil y actualización tras subir. Laravel sigue como runtime; **Symfony no está desplegado ni se migraron cuentas o archivos**.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Version objetivo | 🚧 **v0.1.39** | `config/version.php` |
| Base exacta | ✅ ~~main v0.1.38~~ | `8256cf0725a6981681d798ca3917238a41bd960b` |
| CI del PR | 🚧 Head final pendiente | `GrindFlow CI / validate` |
| Sonar | 🚧 Pendiente | SonarCloud PR |
| CodeRabbit | 🚧 Revisión por comprobar | PR |
| CI del SHA exacto de main | 🚧 Después del merge | CI PR no lo sustituye |
| Deploy Observer | 🚧 Release humano por observar | No prueba Symfony en remoto |
| Production Smoke | ⛔ Credencial E2E productiva pendiente | [Issue #1](https://github.com/pl0n3r/GrindFlow/issues/1) |
| Symfony S1 en Hostinger | ⛔ NO desplegado | Solo entorno aislado CI |
| Migraciones | ✅ ~~Ningún esquema productivo modificado~~ | DB Symfony descartable |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **8** | **+171** | **−46** | **+125** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · symfony-preview** |
| Alcance | Vault S2: paginación tenant-safe con total SQL, navegación móvil, prueba de 31 recursos y entradas inválidas |
| Revisiones | CI/Sonar/CodeRabbit, exact-main y Hostinger son independientes |

## Flujo de entrega
```mermaid
flowchart LR
 A["PR + snapshot exacto"] --> P["preflight"]
 P --> F["fast contracts"]
 F --> V["validate"]
 A --> S["Sonar"]
 A --> C["CodeRabbit"]
 V --> M["Squash merge"]
 M --> X["CI exact-main"]
 X --> O["Observer release"]
 O --> T["Smoke autenticado separado"]
```

## Qué se hizo
- API privada del Vault: 30 imágenes por página, total real y orden determinista `created_at DESC, id DESC` para la organización autorizada.
- Navegación anterior/siguiente móvil; tras subir imágenes vuelve a consultar la página 1 para refrescar el total.
- Pruebas PHP con 31 recursos propios y uno ajeno, segunda página, página fuera de rango y entrada malformada; Playwright móvil con cambio de página.
- Sin nuevas migraciones, archivos productivos ni conexiones externas.

## Archivos modificados en este deploy
- `README.md`
- `config/version.php`
- `symfony/README.md`
- `symfony/frontend/admin/VaultPanel.tsx`
- `symfony/frontend/admin/admin.css`
- `symfony/src/Http/Controller/VaultController.php`
- `symfony/tests/e2e/preview.spec.mjs`
- `symfony/tests/php/VaultTest.php`

## Validación
- CI Symfony PHP/MariaDB/Chromium y Sonar/CodeRabbit deben verificarse sobre el head final; exact-main y Hostinger son independientes.
- No se acredita despliegue Symfony ni migración productiva.

## Qué sigue
| Lane | Trabajo | Estado |
| --- | --- | --- |
| **NOW** | 🚧 Validar paginación Vault S2 v0.1.39 | 🚧 CI y revisión |
| **NEXT** | 🚧 Cuotas de almacenamiento y errores parciales | 🚧 Después de validar paginación |
| **LATER** | 🚧 Vault móvil → reglas → distribución autorizada → piloto | 🚧 Planificado |
| **BLOCKED / EXTERNAL** | ⛔ Cutover sin paridad/datos migrados; Smoke sin credencial | ⛔ Dependencia externa |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~Dashboard Laravel v0.1.30~~ | ✅ ~~Esquema Symfony S1 v0.1.31~~ |
| **NOW** | 🚧 Validar paginación Vault S2 v0.1.39 | 🚧 CI y revisión |
| **NEXT** | 🚧 Cuotas de almacenamiento y errores parciales | 🚧 Después de validar paginación |
| **LATER** | 🚧 Automatización de contenido | 🚧 S2–S5 |
| **BLOCKED / EXTERNAL** | ⛔ Sin cutover Symfony | ⛔ Sin credencial Smoke |
