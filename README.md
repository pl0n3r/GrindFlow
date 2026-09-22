# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Candidato v0.1.86: login Symfony privado también tras autenticación o rechazo.** Base exacta `main` v0.1.85 `677f6ee863bcfaa8ea447e7ab3e94055f9dfb273`; Laravel productivo y Symfony aislado conservan sus límites.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Version objetivo | 🚧 **v0.1.86** | `config/version.php`; no publicada |
| Base exacta | ✅ ~~main v0.1.85~~ | `677f6ee863bcfaa8ea447e7ab3e94055f9dfb273` |
| CI / Sonar / CodeRabbit del PR | 🚧 Revalidar HEAD final | No heredar checks de un HEAD anterior |
| CI del SHA exacto de main | ✅ ~~v0.1.85 success~~ | [#35693080276](https://github.com/pl0n3r/GrindFlow/actions/runs/35693080276) |
| Deploy Observer | ✅ ~~v0.1.85 humana observada~~ | [#35693080236](https://github.com/pl0n3r/GrindFlow/actions/runs/35693080236); NO checkout SHA remoto |
| Production Smoke | ⛔ Login E2E no validado | [#35693080266](https://github.com/pl0n3r/GrindFlow/actions/runs/35693080266); #73 |
| Symfony en Hostinger | ⛔ NO desplegado | `symfony-preview` solo CI aislado |
| Migraciones | ✅ ~~Sin cambio de esquema~~ | No se ejecutan en producción |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **5** | **+48** | **−24** | **+24** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · symfony-preview** |
| Gate agregador obligatorio | **validate**: todos los seleccionados; Sonar y CodeRabbit aparte |
| Alcance | Redirección autenticada y error genérico de login Symfony; sin cambio al login Laravel |
| Revisiones | CI/Sonar/CodeRabbit HEAD, CI exact-main, Observer y Smoke separados |

## Flujo de entrega
```mermaid
flowchart LR
 A["PR + snapshot exacto"] --> P["preflight"]
 P --> F["fast contracts"]
 P --> Y["Symfony preview"]
 F --> V["validate"]
 Y --> V
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
- v0.1.85 impide cachear el HTML del formulario Symfony con CSRF privado. v0.1.86 aplica **el mismo Cache-Control: no-store, private** al GET de `/login` que redirige a `/organizations` cuando la sesión ya está autenticada, evitando reutilización de redirecciones dependientes de identidad.
- PHPUnit HTTP/MariaDB descartable amplía el recorrido autenticado: GET de `/login` redirige de forma privada; tras rechazo de cuenta inactiva, el GET de `/login` vuelve a mostrar mensaje genérico, respuesta no cacheable, nunca refleja la contraseña en HTML y prueba que tampoco expone el motivo de inactividad.
- Se extendió [GF-SEC-006](docs/REQUIREMENTS.md) sin cambiar autenticación Laravel, cuentas productivas, secretos ni migraciones.
- [Smoke v0.1.85](https://github.com/pl0n3r/GrindFlow/actions/runs/35693080266) no pasó; autenticación productiva independiente de Symfony aislado. [Incidente #73](https://github.com/pl0n3r/GrindFlow/issues/73) permanece abierto; no se infiere causa ni se reintentan credenciales.

## Archivos modificados en este deploy
Inventario de solo el deploy actual: candidato, no evidencia de publicación:
- `README.md`
- `config/version.php`
- `docs/REQUIREMENTS.md`
- `symfony/src/Http/Controller/LoginController.php`
- `symfony/tests/php/IdentityLoginTest.php`

## Validación
- La candidata v0.1.86 necesita `preflight`, `fast[contracts]`, `symfony-preview` y `validate` sobre HEAD exacto, además de Sonar y CodeRabbit final. No hay prueba local ni deploy Symfony.
- La CI exact-main v0.1.85 [#35693080276](https://github.com/pl0n3r/GrindFlow/actions/runs/35693080276) se verifica de forma separada; la versión humana v0.1.85 observada no verifica SHA Hostinger.

## Qué sigue
[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)

| Lane | Trabajo | Estado |
| --- | --- | --- |
| **NOW** | 🚧 Bloquear caché también en redirect autenticado y comprobar error privado | 🚧 PR/gates v0.1.86 |
| **NEXT** | 🚧 Resolver origen del rechazo E2E #73 | 🚧 Operación autorizada, sin reintentos ciegos |
| **LATER** | 🚧 Paridad/cutover Symfony aislado | 🚧 No desplegado |
| **BLOCKED / EXTERNAL** | ⛔ Validación productiva autenticada | ⛔ Cuenta/configuración por verificar |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~v0.1.85 fusionada y versión humana observada~~ | ✅ ~~CI exact-main #35693080276 success~~ |
| **NOW** | 🚧 Redirect y error de login Symfony privados | 🚧 v0.1.86 candidata |
| **NEXT** | 🚧 Incidente de acceso E2E #73 | 🚧 Sin tocar credenciales |
| **LATER** | 🚧 Transición Symfony | 🚧 Aislada |
| **BLOCKED / EXTERNAL** | ⛔ Smoke autenticado completo | ⛔ Rechazo de login |
