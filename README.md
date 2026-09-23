# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Candidato v0.1.106: selector multiorganización accesible.** Base exacta `main` v0.1.105 `248ee1d0c20c39d2c2a31cc9cd14ef0639d6db31`; cada acción distingue su espacio y señala el seleccionado.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Version objetivo | 🚧 **v0.1.106** | `config/version.php`; no publicada |
| Base exacta | ✅ ~~main v0.1.105~~ | `248ee1d0c20c39d2c2a31cc9cd14ef0639d6db31` |
| CI del PR | 🚧 Pendiente | Exigir `validate` del HEAD final |
| Sonar del PR | 🚧 Pendiente | Exigir Quality Gate del HEAD final |
| CodeRabbit del PR | 🚧 Pendiente | Exigir full review del HEAD final |
| CI del SHA exacto de main | ✅ **VALIDATED IN CODE** | `35829915605` success sobre `248ee1d0c20c39d2c2a31cc9cd14ef0639d6db31` |
| Deploy Observer | 🚧 Pendiente | `35829915425` sin conclusión al preparar; no inferir SHA remoto |
| Production Smoke | ⛔ Login E2E no validado | `35829915414` failure, #73; independiente de esta mejora |
| Symfony en Hostinger | ⛔ NO desplegado | Sin cutover |
| Datos productivos | ✅ ~~No tocados~~ | Pruebas en Symfony/MariaDB descartable; sin cambio de cuentas reales |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **6** | **+155** | **−25** | **+130** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[operational contracts + automation syntax + README dashboard] · symfony-preview** |
| Gate agregador obligatorio | **validate**: todos los seleccionados; Sonar y CodeRabbit aparte |
| Alcance | GF-UX-005: selector multiorganización con acción distinguible |
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
- Cada botón del selector Symfony identifica su organización mediante su nombre visible y describe el rol sin exponer IDs internos en el nombre accesible.
- La fila seleccionada anuncia «Actual» y `aria-current`, además de estado visual diferenciado.
- PHPUnit HTTP/Doctrine con MariaDB descartable crea dos membresías, verifica nombres accesibles únicos, selecciona la segunda con CSRF y confirma el contexto del tenant.
- GF-UX-005 documentado sin alterar autorizaciones, sesiones, Laravel ni Hostinger.

## Archivos modificados en esta entrega candidata
Inventario exclusivo de esta entrega candidata; no prueba publicación:
<!-- grindflow:changed-files -->
- `README.md`
- `config/version.php`
- `docs/REQUIREMENTS.md`
- `symfony/public/assets/grindflow.css`
- `symfony/templates/identity/organizations.html.twig`
- `symfony/tests/php/OrganizationSelectorAccessibilityTest.php`

## Validación
- Exigir `validate`, Sonar y full review CodeRabbit sobre el HEAD final; después CI exact-main.
- `symfony-preview` valida PHP, MariaDB descartable, TypeScript/Vite y Chromium a 360 y 820 px sobre build aislado.
- El release no demuestra deployment Symfony ni resuelve Production Smoke #73 del Laravel operativo.

## Qué sigue
[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)

| Lane | Trabajo | Estado |
| --- | --- | --- |
| **NOW** | 🚧 Selección accesible de organizaciones | 🚧 v0.1.106 candidata |
| **NEXT** | 🚧 Verificación autorizada de cuenta E2E | 🚧 #2 |
| **LATER** | 🚧 Conmutación Symfony por módulo | 🚧 Sin deploy |
| **BLOCKED / EXTERNAL** | ⛔ Resolver login E2E productivo | ⛔ #73 |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~v0.1.105 fusionada~~ | ✅ ~~accesibilidad del error de login Symfony~~ |
| **NOW** | 🚧 Selector multiorganización accesible | 🚧 GF-UX-005, v0.1.106 |
| **NEXT** | 🚧 Evidencia revisada fuera de banda + autorización separada | 🚧 GF-ARCH-002 sin cutover |
| **LATER** | 🚧 Symfony en Hostinger | 🚧 No desplegado |
| **BLOCKED / EXTERNAL** | ⛔ Smoke autenticado Laravel | ⛔ #73 |
