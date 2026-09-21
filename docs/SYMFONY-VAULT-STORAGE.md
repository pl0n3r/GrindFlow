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
