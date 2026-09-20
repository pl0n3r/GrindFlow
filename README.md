# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Snapshot v0.1.37: solo el deploy actual; protección de vistas privadas Symfony S1 en desarrollo, no desplegada.** Base `main` v0.1.36 `429532ca177998c26a2a7de49722ee4639140237`, CI exact-main verde. El selector y el admin Symfony no permiten caché del HTML privado, y React retira el panel si se revoca la sesión o membresía durante un guardado. Laravel continúa como runtime público; **Symfony no está desplegado ni se migraron cuentas**.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Version objetivo | 🚧 **v0.1.37** | `config/version.php` |
| Base exacta | ✅ ~~main v0.1.36~~ | `429532ca177998c26a2a7de49722ee4639140237` |
| CI del PR | 🚧 Head final pendiente | `GrindFlow CI / validate` |
| Sonar | 🚧 Pendiente | SonarCloud PR |
| CodeRabbit | 🚧 Revisión por comprobar | PR |
| CI del SHA exacto de main | 🚧 Tras merge | Actions |
| Producción | ⛔ Symfony no desplegado | Sin cutover autorizado |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **6** | **+36** | **−30** | **+6** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · symfony-preview** |
| Alcance | HTML privado no-store y revocación de sesión/rol durante mutación |
| Revisiones | CI/Sonar/CodeRabbit, exact-main y Hostinger son independientes |

## Flujo de entrega
```mermaid
flowchart LR
  A[PR + snapshot exacto] --> B[CI + Sonar + CodeRabbit]
  B --> C[Merge]
  C --> D[CI del SHA exacto de main]
  D --> E[Deploy autorizado]
  E --> F[Smoke producción]
```

## Qué se hizo
- Selector y panel privados Symfony envían `Cache-Control: no-store, private` para impedir que el navegador reutilice HTML de otra sesión.
- React retira el panel y los datos de organización cuando un guardado devuelve 401/403/409 por sesión o permisos cambiados.
- Test de integración Symfony verifica cabeceras de selector, panel y contexto JSON; CI valida PHP, TypeScript y navegador.
- No hay cutover ni escritura sobre bases de datos productivas.

## Archivos modificados en este deploy
- `README.md`
- `config/version.php`
- `symfony/frontend/admin/AdminApp.tsx`
- `symfony/src/Http/Controller/AdminController.php`
- `symfony/src/Http/Controller/OrganizationController.php`
- `symfony/tests/php/IdentityLoginTest.php`

## Validación
- CI del PR y Sonar pendientes en este snapshot; comprobar exact-main tras fusionar.
- Symfony S1 sigue aislado de Hostinger y sin cuentas productivas.

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 Protección de sesión Symfony S1 v0.1.37, [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2) |
| **NEXT** | 🚧 Vault móvil real con contexto tenant-safe |
| **LATER** | 🚧 Vault móvil → reglas → distribución autorizada → piloto |
| **BLOCKED / EXTERNAL** | ⛔ Cutover sin paridad/datos migrados; Smoke sin credencial |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~Dashboard Laravel v0.1.30~~ | ✅ ~~Esquema Symfony S1 v0.1.31~~ |
| **NOW** | 🚧 Protección de vistas privadas Symfony v0.1.37 | 🚧 CI y revisión |
| **NEXT** | 🚧 Vault móvil | 🚧 S2 |
| **LATER** | 🚧 Automatización de contenido | 🚧 S2–S5 |
| **BLOCKED / EXTERNAL** | ⛔ Sin cutover Symfony | ⛔ Sin credencial Smoke |

El historial durable y la prioridad completa permanecen en [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2).