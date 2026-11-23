# Delta npm pendiente de la migración Symfony

> Registro técnico del ajuste retirado del PR [#951](https://github.com/pl0n3r/GrindFlow/pull/951) durante la coordinación de [#1038](https://github.com/pl0n3r/GrindFlow/issues/1038) y [#995](https://github.com/pl0n3r/GrindFlow/pull/995). La evidencia de revisión y de CI pertenece a los PR, no a este documento.

## Motivo de la extracción

El PR #951 proponía cambios en `symfony/package.json` y `symfony/package-lock.json` que solapaban las rutas npm reclamadas por #995. Para liberar la coordinación se retiró **únicamente ese delta** de #951, sin autorizar ni ejecutar su reintroducción automática. Este registro conserva la intención para una hoja posterior con claim y pruebas propios.

## Delta a evaluar antes de reintroducir

| Ruta | Cambio diferido | Justificación y condición |
| --- | --- | --- |
| `symfony/package.json` | `scripts.check = "npm run typecheck && npm run build"` | Agrupar la validación TypeScript y el build Symfony; comprobar que ambos scripts existen y pasan con las dependencias actuales. |
| `symfony/package.json` | `engines.node = ">=22 <23"` | Documentar la versión propuesta de Node, **sin imponerla** antes de comprobar compatibilidad con la versión objetivo de CI y el entorno de build. |
| `symfony/package-lock.json` | `packages[""].engines.node = ">=22 <23"` | Mantener la metadata raíz del lockfile coherente con el manifiesto; regenerar con npm y verificar que no se alteren dependencias ajenas. |

## Prerrequisitos y condiciones de reintroducción

1. Comparar el manifiesto y el lockfile con el `main` **vigente en ese momento**; incorporar solo el delta que siga siendo necesario. No restaurar ciegamente archivos de un commit anterior ni pisar cambios de otros PR.
2. Abrir una hoja específica con aceptación ejecutable, reserva BOT V3 propia y claims explícitos sobre `symfony/package.json` y `symfony/package-lock.json`; respetar dependencias y PR abiertos.
3. Confirmar qué versión de Node admiten CI y el entorno real de compilación. El rango `>=22 <23` es una **propuesta histórica**, no un requisito productivo acreditado.
4. Regenerar y validar el lockfile en conjunto con el manifiesto. Ejecutar `npm ci`, `npm run typecheck` y `npm run build` en un entorno reproducible, además del `validate` y de las puertas de coordinación/calidad aplicables al HEAD del nuevo PR.
5. No convertir la incorporación de estas opciones npm en autorización para desplegar, ejecutar migraciones, cambiar el runtime Symfony o emplear credenciales/datos reales.

## Reversión y límites

**Antes del primer commit de la hoja npm:** verificar y registrar el estado previo de **ambas** rutas con `git status --short -- symfony/package.json symfony/package-lock.json` y revisar cambios sin preparar y preparados mediante `git diff -- symfony/package.json symfony/package-lock.json` y `git diff --cached -- symfony/package.json symfony/package-lock.json`. Hacerlo **antes** de modificar los archivos; si existen cambios ajenos, guardar sus parches o copias de respaldo fuera del árbol de trabajo para no confundirlos con el delta propio.

- **Ambas rutas limpias al inicio:** si `npm ci` falla después de aplicar el delta y **antes de crear el primer commit**, descartar **solo esas dos rutas** con `git restore --source=HEAD -- symfony/package.json symfony/package-lock.json`. Si el intento alcanzó a preparar cambios en el índice, incluir `--staged --worktree` para restaurar también el índice, siempre que esas rutas estuvieran limpias al inicio. No restaurar otros archivos.
- **Cambios locales preexistentes:** **no ejecutar el restore anterior** sobre esas rutas, porque eliminaría trabajo ajeno. Conservar los parches/backups capturados antes de empezar y quitar **únicamente los hunks del delta de esta hoja**, conservando y verificando en el diff los cambios previos (incluidos los preparados en el índice). Si no se puede distinguir el delta con certeza, detener la reversión selectiva y reconciliar con quien sea titular de esos cambios; no usar `git reset --hard` ni forzar una restauración masiva.

**Después de crear el commit o fusionar el squash:** si la reintroducción falla, revertir **su propio commit o squash** para recuperar el manifiesto y lockfile que estaban vigentes antes de esa hoja, previa conciliación de otros cambios concurrentes. La extracción histórica de #951 se puede revertir por separado solo con la coordinación y autorización correspondientes; no se revierte aquí ni se modifica el código de #951 o #995.

Este documento es una especificación pendiente, no un registro de checks ni una afirmación de compatibilidad, despliegue o validación en producción.
