# Guía de uso de GrindFlow para creadores y equipo del piloto

Esta guía describe **pantallas que existen en el código**, no una promesa de que estén activas en el sitio público. GrindFlow está en preparación y conviven pantallas del sistema actual con una transición técnica en curso. El equipo del piloto debe comprobar qué está habilitado para cada organización antes de ofrecerlo. **En ensayos y pruebas utiliza únicamente datos y archivos ficticios: una aprobación no permite usar datos o archivos reales para probar.** Este documento no autoriza altas, publicaciones externas ni el inicio del piloto.

## 1. Entrar y consultar la actividad

### Para qué sirve
Acceder al espacio de trabajo y consultar las organizaciones y próximas publicaciones visibles para tu cuenta.

### Antes de empezar
Necesitas acceso autorizado y una organización asignada por el equipo responsable. La guía no proporciona cuentas ni contraseñas.

### Cómo se usa (paso a paso)
1. En la pantalla de acceso, completa «Correo» y «Contraseña» y pulsa «Entrar al workspace».
2. En el menú, abre «Resumen». La pantalla «Tu operación» muestra las organizaciones a las que tienes acceso.
3. Revisa «Contenido listo» y «Publicaciones programadas». Si aparece tu organización, puedes usar «Abrir biblioteca →» para ir a sus archivos.
4. Para salir, pulsa «Cerrar sesión».

### Avisos y mensajes
«Aun no hay organizaciones disponibles.» indica que la cuenta todavía no tiene organizaciones asignadas. Si no aparece una función, no des por hecho que tu rol puede usarla.

### Limitaciones
Los contadores pueden mostrar que un módulo no está disponible. El resumen no demuestra que algo se haya publicado ni que el sitio esté preparado para operar con personas reales.

## 2. Biblioteca: consultar y cargar archivos

### Para qué sirve
Ver las imágenes y videos registrados para una organización y, con el permiso necesario, añadir archivos.

### Antes de empezar
Abre una organización desde el resumen y entra a «Biblioteca». Para ensayos usa siempre archivos y datos ficticios, incluso si se autorizó el ejercicio; no pruebes con archivos reales.

### Cómo se usa (paso a paso)
1. Revisa «Recent media» para ver los archivos y su estado.
2. Si puedes cargar archivos, usa «+ Add media» para ir a la zona de carga.
3. En «Quick upload», elige una imagen o video mediante «Selecciona una imagen o video» y confirma con «Quick upload». La pantalla indica un máximo de 8 MB.
4. «Direct upload» permite archivos más grandes **solo si** el almacenamiento correspondiente está configurado. No lo supongas disponible.
5. «Cloud sources» muestra las opciones de conexión solo cuando están habilitadas; no conectes cuentas externas durante un ensayo sin autorización.

### Avisos y mensajes
«El Vault esta vacio.» significa que todavía no hay archivos registrados. «Direct upload aun no esta configurado.» indica que la carga directa no se puede usar en esa instalación.

### Limitaciones
La carga y las conexiones dependen de permisos y configuración. Que un archivo figure en la biblioteca no significa que haya sido aprobado para publicarse.

## 3. Programación: preparar una fecha

### Para qué sirve
Preparar y consultar publicaciones de archivos aptos para un destino configurado.

### Antes de empezar
Entra a «Programación». Deben existir archivos aptos, destinos activos y los permisos necesarios. El piloto no autoriza publicación automática en plataformas externas.

### Cómo se usa (paso a paso)
1. Abre «New schedule» si aparece el formulario.
2. Selecciona un archivo en «Media» y un destino en «Destinations».
3. Indica la fecha y hora en «Local date and time», elige «Timezone» y pulsa «Schedule» para registrar la programación.
4. Revisa la agenda «Schedule calendar · UTC». La vista previa usa el horario universal; en la tabla, «Local time» usa la zona indicada en «Timezone» para cada programación.
5. Si necesitas cambiar una programación futura y el control está habilitado, usa «New local time» y «Save»; comprueba el resultado en la agenda.

### Avisos y mensajes
«Scheduling migration required.» significa que faltan preparativos de la instalación; no intentes forzarla. También pueden faltar archivos aptos o destinos.

### Limitaciones
Una programación no confirma una entrega. El equipo debe verificar cualquier paso de publicación real mediante sus controles y autorizaciones independientes.

## 4. Distribución: revisar entregas de prueba

### Para qué sirve
Consultar el estado de las entregas asociadas a programaciones y los intentos registrados.

### Antes de empezar
Abre «Distribución» en una organización para la que tengas acceso. Esta guía cubre únicamente el circuito de pruebas.

### Cómo se usa (paso a paso)
1. En «Delivery history», revisa el archivo, destino, estado e intentos de cada entrada.
2. Si tienes permiso para administrar destinos, «Sandbox destinations» permite preparar un destino de ensayo mediante «Create sandbox destination».
3. Para buscar entregas, usa «Apply filters» con los filtros visibles.
4. «Retry» aparece solo en ciertos estados de fallo y con permiso para gestionar destinos. Durante el piloto, el responsable debe decidir antes de repetir un intento.

### Avisos y mensajes
«Distribution migration required.» indica que la vista está bloqueada hasta completar la preparación necesaria. «No deliveries match.» indica que no hay resultados para la consulta.

### Limitaciones
Los destinos de ensayo no publican externamente. El historial puede reflejar estados internos; no lo interpretes como comprobante de publicación real.

## 5. Tráfico: enlaces y consultas de prueba

