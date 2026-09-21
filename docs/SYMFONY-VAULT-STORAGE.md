# Vault Symfony · almacenamiento privado fuera de releases

## Qué permite esta entrega

El Vault S2 de Symfony tiene un directorio local privado predeterminado:
`symfony/var/vault`. En un entorno **aislado**, se puede configurar
`GRINDFLOW_VAULT_ROOT` con una ruta absoluta externa al árbol del repositorio
o del release. Carga, descarga, vista previa, integridad y restauración usan
la misma raíz, sin rutas físicas en las respuestas. La configuración está
desactivada si la variable no existe; Laravel en Hostinger no cambia.

Ejemplo **solo de ubicación**, no copiar literalmente a producción:

```dotenv
GRINDFLOW_VAULT_ROOT=/ruta-privada-fuera-del-release/grindflow-vault
```

El directorio padre debe existir. El proceso PHP debe poder escribir dentro
de la raíz privada. Si falta la carpeta final, se crea con permisos 0700;
no se siguen enlaces simbólicos como raíz configurada, ni se acepta una
carpeta externa ya existente que conceda permisos a grupo u otros usuarios.
Tampoco se aceptan rutas relativas ni segmentos `.` o `..` ni una ubicación dentro
del árbol del release. La carpeta debe permanecer fuera del webroot y no
publicarse como alias del servidor HTTP.

## Cambio de ubicación: no es una migración automática

**Nunca cambiar la ruta y luego asumir que el catálogo sigue disponible.**
Los registros SQL solo guardan claves opacas: sus bytes deben existir también
en la raíz configurada. Para habilitar storage externo en un entorno con
archivos, hace falta un procedimiento separado de copia consistente de blobs
y base de datos, comprobación SHA-256 por registro, congelación de escrituras
durante el corte, verificación de permisos y reversión documentada. No se
ejecuta ninguna copia/movimiento en este PR.

La ruta externa por sí sola **no garantiza durabilidad**, backups ni
recuperación. El operador debe verificar persistencia del volumen entre
releases/reinicios, backups del volumen **y** MariaDB coherentes, restauración
probada en entorno descartable, cifrado/control de acceso y alertas. Los
originales en papelera siguen contando para almacenamiento. No hay purga
automática ni política de retención asumida.

## Auditoría no destructiva y comprobación tras restaurar (v0.1.56)

En un entorno Symfony autorizado y **con escrituras detenidas durante la
captura/restauración**, el operador puede verificar los originales del catálogo
de **una sola organización** y obtener una huella del manifiesto de metadatos.
El comando lee MariaDB y los blobs privados; no hace backups, copia, modifica
o publica ningún archivo:

```bash
cd symfony
php bin/console grindflow:vault:audit --organization=UUID-DE-LA-ORGANIZACION
# Después de restaurar BD + blobs en un entorno separado:
php bin/console grindflow:vault:audit --organization=UUID-DE-LA-ORGANIZACION --expect=HUELLA_SHA256_DEL_INVENTARIO
```

La salida JSON contiene **solo agregados**: número de activos, papelera,
estados `verified/missing/mismatch/unavailable`, `manifest_sha256` y si
coincide la huella esperada. El manifiesto incorpora los metadatos de todos
los recursos ordenados por ID, incluidos los retenidos en papelera. No expone
nombres, IDs de recursos, hashes individuales ni rutas del servidor.
Guardar la huella del inventario de origen con la evidencia operativa.

**Códigos de salida:** `0` significa inventario no vacío, todos los
originales comprobados y manifiesto coincidente si se indicó `--expect`;
`2` significa entrada incorrecta, organización inexistente, catálogo vacío,
archivo faltante/alterado/no disponible o manifiesto diferente; `3`
indica error al auditar. No sustituir esos códigos por éxito en CI.

Una huella coincidente **no** equivale a un backup recuperable si los bytes
son inválidos: ambos criterios deben aprobar. No tomar el manifiesto durante
subidas, renombres o movimientos activos y luego interpretarlo como snapshot
consistente. La prueba integral de un backup requiere efectuar la
restauración real en infraestructura descartable, ejecutar el comando sobre
la pareja BD + blobs restaurada y registrar el resultado. Ningún PR activa
automáticamente esta operación en Hostinger.

## Límites y estado

Esta opción es preparación técnica S2, no un cutover ni un deploy.
Symfony y su MariaDB siguen aislados; Production Smoke de Laravel y
el marcador del Observer no prueban que el storage Symfony funcione en
Hostinger. No activar variable, migraciones o movimiento de archivos
productivos por efecto de un PR de código.

## Preparación manual de una copia y verificación independiente (v0.1.57)

Los comandos siguientes **solo preparan una copia privada por organización**
de los originales y sus metadatos de Vault. No respaldan MariaDB, cuentas,
membresías, otras tablas, claves ni configuración. Por tanto **no constituyen un
backup íntegro ni autorizan un cutover o purga**. No se ejecutan automáticamente
en CI ni en Hostinger; CI los prueba únicamente con datos sintéticos.

