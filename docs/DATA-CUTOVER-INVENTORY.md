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
