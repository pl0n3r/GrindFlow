# Freeze de alcance de producto y corpus · NO SPEC LOOP

Esta política convierte el corpus histórico de GrindFlow en una **fuente congelada de producto**, no en una cola infinita de especificaciones. Su objetivo es impedir que un comando corto o una sesión nueva reactive por inercia la generación masiva de requisitos.

## Regla canónica

1. **`sigue` no genera requisitos.** `sigue`, `continúa`, `adelante` y `avanza` significan inspeccionar el estado real más reciente del repositorio, Issues, PRs y CI, y ejecutar el siguiente trabajo seguro no duplicado.
2. **Prohibido continuar numeraciones masivas** o abrir nuevas tandas de specs/requisitos solo porque la serie histórica tenga un número siguiente.
3. **Prohibido releer o regenerar linealmente miles de definiciones congeladas.** La recuperación histórica se hace por capacidades, macrobloques, procedencia y brechas concretas.
4. Antes de crear trabajo nuevo, contrastar código, Issues/PRs, `docs/REQUIREMENTS.md`, `docs/GRINDFLOW-SPEC.md` y las consolidaciones del corpus.
5. Una idea nueva se convierte en requisito/spec solo cuando responde a una necesidad nueva explícita, no existe una capacidad equivalente, requiere una decisión o contrato concreto y queda vinculada a un Issue/ADR/decisión trazable.
6. Si no existe una brecha real, no se crea spec ni Issue.
7. Las capacidades POST-MVP se preservan como inventario de producto; no se expanden ni desarrollan hasta que cambie su prioridad de forma explícita.
8. La memoria o el chat histórico son contexto no canónico y nunca autorizan reiniciar una secuencia masiva.
9. Reabrir generación masiva de specs exige una **decisión explícita del owner** que indique de forma inequívoca que quiere reabrirla; un `sigue` genérico no cumple esa condición.

## Flujo obligatorio después del corpus

`fuente histórica → capacidad canónica → estado real → brecha → leaf ejecutable → código/tests/evidencia`

La salida de una revisión histórica debe ser una capacidad reconciliada o una brecha concreta. La brecha se materializa como leaf ejecutable con aceptación y claims; el objetivo final es código, pruebas o evidencia, no aumentar el inventario documental.

## Interpretación de comandos cortos

- `sigue` / `continúa` / `adelante` / `avanza`: continuar desde el estado real y ejecutar trabajo seguro/no duplicado.
- `sigue con el MVP`: continuar el camino crítico del MVP.
- `sigue con requisitos`: reconciliar y priorizar requisitos existentes; no generar más por defecto.
- `revisa los requisitos`: usar consolidaciones y macrobloques; bajar a detalle solo para resolver una brecha, conflicto o requisito único verificable.

## Relación con #250 y el corpus histórico

Issue #250 conserva la convergencia del MVP y el freeze histórico. El corpus congelado preserva trazabilidad e ideas; **no equivale a backlog ni a implementación**. Si una revisión futura descubre una idea útil, primero se deduplica contra las fuentes canónicas y solo entonces se materializa la brecha real.

## Reversión y alcance

Esta regla no cambia producto, producción, DB, migraciones, proveedores ni secretos. Se revierte como cualquier cambio documental/test. Modificar o retirar este freeze requiere actualizar simultáneamente el enlace de `AGENTS.md` y su regresión ejecutable.