Antes de usarlos, el operador debe detener toda escritura en Vault, incluidos
uploads, renombres, movimientos y jobs; mantenerla detenida durante la captura
del estado de la BD compatible con los blobs. Elegir una carpeta nueva bajo
un **padre ya existente y privado (0700)**, fuera del release y de
`GRINDFLOW_VAULT_ROOT`. No reutilizar un destino, no exponerlo en el webroot
ni usar una carpeta compartida como `/tmp` directamente: preparar un subdirectorio
privado primero. Los ejemplos representan una operación deliberada en un
**entorno Symfony autorizado**, nunca una instrucción de migración productiva.

```bash
cd symfony
php bin/console grindflow:vault:audit --organization=UUID-ORGANIZACION
php bin/console grindflow:vault:stage \
  --organization=UUID-ORGANIZACION \
  --target=/volumen-privado-0700/copia-nueva \
  --confirm-writes-stopped
php bin/console grindflow:vault:verify-stage \
  --directory=/volumen-privado-0700/copia-nueva \
  --expect=HUELLA_SHA256_DE_AUDIT
```

La carpeta contiene `manifest.json` con metadatos sensibles (ID de la
organización, nombres, actores, claves opacas y hashes) y `blobs/*.blob`.
Carpetas en modo 0700 y archivos en 0600; proteger con cifrado, control de
acceso y retención del operador. Los comandos **no imprimen nombres, IDs de
recursos, rutas ni excepciones internas**. El manifiesto se escribe **al final**
solo tras copiar cada archivo, validar tamaño y SHA-256 y volver a contrastar
que el catálogo no cambió. Ante un fallo, puede quedar una carpeta parcial
**sin manifiesto final**; inspeccionarla y gestionarla de forma explícita,
nunca tratarla como copia válida ni sobrescribirla automáticamente.

`verify-stage` verifica sin consultar la BD viva: estructura, huella
del catálogo, número exacto de archivos y tamaño/SHA de cada original.
`--expect` compara la huella que devolvió `audit`. Exit `0` significa
preparación íntegra **de Vault en ese punto**, `2` significa entrada inválida
o copia incompleta y `3` significa fallo operativo. La huella puede coincidir
aunque otros datos de MariaDB falten: para una recuperación completa, hacer
**otro** backup consistente de todas las tablas/dependencias de Symfony y
ensayar una restauración real en un entorno aislado. Ninguno de los comandos
ejecuta SQL de restauración, cambia la raíz activa, purga ni modifica archivos
existentes del Vault.



## Ensayo de recuperación: cotejo de la copia y el entorno restaurado (v0.1.58)

Tras obtener un backup completo y coherente de **MariaDB Symfony**, además
de la carpeta privada `stage` del Vault, restaurar ambos en un **entorno
separado y descartable**: nuevas credenciales, base aislada, directorio de
originales privado y `GRINDFLOW_VAULT_ROOT` apuntando **solo** a esos bytes
restaurados. No dirigir jamás esta prueba a la MariaDB ni al Vault activos de
Laravel o de Hostinger. Suspender escrituras en el entorno del ensayo, guardar
fuera del directorio de copia la huella SHA-256 que devolvió `audit` y usar:

```bash
cd symfony
php bin/console grindflow:vault:verify-stage \
  --directory=/volumen-privado-0700/copia-nueva \
  --expect=HUELLA_SHA256_GUARDADA
php bin/console grindflow:vault:verify-restore \
  --organization=UUID-ORGANIZACION \
  --directory=/volumen-privado-0700/copia-nueva \
  --expect=HUELLA_SHA256_GUARDADA \
  --confirm-writes-stopped
```

`verify-restore` verifica de nuevo toda la copia y compara su pertenencia a
la organización, cantidad de archivos, huella canónica del catálogo de la
base **restaurada** y tamaño/huella de **cada original restaurado**, incluidos
los que sigan en papelera. Repite las lecturas para detectar cambios durante
el ensayo; no ofrece una transacción de snapshot ni reemplaza la congelación
de escrituras. Una copia íntegra que no coincida con la DB/restauración es
**incompleta**, y viceversa. No se confía en un manifiesto editado sin la
huella independiente guardada antes de capturar la copia.

Salida JSON segura con `verified|incomplete|error`, recuento y huella, sin
nombres, claves, rutas ni excepciones internas. Exit `0` solo si coincide
todo; `2` para argumentos incorrectos o recuperación incompleta; `3`
para error operacional. El comando **no** extrae backups de MariaDB, no
restaura datos automáticamente, no mide las otras tablas/dependencias ni
certifica por sí solo un RPO/RTO. Anotar aparte la validación de identidad,
membresías, configuración/secretos recuperados, tiempo de recuperación y
prueba de acceso con credenciales sintéticas. No ejecutarlo como parte
de un deploy automático ni de Production Smoke.

### Operación pendiente antes de producción

Definir política aprobada de retención y eliminación, almacenamiento durable
con permisos/volumen verificables, backup cifrado de MariaDB y originales,
restauración integral ensayada (incluidos usuarios/tenants/permisos), rotación de
claves y monitoreo. Una copia en el mismo host no cubre pérdida de host o disco.
