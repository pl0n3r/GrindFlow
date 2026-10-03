# GrindFlow · Método de reconciliación del corpus masivo

## Problema detectado

La numeración conversacional histórica **no es globalmente única**. Algunas tandas reiniciaron o reutilizaron rangos. Por ejemplo, el rango `#1301–#1320` aparece en resúmenes históricos asociado a varios dominios distintos (Search/Indexing, Collaboration/Approvals, Automation Studio, Creator Command Center, AI Editing Queue, métricas SaaS, entre otros).

Por tanto:

- un número no identifica por sí solo una definición única;
- contar hasta `#10000` no demuestra que existan exactamente 10.000 conceptos únicos;
- deduplicar solo por número podría borrar requisitos válidos;
- releer todo línea por línea tampoco es necesario ni deseable.

## Identidad segura de origen

Cada bloque recuperado se identifica por una tupla conceptual:

`fuente/sesión + rango original + título/tema`

Ejemplo:

`2026-09-30 · #1301–#1320 · Collaboration, Approvals & Team Workflows`

Esto permite conservar dos bloques con el mismo rango cuando su contenido es distinto.

## Pipeline de consolidación sin relectura masiva

1. **Recuperar índices y resúmenes de bloque**, no miles de líneas individuales.
2. Registrar cada bloque con identidad de origen, rango y tema.
3. Extraer capacidades semánticas del bloque.
4. Deduplicar por significado contra:
   - `docs/REQUIREMENTS.md`;
   - `docs/GRINDFLOW-SPEC.md`;
   - Roadmap #2;
   - matriz histórica #6;
   - código, PRs e Issues existentes.
5. Mantener referencias de **todos** los bloques fuente que convergen en una capacidad canónica.
6. Clasificar la capacidad resultante como:
   - `YA CUBIERTO`;
   - `BRECHA MVP`;
   - `POST-MVP`;
   - `CONFLICTIVO/DESCARTADO`;
   - `NECESITA DECISIÓN`.
7. Leer a detalle únicamente cuando exista:
   - contradicción entre bloques;
   - capacidad aparentemente única;
   - posible requisito P0/P1 no cubierto;
   - decisión comercial/seguridad ambigua.

## Regla de no pérdida

Una definición histórica puede fusionarse con otra, pero su **procedencia nunca se elimina**. La capacidad canónica debe poder apuntar a todos los bloques fuente que la originaron.

No se considera reconciliado un bloque hasta que todas sus capacidades estén:

- mapeadas a una capacidad canónica existente, o
- registradas como nueva brecha/decisión/post-MVP/conflicto.

## Regla de ejecución

- No crear miles de Issues.
- No volver a generar requisitos para rellenar rangos numéricos.
- No tratar propuestas como implementadas.
- No convertir ejemplos de pricing, límites o infraestructura futura en decisiones aprobadas.
- Crear Issues únicamente para brechas reales priorizadas después de la reconciliación.

## Baseline histórico congelado

El tramo `#1–#8040` se conserva como baseline histórico y **no vuelve a procesarse requisito por requisito**. Su mapa canónico está en `docs/CORPUS-HISTORICAL-BASELINE-1-8040.md`.

Esto no declara implementadas todas las definiciones antiguas. Define una regla de entrada: cualquier bloque histórico que reaparezca se compara semánticamente con las fuentes canónicas; solo contradicciones, requisitos únicos, decisiones ambiguas o posibles brechas P0/P1 justifican lectura adicional.

## Estado actual

- `#1–#8040` → baseline histórico congelado; macrobloques recuperables mapeados por significado, sin regeneración masiva.
- `#8041–#8340` → 300 definiciones históricas reconciliadas en 3 macrobloques en `docs/CORPUS-REQUIREMENTS-8041-8340-CLASSIFICATION.md`; no apareció una nueva brecha P0/P1.
- `#8341–#10000` → consolidación estructural: 1.660 definiciones → 332 capacidades → 17 dominios, preservando rangos.
- Issue #250 sigue siendo el tracker de convergencia MVP y no puede cerrarse mientras exista un macrobloque recuperado sin clasificación o una capacidad P0/P1 relevante sin representación canónica.

## Regla de continuidad

A partir de este punto **no se relee ninguno de los tramos ya congelados**. El trabajo histórico restante consiste exclusivamente en incorporar nuevos resúmenes/macrobloques que aparezcan, resolver contradicciones y cerrar brechas P0/P1 demostrables contra el producto real.