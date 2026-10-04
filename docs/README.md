# GrindFlow · Índice de documentación canónica

Este índice evita reconstruir el producto a partir de archivos aislados o conversaciones históricas.

## Orden de lectura para producto

1. [`PRODUCT-REQUIREMENTS.md`](PRODUCT-REQUIREMENTS.md) — decisiones actuales del producto, requisitos `GF-PROD-*`, hipótesis comerciales y pendientes explícitos.
2. [`PRODUCT-CORPUS-TRACEABILITY.md`](PRODUCT-CORPUS-TRACEABILITY.md) — reconciliación entre esas decisiones y el corpus histórico `#1–#10000`; separa visión final de prioridad MVP.
3. [`REQUIREMENTS.md`](REQUIREMENTS.md) — contratos funcionales/técnicos `GF-*` y evidencia de estado.
4. [`GRINDFLOW-SPEC.md`](GRINDFLOW-SPEC.md) — especificación técnica y de dominio vigente.
5. [`STACK-TRANSITION-SYMFONY.md`](STACK-TRANSITION-SYMFONY.md) — arquitectura objetivo y transición del stack.
6. Roadmap Issue #2 — secuencia operativa, progreso y bloqueos actuales.

## Corpus histórico

El corpus ya fue reconciliado. **No se debe volver a leer ni regenerar requisito por requisito.**

- `CORPUS-HISTORICAL-BASELINE-1-8040.md`
- `CORPUS-CURRENT-SESSION-66-1000-INDEX.md`
- `CORPUS-HISTORICAL-BLOCK-INDEX.md`
- `CORPUS-HISTORICAL-MACRO-CLASSIFICATION.md`
- `CORPUS-HISTORICAL-RECOVERY-PASS-2.md`
- `CORPUS-HISTORICAL-RECOVERY-PASS-3.md`
- `CORPUS-HISTORICAL-GAP-CLOSURE-PASS-4.md`
- `CORPUS-RECONCILIATION-METHOD.md`
- `CORPUS-REQUIREMENTS-8041-8340-CLASSIFICATION.md`
- `CORPUS-REQUIREMENTS-8341-10000-CLASSIFICATION.md`
- `CORPUS-REQUIREMENTS-8341-10000-CONSOLIDATED.md`

La numeración histórica conserva procedencia. Los IDs `GF-PROD-*` son la capa actual de decisión de producto y los `GF-*` son contratos técnicos/funcionales. No mezclar estas funciones.

## Regla de precedencia

Para una duda de **qué quiere el producto**, usar primero `PRODUCT-REQUIREMENTS.md`.

Para una duda de **cuándo se entrega**, usar `PRODUCT-CORPUS-TRACEABILITY.md` junto con Roadmap #2.

Para una duda de **qué está implementado o validado**, usar `REQUIREMENTS.md`, la especificación técnica, código, tests, PRs y evidencia del SHA exacto. Una decisión de producto no demuestra implementación.

Para una **nueva idea**, compararla semánticamente contra las tres capas antes de crear otro requisito. Si ya existe, ampliar la capacidad canónica en lugar de duplicarla.