# GrindFlow - respaldo diario de produccion: preparacion inactiva (#780)

**OFF / NO-GO.** El nuevo workflow production-backup-scheduled.yml tiene
workflow_dispatch, pero su schedule diario esta comentado. El job exige a la
vez evento schedule y la variable GRINDFLOW_DAILY_BACKUP_ENABLED=true.
Por ello ni el merge ni la ejecucion manual activan un backup o SSH productivo.

## Encendido posterior exclusivamente por el owner (otro PR, fase live)

1. Validar restauracion sintetica, espacio disponible, cifrado, acceso al
   almacenamiento privado persistente entre releases y aprobacion explicita
   de la poda irreversible de backups SQL de mas de 30 dias.
2. Crear/proteger el ambiente GitHub production-backup. Provisionar solo
   en GitHub Secrets DEPLOY_SSH_KEY y HOSTINGER_KNOWN_HOSTS, sin copiar
   valores, passwords, claves de restore o SQL al repositorio o artefactos.
3. Definir variables HOSTINGER_SSH_HOST, HOSTINGER_SSH_USER,
   HOSTINGER_SSH_PORT, HOSTINGER_RELEASE_ROOT, BACKUP_EXPECTED_SHA
   (40 hex del release desplegado; actualizar despues de cada despliegue
   antes del siguiente backup) y BACKUP_EXPECTED_PENDING (entero
   positivo que coincida con las migraciones realmente pendientes).
   El script canónico run-production-backup.sh rechaza EXPECTED_PENDING=0
   en modo base de datos. Si hay **cero** pendientes, NO-GO: resolver
   esta limitacion con un PR y claim separados antes de activar cron;
   no falsear el contador para obtener un PASS.
4. Descomentar schedule con cron '17 3 * * *' UTC, mediante PR revisado,
   y solo entonces configurar GRINDFLOW_DAILY_BACKUP_ENABLED=true
   como variable de REPOSITORIO u ORGANIZACION, nunca solo en el ambiente
   production-backup: GitHub evalua el guard antes de cargar ese ambiente.
   Supervisar tambien ausencia de ejecucion: Actions puede retrasar u
   omitir eventos programados y no constituye garantia de respaldo diario.

## Contrato futuro, NO ejecutado en #780

Se comparte la concurrencia grindflow-production-backup con el
workflow manual production-backup.yml, sin cancelar respaldos existentes.
Antes de la escritura se verifican current, releases/SHA y .release-sha.
Se invoca exclusivamente bash scripts/run-production-backup.sh con
RECOVERY_BACKUP_ENABLED=false; el script valida GZIP, registra el
backup privado y deja recibo canónico de DB en el servidor.

Luego se hace gzip -t, checksum SHA-256 con sidecar privado 0600 y
sha256sum -c. La retención de 30 días elimina solo archivos
pre-migration-*.sql.gz de más de 43200 minutos con sidecar SHA-256
válido; ignora recovery .gfrec, Vault, latest, receipts internos y
archivos antiguos sin sidecar o con checksum no verificable. Antes de
 eliminar una copia verifica el hash y el nombre exacto en el sidecar.
 No se suben respaldos a GitHub.
La ausencia de artefacto, SHA incorrecto, checksum alterado, credenciales
faltantes, cambio de release o UNKNOWN = NO-GO y alerta.

En caso de fallo se comenta o abre un Issue unico
[AUTO] GrindFlow Scheduled Backup Failure, solo con URL de run y texto
saneado; no se incluyen logs crudos, credenciales, paths ni datos SQL.
Una falla del backend de Issues no es motivo para decir que el backup
se completo: el check del workflow permanece fallido.

El recovery bundle cifrado y la congelacion de escrituras permanecen
manuales, bajo docs/PRODUCTION-RECOVERY.md y autorizacion especifica.
El merge de esta preparacion NO equivale a respaldo, restore probado,
activacion de schedule, provision de secretos ni go-live.

## Pruebas sin produccion

tests/test_production_backup_schedule.py expone
ProductionBackupScheduleTests.test_criterio_principal, verificando la
desactivacion, las guardas, SHA-256, retencion y alerta sin abrir redes
ni acceder a Hostinger. AC-02 requiere Factory CI reusable / Validar
terminal en verde sobre HEAD exacto.
