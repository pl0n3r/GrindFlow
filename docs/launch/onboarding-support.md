# GrindFlow — onboarding y soporte inicial (pre-live)

**Estado:** preparación en fase construcción; NO se ofrece todavía un servicio de soporte comercial ni se autoriza go-live.
**Origen:** issues #439 y #351. Puertas relacionadas: piloto #271, recuperación #311 y decisión de versión/live #356.
**Owner del documento:** rol Producto. Persona asignada, aprobación, canal público y fecha de revisión: UNKNOWN.

## Onboarding: independiente

**Owner de proceso:** rol Producto; persona asignada: UNKNOWN.
**Proceso propuesto, con fixtures antes de clientes reales:**
1. Confirmar invitación, permisos de cuenta y aislamiento por tenant con pruebas sintéticas; alta pública: NO HABILITADA.
2. Explicar derechos de uso de contenidos, credenciales protegidas y revisión editorial; nunca pedir secretos por un canal de soporte.
3. Demostrar Biblioteca, propuestas de programación y controles de aprobación en entorno aislado, sin redes sociales reales.
4. Registrar checklist de comprensión, incidencias y consentimiento aplicable con identificadores sintéticos; canal y responsable operativo: UNKNOWN.
5. Solicitar confirmación explícita de finalización al propietario de la cuenta solo tras superar las puertas de seguridad; evidencia piloto #271: UNKNOWN.

## Onboarding: Studio

**Owner de proceso:** rol Producto con rol Administrador Studio; personas asignadas: UNKNOWN.
**Proceso propuesto, con fixtures antes de clientes reales:**
1. Verificar tenant Studio, creadores y separación de acceso por rol; no usar perfiles ni datos de clientes.
2. Configurar roles editor/reviewer/producer y aprobación previa según política de Studio; ninguna aprobación se infiere del modo piloto.
3. Ensayar calendario multi-creador, límites y capacidad del equipo con datos sintéticos; no invocar Scheduler/Distribution productivos.
4. Simular rechazo, revocación, escalamiento y auditoría de cambios; verificar que faltantes de rol bloquean el flujo.
5. Registrar observaciones para el piloto #271 y validación de seguridad; asignación del administrador y resultado: UNKNOWN.

## Soporte: responsables, canales y proceso

**Owner de proceso:** rol Soporte operativo; suplencia: rol Producto. Personas y cobertura horaria: UNKNOWN.
**Canales:** canal de recepción = UNKNOWN; canal de incidentes críticos = UNKNOWN; no publicar direcciones, números ni proveedores ficticios.
**Proceso de soporte propuesto:**
1. Recepción: generar referencia opaca del caso; no solicitar tokens, contenido privado ni identificadores personales en tickets públicos.
2. Triage: categorizar bloqueo, seguridad/privacidad o consulta; un incidente P0/P1 bloquea cualquier decisión go.
3. Asignación: Soporte operativo asigna responsable de investigación y escalamiento a Seguridad/SRE cuando corresponda.
4. Seguimiento: informar estado y próxima revisión por el canal acordado; conservar evidencias sanitizadas y fecha de comprobación.
5. Cierre: verificar reproducción, corrección o workaround seguro, confirmación del solicitante y retroalimentación a Producto.
**Operación efectiva:** UNKNOWN hasta confirmar personal, canales, cobertura, protocolo de privacidad y resultados del piloto.

## SLAs iniciales — propuesta, NO aprobación contractual

Estas cifras son **hipótesis de planificación**, no compromisos vigentes ni resultados medidos. La cobertura, calendario hábil, tarifas y owner humano requieren decisión posterior; todos los SLA permanecen PROPUESTA_NO_APROBADA.

| Clase | Objetivo de primera respuesta propuesto | Fuente | Estado | Owner de revisión |
| --- | --- | --- | --- | --- |
| P0 incidente de seguridad | 4 horas hábiles (propuesta) | Diseño #351; medición piloto UNKNOWN | PROPUESTA_NO_APROBADA | Seguridad y Soporte |
| P1 servicio bloqueado | 1 día hábil (propuesta) | Diseño #351; medición piloto UNKNOWN | PROPUESTA_NO_APROBADA | Soporte y SRE |
| P2 consulta general | 3 días hábiles (propuesta) | Diseño #351; medición piloto UNKNOWN | PROPUESTA_NO_APROBADA | Soporte y Producto |

No existe SLA contractual ni promesa de disponibilidad. Medir capacidad, fijar horarios, asignar responsables reales y obtener aprobación independiente antes de cambiar cualquier propuesta a compromiso.

## Puerta go/no-go de preparación (NO es go-live)

| Criterio | Evidencia | Estado | Owner por rol | Frescura |
| --- | --- | --- | --- | --- |
| Piloto con resultados verificables #271 | UNKNOWN | UNKNOWN | Producto | UNKNOWN |
| Prueba de recovery/rollback #311 | UNKNOWN | UNKNOWN | SRE | UNKNOWN |
| Seguridad, permisos y ausencia P0/P1 | UNKNOWN | UNKNOWN | Seguridad | UNKNOWN |
| Canales y personal de soporte operativos | UNKNOWN | UNKNOWN | Soporte operativo | UNKNOWN |
| SLAs ratificados y cobertura medible | UNKNOWN | UNKNOWN | Producto y Soporte | UNKNOWN |
| Costos de storage, colas y operación #351 | UNKNOWN | UNKNOWN | Finanzas/Producto | UNKNOWN |
| Evidencia legal/privacidad y proveedores | UNKNOWN | UNKNOWN | Legal/Privacidad | UNKNOWN |

**Resultado go/no-go:** NO-GO mientras cualquiera de los siete criterios obligatorios esté UNKNOWN, falte uno, aparezca duplicado, existan incidentes P0/P1 abiertos o no haya evidencia fresca. UNKNOWN nunca significa cero, listo ni aprobado. El contrato offline rechaza cualquier fila `EVIDENCIADO`: autenticar evidencia, fecha y autoridad requiere una puerta independiente, no editar este documento. Este checklist no dispara publicaciones.

## Autorización de live — decisión humana posterior y separada

Aunque la preparación alcance go, **solo una instrucción explícita del propietario** puede aprobar versión 1.0.0, cambio de fase, publicación o go-live, bajo #356 y demás puertas aplicables. La decisión debe registrarse con fecha, SHA exacto, responsable, evidencias operativas y verificación independiente del despliegue. Un CI verde o este documento NO autorizan datos reales, gastos, proveedores ni activación.

**Estado actual de live:** NO AUTORIZADO. **Versión y ambiente productivo:** no inferidos desde este documento.
