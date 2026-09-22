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

## Captura controlada de metadatos MariaDB

`scripts/mariadb-structure-snapshot.php` genera el contrato `gf-arch-002-db-structure-snapshot-v1` directamente desde `information_schema`, limitado a tablas `gf_*`. La herramienta:

- exige `GF_METADATA_SNAPSHOT_APPROVED=1` como autorización explícita;
- usa `DATABASE_URL` únicamente para abrir la conexión;
- inicia una transacción read-only;
- consulta solo `information_schema.TABLES`, `COLUMNS`, `STATISTICS`, `KEY_COLUMN_USAGE`, `REFERENTIAL_CONSTRAINTS` y `TRIGGERS`;
- no selecciona filas de tablas de aplicación;
- no emite credenciales, nombre de esquema ni texto de errores de conexión;
- produce únicamente columnas, índices, FKs y triggers del espacio `gf_*`.

El contrato de seguridad local se valida con:

```bash
bash scripts/mariadb-structure-snapshot-contract.sh
```

CI ejecuta además una prueba end-to-end sobre la MariaDB **descartable** de `symfony-preview`: migra Symfony, captura el snapshot metadata-only y lo compara contra el estado final reconstruido desde las migraciones. Esa validación comprueba que el capturador y el comparador acuerdan sobre una base efímera; **no constituye autorización ni evidencia de una captura productiva**.

La comparación normaliza dos detalles físicos propios de MariaDB/InnoDB que no representan drift lógico: los display widths de tipos enteros (`int(10)`, `smallint(5)`, etc.) y los índices de soporte que InnoDB crea automáticamente con el mismo nombre/columnas que una FK cuando no existe un índice explícito. Cualquier otro índice extra continúa fallando la paridad.

Ejemplo operativo, solo para un entorno previamente autorizado. `DATABASE_URL` debe llegar al proceso desde el gestor de secretos o mecanismo de inyección del entorno; **no** escribir la URI con credenciales en la línea de comandos ni en el historial del shell:

```bash
GF_METADATA_SNAPSHOT_APPROVED=1 \
php scripts/mariadb-structure-snapshot.php > /tmp/gf-structure.json
```

El snapshot generado desde un entorno real debe permanecer fuera del repositorio salvo que haya sido revisado y saneado explícitamente.

## Restore drill descartable de MariaDB + Vault

`scripts/symfony-disposable-restore-drill.sh` ejecuta en CI un ensayo destructivo **solo** sobre la base `grindflow_symfony_ci` en loopback, con `APP_ENV=test` y `CI=true`. Requiere además `GF_RESTORE_DRILL_APPROVED=1`; cualquier otro host, puerto, base o entorno falla antes de tocar datos.

El ensayo:

1. crea usuario, organización y recurso sintéticos en el esquema Symfony aislado;
2. escribe un blob sintético en una raíz privada temporal;
3. ejecuta `vault:audit`, `vault:stage` y `vault:verify-stage`;
4. captura el inventario estructural previo;
5. genera un dump completo de la MariaDB descartable con la imagen fijada `mariadb:11.4`, incluidos triggers;
6. vacía todas las tablas de la base descartable con las FKs suspendidas durante esa limpieza y elimina la raíz original, **sin borrar el schema/base de datos del servicio CI**;
7. restaura las tablas dentro de esa misma base desde el dump y los blobs desde el stage privado;
8. exige `vault:verify-restore`, `doctrine:schema:validate` y paridad estructural;
9. compara el snapshot estructural anterior y posterior byte a byte;
10. elimina la fixture sintética y destruye todos los temporales al salir.

El dump y el stage contienen solo datos sintéticos de CI, se guardan bajo un directorio temporal 0700/0600 y no se suben como artefactos. El script no admite ejecución fuera de CI, Hostinger, hosts remotos ni otro nombre de base. `scripts/symfony-disposable-restore-drill-contract.sh` prueba que las guardas rechacen falta de aprobación, entorno no-test, `CI=false`, host remoto y base distinta.

Este ejercicio demuestra que **la mecánica de recuperación del stack Symfony aislado es ejecutable** sobre infraestructura desechable. No demuestra que exista un backup productivo, no acredita RPO/RTO, no valida secretos/configuración externa y no autoriza ejecutar el mismo procedimiento contra producción.

## Plan automático de reversión de migraciones

`scripts/symfony-migration-reversal-plan.py` elimina la lista manual de migraciones del gate de reversibilidad. Descubre todos los archivos `symfony/migrations/VersionYYYYMMDDHHMMSS.php`, rechaza nombres inesperados/duplicados y emite las clases Doctrine en orden descendente para ejecutar `--down`.

El contrato es source-only: no conecta MariaDB y no ejecuta migraciones por sí mismo. CI prueba el plan contra el directorio real y luego `symfony-preview` consume `--classes` para revertir **cada migración descubierta** antes de reaplicarlas. De este modo, agregar una migración nueva no exige editar una segunda lista manual y no puede quedar silenciosamente fuera del restore drill.

