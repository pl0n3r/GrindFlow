# Recovery productivo pre-piloto

Este procedimiento acredita **backup cifrado + evidencia + restore drill** para GrindFlow antes del piloto. No autoriza un restore destructivo sobre producción y no sustituye una decisión humana de incidente.

## Autoridad y precondiciones

El workflow `GrindFlow Production Backup` es owner-only y se ejecuta por `workflow_dispatch`. Antes de cualquier I/O valida el SHA exacto del release productivo mediante `.release-sha`, el fingerprint de migraciones, SSH estricto, PHP 8.5, MariaDB tooling, Vault staging y libsodium secretstream.

La key de recuperación se entrega como GitHub Actions secret `PRODUCTION_RECOVERY_KEY_B64` y existe dentro del runtime de cifrado únicamente como `GF_RECOVERY_KEY_B64`. Debe decodificar exactamente 32 bytes. Nunca se pasa como argumento CLI, se escribe en Issues, summaries, artifacts o repo, ni se conserva junto al ciphertext.

La evidencia del propietario confirmó libsodium secretstream en el CLI PHP 8.5 del host. El workflow vuelve a comprobar extensión y funciones en cada ejecución y falla cerrado si dejan de estar disponibles.

## Formato de recuperación

El formato `grindflow-recovery-v1` contiene, antes del cifrado:

- `database.sql.gz`: dump MariaDB consistente;
- `vault/<organization UUID>/`: stages privados producidos por `grindflow:vault:stage`;
- `vault-index.json`: índice canónico por organización con `manifest_sha256` y conteos;
- `metadata.json`: versión/SHA exactos, timestamp UTC, fingerprint DB y `vault_index_sha256`.

El tar plaintext es temporal, privado y se elimina inmediatamente después de promover el ciphertext autenticado. El ciphertext `.gfrec` usa libsodium secretstream XChaCha20-Poly1305 con frames autenticados y `TAG_FINAL` obligatorio. Truncado, modificación, key incorrecta, tag inesperado o runtime criptográfico ausente fallan cerrado.

`VerifiedBackupEvidence` conserva su responsabilidad original para el gate de migraciones. `VerifiedRecoveryEvidence` es independiente y liga ciphertext SHA-256, fingerprint DB, Vault index SHA-256, release version/SHA, timestamp y conteos. No contiene nombres originales, notas privadas, storage keys, paths absolutos ni secretos.

## Evidencia segura

El summary de GitHub puede publicar únicamente:

- migration fingerprint;
- ciphertext SHA-256;
- Vault index SHA-256;
- recovery receipt id;
- número de organizaciones y assets;
- estado del workflow/run.

No publicar rutas del host, manifests, contenido del backup, `.env`, credenciales MariaDB, `GF_RECOVERY_KEY_B64`, nombres de archivos Vault ni PII.

Los backups reales permanecen server-side. El workflow no usa `actions/upload-artifact` para material de recovery.

## Restore drill

El restore automatizado permitido es solo un **entorno descartable** de CI con datos sintéticos:

1. crear DB + Vault sintéticos;
2. stage/verify del Vault;
3. crear el mismo `grindflow-recovery-v1`;
4. cifrar con `scripts/recovery-secretstream.php`;
5. probar rechazo de ciphertext alterado, truncado y key incorrecta sin plaintext residual;
6. eliminar fuente plaintext;
7. descifrar el ciphertext válido;
8. verificar bundle e índice Vault;
9. restaurar MariaDB;
10. restaurar blobs y ejecutar `grindflow:vault:verify-restore`;
11. validar schema parity;
12. ejecutar guardas tenant/IDOR existentes.

Un drill verde prueba el procedimiento y el formato; no autoriza restauración productiva.

## Restore productivo

Un restore destructivo requiere incidente confirmado, aprobación explícita del propietario y una ventana operativa. Antes de tocar producción:

1. identificar un receipt/ciphertext cuyo SHA, release SHA y fingerprints sean coherentes;
2. verificar disponibilidad de la key fuera de GitHub Issues/logs;
3. restaurar primero en entorno descartable o aislado y completar todas las verificaciones;
4. detener escrituras de aplicación;
5. conservar evidencia previa y plan de rollback;
6. solo entonces ejecutar el procedimiento aprobado para DB y Vault;
7. comprobar schema, manifests, tenant isolation, health y smoke exact-SHA antes de reabrir escrituras.

No se automatiza restore productivo desde este repositorio.

## Fallo, rollback y escalamiento

Si falla staging, checksum, cifrado, receipt, decrypt, manifest, schema parity o aislamiento tenant, no promover el backup como evidencia válida. Eliminar temporales plaintext; conservar únicamente ciphertext previamente verificado cuando exista receipt válido.

Si un restore descartable falla, no reintentar sobre producción. Registrar solo códigos/hashes allowlisted, corregir la causa y repetir el drill.

Escalar al propietario antes de cualquier acción destructiva, cambio de key, cambio de proveedor/almacenamiento, gasto, pérdida de evidencia o decisión de retención. Un merge, una versión o un ciphertext existente por sí solos no significan recovery readiness.
