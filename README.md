# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Candidato v0.1.85: login Symfony con caché bloqueada y CSRF de sesión estable.** Base exacta `main` v0.1.84 `fa7f8413bfda489bdf2a9d55a19237ca3dd6c697`; Laravel productivo y Symfony aislado conservan sus límites.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Versión objetivo | 🚧 **v0.1.85** | `config/version.php`; no publicada |
| Base exacta | ✅ ~~main v0.1.84~~ | `fa7f8413bfda489bdf2a9d55a19237ca3dd6c697` |
| CI / Sonar / CodeRabbit del PR | 🚧 Revalidar HEAD final | No heredar checks del PR #83 |
| CI del SHA exacto main | ✅ ~~v0.1.84 success~~ | [#35691546669](https://github.com/pl0n3r/GrindFlow/actions/runs/35691546669) |
| Deploy Observer | ✅ ~~v0.1.84 humana observada~~ | [#35691546770](https://github.com/pl0n3r/GrindFlow/actions/runs/35691546770); NO checkout SHA remoto |
| Production Smoke | ⛔ Login E2E no validado | [#35691546677](https://github.com/pl0n3r/GrindFlow/actions/runs/35691546677); #73 |
| Symfony en Hostinger | ⛔ NO desplegado | `symfony-preview` solo CI aislado |
| Migraciones | ✅ ~~Sin cambio de esquema~~ | No se ejecutan en producción |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **5** | **+0** | **−0** | **+0** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · symfony-preview** |
| Gate agregador obligatorio | **validate**: todos los seleccionados; Sonar y CodeRabbit aparte |
| Alcance | Login Twig, cabeceras y sesión sintética Symfony; sin cambio al login Laravel |
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
- El formulario anónimo `GET /login` Symfony contiene token CSRF ligado a sesión y puede recordar un email; la respuesta ahora exige `Cache-Control: no-store, private` en lugar de solo `private`. No se modifica la autenticación productiva Laravel.
- PHPUnit HTTP con sesión sintética comprueba dos GET, formulario real, token CSRF no vacío e idéntico entre ambos, y cabeceras `no-store, private` sin `public`.
- Nuevo contrato [GF-SEC-006](docs/REQUIREMENTS.md) documenta la privacidad del formulario. La rama previa de trabajo fue recuperada sobre la base actual sin saltar release.
- El [Smoke de v0.1.84](https://github.com/pl0n3r/GrindFlow/actions/runs/35691546677) falló. Artifact privado: preflight CSRF `consistent`, POST `/login`, GET anónimo adicional `LOGIN_FAILURE_SESSION_CHECK=stable`. Esto reduce la incertidumbre sobre continuidad del token, pero **no demuestra credenciales válidas ni explica el rechazo**; #73 sigue abierto.

## Archivos modificados en este deploy
Inventario del candidato, no evidencia de publicación:
- `README.md`
- `config/version.php`
- `docs/REQUIREMENTS.md`
- `symfony/src/Http/Controller/LoginController.php`
- `symfony/tests/php/IdentityLoginTest.php`

## Validación
- El test se ejecuta exclusivamente en MariaDB Symfony descartable dentro de `symfony-preview`; CI/Sonar/CodeRabbit del HEAD final siguen pendientes hasta que concluyan. No hay prueba local ni deploy Symfony.
- El Smoke de Hostinger mide Laravel y no sustituye el test Symfony. El marcador humano v0.1.84 observado no verifica el SHA desplegado.

## Qué sigue
[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)

| Lane | Trabajo | Estado |
| --- | --- | --- |
| **NOW** | 🚧 Bloquear caché del login Symfony y probar continuidad CSRF | 🚧 PR/gates v0.1.85 |
| **NEXT** | 🚧 Resolver origen del rechazo E2E #73 | 🚧 Operación autorizada, sin reintentos ciegos |
| **LATER** | 🚧 Paridad/cutover Symfony aislado | 🚧 No desplegado |
| **BLOCKED / EXTERNAL** | ⛔ Validación productiva autenticada | ⛔ Cuenta/configuración por verificar |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~v0.1.84 main CI / versión humana observada~~ | ✅ ~~Nuevo diagnóstico CSRF estable tras rechazo~~ |
| **NOW** | 🚧 Login no cacheable, prueba sintética | 🚧 v0.1.85 candidata |
| **NEXT** | 🚧 Incidente de acceso E2E #73 | 🚧 Sin tocar credenciales |
| **LATER** | 🚧 Transición Symfony | 🚧 Aislada |
| **BLOCKED / EXTERNAL** | ⛔ Smoke autenticado completo | ⛔ Rechazo de login |