## Aislamiento tenant después del restore

Después de completar el restore drill, `scripts/symfony-post-restore-tenant-guard.sh` vuelve a ejecutar sobre la base restaurada regresiones de seguridad existentes:

- `VaultTest`: lectura, detalle, preview y descarga de recursos ajenos deben devolver 404, y revocación de membresía debe bloquear acceso;
- `VaultBulkUsageTest`: un lote con IDs de otro tenant se rechaza completo, sin mutación parcial;
- `VaultTrashTest`: papelera/restauración conserva tenant, membresía y privacidad del original;
- `OrganizationSettingsTest`: un actor no puede mutar otra organización enviando `organization_id` y los permisos/CSRF se reevalúan;
- `DistributionAuthorizationTest`: la autorización de distribución permanece tenant-safe y un recurso de otra organización no puede autorizarse.

El guard es CI-only y test-only. No introduce rutas, fixtures persistentes ni acceso a datos reales; reutiliza pruebas que crean y destruyen sus propios datos sintéticos sobre la copia restaurada.

## Contrato offline de propiedad de escritura por módulo

`scripts/cutover-ownership-plan.py` verifica propuestas de traspaso de escritura **sin conectarse a MariaDB**. Lee un envelope JSON `{"source": {...}, "plan": {...}}` por stdin y reutiliza `gf-arch-002-source-inventory-v1`. Su contrato `gf-arch-002-cutover-ownership-plan-v1` solo admite `mode=planning_only`, `source_only=true`, `database_contacted=false` y `production_authorized=false`. No acepta URI, secretos, SQL, paths externos ni campos arbitrarios en el plan.

Los grupos iniciales son deliberadamente pequeños: **identidad** (`users`, `organizations`, `memberships` frente a `gf_identity_*`) y **Vault** (`media_assets`, `media_blobs` frente a `gf_vault_assets`). El mapeo enumera **propiedad de tablas en el código fuente**, no equipara sus filas, adjuntos, IDs ni semántica. Otros módulos requieren un mapeo revisado antes de figurar aquí; no se inventa equivalencia entre, por ejemplo, publicaciones Laravel y borradores Symfony.

Ejemplo completamente reproducible y sin credenciales:

```bash
python3 scripts/data-schema-inventory.py --json > /tmp/gf-source-inventory.json
python3 scripts/cutover-ownership-plan.py --template identity > /tmp/gf-identity-plan.json
python3 scripts/cutover-ownership-plan.py --json < /tmp/gf-identity-plan.json
python3 scripts/cutover-ownership-plan.py --template vault |
  python3 scripts/cutover-ownership-plan.py --json
```

El primer comando emite **un solo documento JSON válido**; el mensaje de éxito para uso humano solo aparece sin `--json`. Los templates se derivan de las migraciones versionadas, con todos los requisitos en `pending` y escritor actual Laravel. El comparador reconstruye independientemente el inventario desde ese mismo checkout y rechaza envelopes fabricados o generados desde otra revisión de migraciones. El validador exige inventario sin colisiones, cobertura exacta de las tablas mapeadas, ausencia de duplicados, escritor previo/rollback Laravel y escritor propuesto Symfony. También valida el catálogo completo: una tabla no puede pertenecer a dos módulos y ningún mapeo puede apuntar a una tabla que ya no exista en las migraciones. La entrada se limita a 1.000.000 de bytes reales, no caracteres, y los errores no reproducen el payload. Rechaza cualquier intento de marcar aprobación, operación productiva o comprobaciones como completas dentro del plan offline.

El reporte `gf-arch-002-cutover-ownership-report-v1` incluye una huella SHA-256 del inventario fuente canónico (`source_inventory_sha256`) para identificar la versión exacta del conjunto de metadatos revisado; **no es un SHA de Git ni prueba de deploy**. `outside_this_proposal` enumera explícitamente las tablas de ambos runtimes que quedan fuera del módulo propuesto y cuyo ownership no se transfiere. Siempre declara `cutover_authorized=false` y `database_contacted=false`. Un plan estructuralmente válido **no certifica** backups, paridad de datos, bloqueo del escritor, RPO/RTO, smoke, consentimiento operativo ni autorización del propietario. Es insumo para revisión humana y un ensayo separado, nunca una orden ejecutable. No modificar la versión desplegada, `public_html` o el owner real de las tablas por obtener este reporte.

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

Esta entrega **no** cierra GF-ARCH-002. Para cerrarlo todavía faltan: inventario del esquema real autorizado, contrato reversible de migración por módulo, backup real restaurado y evidencia de un único escritor durante el ensayo/cutover. La prueba cross-tenant/IDOR Symfony+Doctrine ya queda cubierta en CI descartable, no en producción. La guardia de fuente reduce riesgo antes de llegar a esa fase.
