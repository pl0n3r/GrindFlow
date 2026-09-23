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

Ejecutar con el SHA íntegro de 40 caracteres y los archivos obtenidos para
**ese** HEAD:

```bash
python3 scripts/coderabbit-final-review.py \
  --head "${PR_HEAD_SHA}" \
  --statuses status.json \
  --reviews reviews.json \
  --threads threads.json
```

El comando imprime únicamente `CODERABBIT_GATE=completed_for_exact_head` o
`CODERABBIT_GATE=blocked` con causas fijas. Nunca muestra respuestas del
proveedor ni cuerpos de revisión. Una lectura malformada falla cerrado.

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