### Para qué sirve
Preparar enlaces para observar visitas y consultar los resultados permitidos para una organización.

### Antes de empezar
Abre «Tráfico» con un rol autorizado. Para pruebas utiliza únicamente destinos y campañas ficticios.

### Cómo se usa (paso a paso)
1. Si tienes permiso, abre «New tracked link».
2. Completa «Label», «Destination URL», «Channel» y «Campaign», según corresponda, y guarda el enlace con el control del formulario.
3. Busca los enlaces en «Tracked links» y revisa su estado antes de compartir nada.
4. En «Traffic analytics», ajusta las fechas y pulsa «Apply filters».
5. «Export daily CSV» ofrece una descarga de los resultados visibles cuando la función está habilitada.

### Avisos y mensajes
«Traffic migration required.» indica que faltan preparativos. «No matching tracked links.» significa que no hay enlaces para los filtros elegidos.

### Limitaciones
Las cifras pueden ser sintéticas o inexistentes. No difundas enlaces de ensayo ni atribuyas resultados a personas reales. La interfaz no garantiza que se estén midiendo campañas en producción.

## 6. Finanzas: revisión y registros de ensayo

### Para qué sirve
Consultar registros financieros separados por organización y, si existe permiso, preparar movimientos de prueba.

### Antes de empezar
Abre «Finanzas» solo con autorización del responsable. Usa importes y conceptos ficticios; nunca asientes ingresos reales durante pruebas.

### Cómo se usa (paso a paso)
1. En «Reconciliation · event dates (UTC)», ajusta moneda, beneficiario y fechas y pulsa «Apply reconciliation filters».
2. Consulta «Currency totals · matching events» y «Beneficiary reconciliation» para ver los resultados.
3. Si tu permiso lo permite, abre «New revenue allocation» y completa «Source», «Amount (minor units)», «Currency», «Occurred on» y «Beneficiary».
4. Antes de usar «Record allocation», confirma con el equipo que se trata de un ejercicio autorizado y de una instalación de pruebas.
5. Revisa «Revenue ledger» para comprobar que el registro aparece.
6. Si debes corregir un registro ficticio y el equipo lo autoriza, búscalo en «Revenue ledger», completa «Reversal reason» y pulsa «Reverse».

### Avisos y mensajes
«Finance migration required.» señala que la sección aún no está preparada. «No currency totals yet.» indica que no hay totales visibles.

### Limitaciones
Los movimientos no se editan ni borran: la reversa conserva el asiento original y se contabiliza en su propia fecha UTC. Un registro de prueba no es una factura, transferencia ni conciliación bancaria.

## 7. Carga de invitado por enlace

### Para qué sirve
Permitir que una persona invitada revise fotos o videos antes de enviarlos al espacio indicado por un enlace autorizado.

### Antes de empezar
El equipo debe proporcionar un enlace válido de prueba. No generes ni compartas enlaces reales sin autorización.

### Cómo se usa (paso a paso)
1. Abre el enlace facilitado por el equipo. La pantalla muestra «Selecciona, revisa y envía.».
2. Elige archivos con «Agregar fotos o videos».
3. Revisa «Archivos listos para enviar» y retira cualquier archivo equivocado.
4. Cuando la selección sea válida y estés autorizado, pulsa «Enviar archivos».
5. Confirma el mensaje de recepción antes de cerrar la página.

### Avisos y mensajes
«Este enlace de carga ya no está disponible.» significa que no se puede continuar con ese enlace. Las advertencias de archivos rechazados aparecen antes del envío.

### Limitaciones
Los enlaces tienen restricciones de uso y tamaño. La carga no autoriza a reutilizar ni publicar el contenido. No pidas fotos privadas para probar el flujo.

## 8. Equipo del piloto: estado y atención de incidencias

### Para qué sirve
Ayudar al equipo autorizado a comprobar si hay módulos pendientes y a informar de errores sin dar por iniciada la operación real.

### Antes de empezar
Solo las cuentas de administración de plataforma pueden ver «Sistema» y «Diagnósticos». El personal del piloto no debe intentar cambios de instalación ni acceso a datos reales.

### Cómo se usa (paso a paso)
1. Si tienes autorización de administración, abre «Sistema» para consultar el estado de los módulos.
2. Revisa la sección «Workspace module readiness» antes de decirle a un creador que una función está disponible.
3. En «Diagnósticos», consulta «Recent incidents» para ver si hay errores registrados.
4. Si hay un fallo, comunica al equipo responsable el momento, la pantalla y el mensaje visible, sin compartir claves, datos privados ni archivos.
5. Sigue el procedimiento interno de incorporación del piloto; ninguna guía sustituye sus aprobaciones.

### Avisos y mensajes
«No hay errores 5xx registrados.» solo significa que esa lista está vacía; no prueba por sí solo que todo funcione. El estado de un módulo puede impedir el uso de una pantalla.

### Limitaciones
No pulses acciones de migración o mantenimiento sin autoridad específica. El piloto requiere decisiones humanas, validación jurídica y pruebas separadas; este manual no habilita invitados, gasto, despliegues ni publicación real.

---

**Alcance del manual:** interfaz y rutas verificadas en el código de GrindFlow (vistas del espacio de trabajo, carga invitada y administración) y reglas del [guion del piloto](../launch/onboarding-pilot-five.md). No contiene usuarios, contraseñas, correos reales, imágenes inventadas ni capturas. Cuando una opción no esté visible, su disponibilidad depende de rol, configuración o preparación de la instalación.
