# Inventario de datos para coexistencia y cutover

Estado: **guardia de fuente implementada; inventario de datos reales y cutover productivo pendientes**.
Requisito: `GF-ARCH-002`.

## Objetivo

Evitar que Laravel y Symfony se conviertan accidentalmente en escritores concurrentes de una misma tabla durante la transición. Este documento y `scripts/data-schema-inventory.py` trabajan exclusivamente sobre el repositorio: **no conectan MariaDB, no inspeccionan datos reales y no autorizan migraciones productivas**.

## Frontera vigente

| Runtime | Esquema que puede escribir hoy | Regla de coexistencia |
| --- | --- | --- |
| Laravel raíz | Tablas creadas por `database/migrations/*.php` | Sigue siendo dueño del runtime legado hasta cutover explícito por módulo. |
| Symfony aislado | Tablas `gf_*` creadas por `symfony/migrations/*.php` | Solo escribe su esquema aislado. Una tabla Symfony nueva debe conservar prefijo `gf_`. |
| Producción | No inferida desde el repositorio | Ningún merge, versión humana o CI autoriza cambiar dueño de escritura en Hostinger. |

La guardia falla si detecta una colisión nominal entre tablas creadas por ambos runtimes o una tabla Symfony sin prefijo `gf_`. Se ejecuta manualmente con:

```bash
python3 scripts/data-schema-inventory.py
python3 scripts/data-schema-inventory.py --json
```

El JSON usa el contrato `gf-arch-002-source-inventory-v1`, registra la migración que introduce cada tabla y marca explícitamente `database_contacted=false`.

## Comparación contra un snapshot read-only

`scripts/data-schema-parity.py` permite comparar ese inventario con un snapshot de metadatos capturado por un operador autorizado. El comparador **no tiene código de conexión a MariaDB**: recibe dos archivos JSON y por diseño no puede usar `DATABASE_URL`.

El snapshot usa el contrato `gf-arch-002-db-snapshot-v1` y debe declarar explícitamente:

- `metadata_only=true`;
- `contains_row_data=false`;
- `tables` como lista de objetos `{"name": "tabla"}`.

Ejemplo mínimo:

```json
{
  "contract": "gf-arch-002-db-snapshot-v1",
  "metadata_only": true,
  "contains_row_data": false,
  "tables": [{"name": "users"}, {"name": "gf_organizations"}]
}
```

Flujo reproducible:

1. Generar el inventario fuente con `data-schema-inventory.py --json`.
2. Capturar el snapshot metadata-only por un canal autorizado.
3. Formar un único envelope JSON `{"source": {...}, "snapshot": {...}}`.
4. Entregar el envelope por **stdin** al comparador:

```bash
cat envelope.json | python3 scripts/data-schema-parity.py --json
```

El comparador no acepta rutas de archivos como argumentos, reduciendo el riesgo de lectura arbitraria por path traversal. El archivo `envelope.json` del ejemplo es local/temporal y **no debe versionarse si proviene de un entorno real**.

La comparación falla cerrado cuando falta una tabla declarada por fuente, el snapshot repite un nombre o aparece una tabla `gf_*` que Symfony no declara. Tablas adicionales sin prefijo `gf_` se reportan como informativas porque una base existente puede contener tablas operativas o históricas que esta transición no administra.

## Paridad estructural del esquema Symfony

La segunda capa comprueba el esquema aislado `gf_*` con dos herramientas también offline:

```bash
python3 scripts/symfony-schema-structure.py --json > /tmp/grindflow-symfony-structure.json
cat structure-envelope.json | python3 scripts/data-schema-structure-parity.py --json
```

`symfony-schema-structure.py` analiza únicamente los métodos `up()` de las migraciones Doctrine y reconstruye su estado final en orden, incluyendo los `ALTER TABLE` aditivos soportados; el SQL de `down()` no puede borrar metadatos del inventario final. Un `ALTER` destructivo/no soportado falla cerrado en vez de omitirse. Su contrato `gf-arch-002-symfony-structure-v1` registra columnas (nombre, tipo SQL canónico y nulabilidad), índices (nombre, unicidad y columnas ordenadas), claves foráneas (columnas, tabla/columnas referenciadas y `ON DELETE`) y triggers (nombre, tabla, momento y evento).

El snapshot autorizado usa `gf-arch-002-db-structure-snapshot-v1`, declara `metadata_only=true` y `contains_row_data=false`, y usa el mismo modelo normalizado. Los tipos se expresan en minúscula canónica, por ejemplo `char(36)`, `varchar(120)` o `decimal(12,2)`.

El comparador falla cerrado si una tabla `gf_*`, columna, índice, FK o trigger falta, aparece de más o cambia de estructura. Las tablas no `gf_*` siguen siendo informativas: este slice **no declara paridad estructural de Laravel**, que continúa como escritor legado hasta un cutover explícito.

Esta capa todavía **no** acredita defaults/check constraints, conteos, contenido, secuencia de migraciones aplicada, backup restaurable ni aislamiento cross-tenant sobre datos reales.

## Secuencia obligatoria antes de un cutover real

1. Obtener inventario **read-only** del MariaDB de destino: tablas, columnas, tipos, PK/FK, índices, triggers, conteos y versión de migraciones. Guardar solo metadatos no sensibles.
2. Compararlo con el inventario de fuente. Una diferencia no se corrige automáticamente.
3. Crear y probar backup restaurable de base y blobs en un entorno descartable. Un backup no probado no cuenta como rollback.
4. Definir por módulo el escritor anterior, el nuevo escritor, ventana, precondiciones y criterio de abortar.
5. Ejecutar ensayo de migración en copia descartable y pruebas negativas cross-tenant/IDOR de lectura y mutación.
6. Congelar el escritor anterior únicamente durante una operación de cutover autorizada y observable.
7. Migrar con script versionado e idempotente. Prohibido `schema:update --force` y cambios manuales no reproducibles.
8. Verificar conteos, invariantes, relaciones, muestras no sensibles y recorrido funcional antes de habilitar el nuevo escritor.
9. Si falla una condición, restaurar y devolver propiedad de escritura al runtime anterior. No intentar reparar producción improvisando SQL.
10. Registrar por separado: código fusionado, CI, operación de datos, deploy y smoke productivo.

## Criterio para declarar GF-ARCH-002 completado

Esta entrega **no** cierra GF-ARCH-002. Para cerrarlo todavía faltan: inventario del esquema real autorizado, contrato reversible de migración por módulo, backup restaurado, prueba cross-tenant/IDOR Symfony+Doctrine y evidencia de un único escritor durante el ensayo/cutover. La guardia de fuente reduce riesgo antes de llegar a esa fase.
