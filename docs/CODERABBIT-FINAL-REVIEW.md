# Compuerta de revisión CodeRabbit del HEAD exacto

`scripts/coderabbit-final-review.py` es una herramienta **de lectura local** para
verificar evidencia previamente consultada en GitHub. No hace llamadas HTTP,
no requiere tokens y no fusiona PRs. No sustituye las reglas de `AGENTS.md`,
la inspección de los hallazgos ni una compuerta obligatoria configurada en GitHub.

## Entrada

Proveer tres archivos JSON de un mismo PR, obtenidos tras estabilizar su SHA:

- `status.json`: respuesta de GitHub REST
  `GET /repos/{owner}/{repo}/commits/{HEAD}/status`, con `sha` y `statuses`.
  Se examina el **último** estado del contexto `CodeRabbit`.
- `reviews.json`: lista completa de
  `GET /repos/{owner}/{repo}/pulls/{PR}/reviews`, sin truncar páginas.
- `threads.json`: lista **completa** y normalizada de
  `list_pull_request_review_threads`:
  `{"review_threads":[{"is_resolved":true}, ...]}`.
  No pasar la respuesta cruda de GraphQL sin normalizar. Si hay paginación,
  recuperarla entera y no convertir un resultado parcial en evidencia vacía.

Guardar las tres respuestas completas como archivos JSON ordinarios de nombres fijos
en una carpeta temporal dedicada. Ejecutar **desde esa carpeta** con el SHA
íntegro de 40 caracteres:

```bash
python3 /ruta/al/repositorio/scripts/coderabbit-final-review.py --head "${PR_HEAD_SHA}"
```

El programa solo abre `status.json`, `reviews.json` y `threads.json` desde
su directorio de trabajo; no recibe rutas por CLI. En sistemas POSIX exige
`O_NOFOLLOW`, `O_NONBLOCK` y archivos regulares, con tamaño máximo de 1 MiB
cada uno. Usa `fstat` sobre el descriptor abierto para rechazar enlaces
simbólicos, directorios, FIFO y cambios de ruta entre comprobación y lectura.
Falla cerrado en plataformas sin estas garantías; no ejecutarlo directamente
sobre una carpeta compartida sin controlar sus archivos.

El comando imprime únicamente `CODERABBIT_GATE=completed_for_exact_head` o
`CODERABBIT_GATE=blocked` con causas fijas. Nunca muestra respuestas del
proveedor ni cuerpos de revisión. Una lectura malformada falla cerrado. Hay 19 tests offline de este contrato,
que se ejecutan desde el gate `fast` de la candidata.

## Lo que acepta y lo que rechaza

Acepta únicamente `CodeRabbit` = `success` con descripción explícita
`Review completed` para el SHA indicado, sin `CHANGES_REQUESTED` para ese
HEAD ni hilos sin resolver. En particular rechaza `success` +
`Review skipped: incremental reviews are disabled`, estados `pending`,
revisiones incompletas y un SHA diferente. Una revisión anterior sobre otro
commit no sirve.

**Esta herramienta no valida CI, Sonar, el despliegue ni producción.** Antes del
squash son obligatorios los demás controles de `AGENTS.md`, y después del
squash hay que validar el SHA exacto nuevo de `main`. No se automatiza ningún
merge ni se cambian secretos, usuarios o permisos.
