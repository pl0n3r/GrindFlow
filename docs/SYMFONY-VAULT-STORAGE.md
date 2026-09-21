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
no se siguen enlaces simbólicos como raíz configurada, no se aceptan rutas
relativas ni segmentos `.` o `..` y no se acepta una ubicación dentro
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

## Límites y estado

Esta opción es preparación técnica S2, no un cutover ni un deploy.
Symfony y su MariaDB siguen aislados; Production Smoke de Laravel y
el marcador del Observer no prueban que el storage Symfony funcione en
Hostinger. No activar variable, migraciones o movimiento de archivos
productivos por efecto de un PR de código.
