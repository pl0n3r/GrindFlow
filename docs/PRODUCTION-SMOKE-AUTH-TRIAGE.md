# Diagnóstico seguro del login de Production Smoke

**Alcance:** clasificación pasiva de señales que `scripts/production-smoke.sh`
ya emitió. No se realiza una petición HTTP adicional, no se repite el POST de
login, no se comprueban credenciales y no se lee una base de datos.

`scripts/production-smoke-auth-triage.py` consume por stdin el log privado del
run fallido y emite únicamente etiquetas y frases **predefinidas**. El workflow
`.github/workflows/production-smoke.yml` incorpora la salida `--markdown`
al resumen y al issue de fallo; el log original permanece en el artifact con
retención breve. Los datos remotos no se insertan directamente en el issue.

## Interpretación de señales

| Resultado | Señales requeridas | Qué afirma |
| --- | --- | --- |
| `anonymous_session_inconsistent` | Preflight inconsistente | El smoke detectó cambio de sesión/CSRF antes de enviar el POST. |
| `login_rejected_anonymous_session_stable` | Preflight consistente; redirect a `/login`; recheck estable | El intento volvió al login; la sesión anónima no cambió en el recheck. |
| `login_rejected_anonymous_session_changed` | Mismo redirect; recheck cambiado | La sesión anónima cambió después del rechazo. |
| `login_rejected_recheck_unavailable` | Mismo redirect; recheck ausente/no disponible | No hay evidencia suficiente sobre el recheck. |
| `dashboard_authentication_redirect` | Redirect a `/dashboard`; nueva redirección autenticada conocida | La sesión autenticada no se observó como persistida hasta el dashboard. |
| `login_http_rejected` | Preflight consistente y HTTP de autenticación conocido | La petición recibió respuesta HTTP de rechazo/limitación. |
| `not_classified` | Cualquier otro caso | No inferir causa de autenticación. |

**Ninguna clasificación demuestra que la contraseña sea inválida, que el
usuario exista o esté activo, ni que el rate-limit sea la causa.** El issue #73
sigue abierto hasta que el smoke autenticado sea satisfactorio o haya una
comprobación de configuración autorizada y documentada.

## Contrato de seguridad

- Lee como máximo 1.000.000 bytes UTF-8 desde stdin; rechaza entrada excesiva
  o inválida con un error fijo, sin eco del contenido.
- Solo reconoce claves de telemetría y valores exactos en una allowlist cerrada.
  Los valores contradictorios o no permitidos fallan cerrado.
- Nunca publica headers completos, cookies, CSRF, contraseña, correo,
  HTML, destinos externos, consultas ni mensajes de servidor libres.
- El workflow captura un fallo del resumen con una frase fija y mantiene la
  publicación del incidente; el clasificador no suprime el fallo del smoke.
- `tests/test_production_smoke_auth_triage.py` cubre clasificación, ausencia
  de filtración, ambigüedad, codificación y límites. CI lo ejecuta en `fast`
  con datos sintéticos, sin contactar producción.

Prueba local con señales **sintéticas**:

```bash
printf '%s\n' \
  'LOGIN_SESSION_PREFLIGHT=consistent' \
  'LOGIN_REDIRECT_PATH=/login' \
  'LOGIN_FAILURE_SESSION_CHECK=stable' |
  python3 scripts/production-smoke-auth-triage.py --markdown
```

El resultado del ejemplo demuestra solo el formateo del diagnóstico. No es un
reporte de producción ni un intento de login.
