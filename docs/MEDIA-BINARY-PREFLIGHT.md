# Preflight local de ffmpeg y ffprobe

## Propósito

python3 scripts/media-binary-preflight.py ejecuta una inspección **local y no autoritativa** de dos nombres fijos: ffmpeg y ffprobe. Este diagnóstico del roadmap #345 no cambia la aplicación ni la configuración y no accede a Hostinger, buckets, objetos, credenciales o servicios de red.

El comando busca cada binario mediante shutil.which y ejecuta directamente -version (sin shell) con un timeout fijo de 3 segundos. Captura la salida solo para rechazar anomalías, **nunca** la muestra ni la conserva. Rechaza una ruta cuyo nombre no corresponda exactamente al binario esperado. El retorno JSON incluye exclusivamente contract, environment: local, target_verified: false y los estados por nombre available | unavailable | unknown.

- available: ruta local esperada y proceso -version exitoso con salida acotada.
- unavailable: no existe el binario en el PATH de este proceso o devuelve código de error.
- unknown: timeout, excepción, identificador o resultado anómalo, salida excesiva u otra evidencia insuficiente.

Exit code **0** significa que ambos son available **solo en el equipo local**. Exit code **2** representa cualquier otra combinación. Ningún resultado declara capacidad del servidor objetivo, vigencia de artefactos multimedia ni readiness de producción.

## Pruebas reproducibles

python3 -m unittest -v tests/test_media_binary_preflight.py o python3 tests/test_media_binary_preflight.py. Las pruebas simulan shutil.which y subprocess.run; no dependen de la instalación local de FFmpeg. Cubren éxito acotado, ausencia, fallo, permisos, timeout, anomalías, salidas demasiado grandes, programas inesperados y ausencia de filtración por el CLI.

## Requisitos para verificar hosting de verdad

La operación productiva exige permiso explícito y evidencia independiente del entorno real: identidad del host y despliegue, ejecución autorizada de ambos binarios en ese host con timeout y comprobación de políticas, recursos, límites, versión operativa y eventuales fallbacks; registro auditable sin secretos. No copiar resultados locales a campos de producción, ni declarar MEDIA_STORAGE_READY, CORS, direct upload o /health como exitosos por este preflight. Este documento no solicita ni habilita deploy, gasto, secretos, red o cambios de datos.
