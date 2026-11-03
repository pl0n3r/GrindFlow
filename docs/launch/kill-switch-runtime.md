# Interruptor de emergencia Symfony — preparación offline (#877)

Estado: **NO LIVE**. La migración no está autorizada para producción y no debe ejecutarse
en Hostinger ni con información real. La ausencia de la fila \`gf_emergency_stop\`,
la pérdida de conexión, un esquema incompleto o un error son **STOP**, nunca RUNNING.

El contrato almacena una revisión monotónica y un registro append-only de transiciones.
STOP se confirma en una transacción antes de revocar credenciales/sesiones. Una
revocación parcial deja la plataforma en STOP; el trabajo de revocación debe poder
reanudarse sin exponer secretos ni repetir efectos completados. RUNNING exige
reanudación explícita por un administrador autenticado y revocación completada.
Los lectores deben verificar la revisión de nuevo antes de cada efecto externo
y al reservar contenido. Ningún timeout o reinicio restablece RUNNING.

**Implementación en progreso:** el store y su migración son un bloque preparatorio;
no se declara que las rutas operativas ni las fronteras de publicación estén
conectadas hasta que los tests PHP reales y el CI exact-SHA lo demuestren.

Rollback: revertir el commit de código; en una base **desechable** con
\`APP_ENV=test\` y nombre \`grindflow_symfony_ci\`, se permite revertir la
migración. En un entorno real se conserva el audit y no se borra automáticamente.
