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
  Los valores contradictorios o no permitidos fallan cerrado: un error de
  dashboard requiere redirect del POST a `/dashboard`; un recheck solo puede
  acompañar el retorno a `/login`; un preflight inconsistente nunca coexiste
  con señales de etapas posteriores. Redirects HTTP inesperados del POST se
  distinguen de un rechazo normal con recheck, sin inferir credenciales.
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


## Diagnóstico de Production Migration

El workflow de migración y el Production Smoke deben usar la misma identidad
sintética dedicada: `e2e-oidc-smoke@grindflow.test`. Production Smoke la
reconcilia mediante el bootstrap OIDC y la deja como `platform_role=admin`
sin memberships. La identidad legacy `e2e-admin@grindflow.test` no es una
fuente válida para inferir el estado de esa cuenta dedicada.

La ruta `/admin/system` está detrás de `auth`; además, el controlador devuelve
403 cuando la sesión sí existe pero el usuario no es platform admin. Por eso el
runner de migración clasifica de forma fail-closed:

| Código | Señal | Interpretación permitida |
| --- | --- | --- |
| `login_failed` | el POST de login no devuelve 302/303 | el login fue rechazado; no inferir contraseña ni existencia de cuenta |
| `login_session_not_persisted` | login 302/303 y luego Admin System 302/303 | la sesión no quedó aceptada por la ruta protegida |
| `admin_system_access_denied` | Admin System 401/403 | existe respuesta de autorización denegada; no ejecutar migración |
| `admin_system_unavailable` | red o cualquier otra respuesta no-200 | Admin System no es utilizable para la operación |

Los códigos y mensajes son fijos. Cookies, contraseña, HTML remoto y headers no
se copian al JSON de resultado ni a stdout/stderr.

### Migración manual sin SSH

La migración sigue siendo una acción explícita del dueño. El comando de
migración, una vez cumplida la evidencia de backup server-side para el
fingerprint exacto, es:

```bash
gh workflow run production-migration.yml --repo pl0n3r/GrindFlow --ref main -f expected_pending=<N>
```

Ese comando **no crea ni sustituye el backup**. Antes de lanzarlo debe existir
un backup nativo/restaurable de Hostinger. Cuando la política requiera receipt
server-side, el archivo `.sql.gz` verificado debe estar en el almacenamiento
local permitido de la app y registrarse desde el Terminal de hPanel —sin SSH—
con:

```bash
php artisan operations:record-db-backup operations/database-backups/<archivo>.sql.gz <fingerprint-64-hex>
```

El receipt dura como máximo 15 minutos y queda ligado al fingerprint y checksum
del archivo. Una copia de hPanel, un comentario o un booleano sin archivo
verificable no satisfacen la política. Si falta el receipt, el controlador
falla cerrado antes de ejecutar `migrate`.

El resumen distingue tres estados de evidencia: si faltan las credenciales,
muestra `Verified backup evidence: no ejecutado`; si el paso de migración
termina correctamente, muestra `resolved and validated server-side`; y si el
paso falla después de iniciarse, muestra `no confirmado (falló el paso de
migración)`. Así el workflow no afirma que una validación se omitió cuando el
fallo pudo ocurrir después de alcanzarla, ni convierte incertidumbre en
evidencia operativa.


## Recuperación segura de la identidad sintética de Production Migration

La identidad dedicada para Production Migration es `e2e-oidc-smoke@grindflow.test`.
Solo se reconcilia cuando una revisión autorizada haya confirmado que la identidad
sintética es la causa del rechazo. No se cambia ni se reutiliza
`e2e-admin@grindflow.test`, y nunca se copian contraseñas, tokens, cookies ni
payloads remotos a Issues, logs o documentación.

La recuperación canónica reutiliza **GrindFlow Production Smoke**:

1. ejecutar el workflow `.github/workflows/production-smoke.yml` sobre el SHA
   exacto autorizado;
2. dejar que el step **Reconcile synthetic smoke identity through GitHub OIDC**
   obtenga el token OIDC efímero del runner;
3. ese step llama exclusivamente a
   `/internal/production-smoke/bootstrap`, ligado al SHA esperado y a la fase
   permitida, para reconciliar la identidad sintética;
4. si el bootstrap no devuelve su éxito canónico, el workflow falla antes del
   login y solo publica códigos allowlisted; no se intenta reparar manualmente
   la cuenta ni se prueba otra credencial;
5. una vez que Production Smoke vuelva a pasar con evidencia exact-SHA, se puede
   reintentar Production Migration mediante su workflow owner-only y su
   aprobación explícita de pending migrations.

Esta recuperación no ejecuta migraciones por sí misma ni sustituye la decisión
vigente de #238 sobre cualquier lote productivo protegido. Si la evidencia no
demuestra que la identidad sintética sea la causa, conservar el diagnóstico
fail-closed y no mutar usuarios.
