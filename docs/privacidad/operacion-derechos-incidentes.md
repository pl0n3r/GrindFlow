# Operación de derechos e incidentes de privacidad — pre-piloto

Estado operativo: `BLOCKED_REAL_DATA`  
Estado jurídico: `documented_not_legally_approved`  
Gate humano pre-live: `GrindFlow#185`

Este runbook prepara la operación técnica mínima de privacidad. No constituye opinión jurídica, no aprueba bases legales, consentimientos, retenciones o transferencias y no autoriza por sí mismo el uso de datos personales reales.

## Gate de datos reales

Mientras cualquiera de `datos.yml.controller.name`, `identifier`, `address` o `rights_email` conserve `[COMPLETAR POR EL DUEÑO]`:

- desarrollo, CI y fixtures sintéticos: permitido;
- piloto con datos personales reales: bloqueado;
- `APP_PHASE=live`: no autorizado por este runbook;
- el estado jurídico permanece `documented_not_legally_approved`;
- no se deben completar ni sustituir aquí los datos del responsable.

La habilitación de datos reales requiere un responsable/canal no-placeholder y la revisión humana exigida por `GrindFlow#185` para el alcance aplicable.

## Solicitudes de derechos

Tipos operativos contemplados:

- acceso;
- rectificación;
- portabilidad;
- eliminación.

### Recepción y trazabilidad mínima

Cuando exista un canal aprobado, registrar únicamente:

- `request_ref`: identificador opaco;
- `request_type`: uno de los tipos anteriores;
- `received_at`: timestamp;
- `status`;
- `assigned_role`: rol responsable, no identidad personal si no es necesaria;
- `closed_at`: solo al cierre;
- `outcome_code`: código mínimo de resultado;
- `evidence_ref`: referencia opaca a evidencia conservada en un sistema privado autorizado.

Estados permitidos:

`received → verification_required|in_review → fulfilled|rejected|legal_review_required`

GitHub/Issues/logs: prohibido copiar PII, documentos de identidad, contenido de la solicitud, payload personal, secretos, tokens o credenciales.

### Identidad y autorización

Antes de ejecutar acceso, rectificación, portabilidad o eliminación, verificar identidad y representación por un canal privado aprobado. Si identidad, representación o alcance no son suficientes, mover a `verification_required` y no ejecutar cambios.

### Ejecución y cierre

- aplicar mínimo privilegio y alcance tenant-scoped;
- no reutilizar datos de otro tenant para responder una solicitud;
- si base jurídica, retención, excepción, borrado o alcance son inciertos, usar `legal_review_required` y detener la acción afectada;
- al cerrar, conservar solo estado, timestamps, códigos mínimos y referencias opacas;
- no copiar al sistema de coordinación los datos entregados, corregidos, portados o eliminados;
- este runbook no inventa plazos legales ni compromisos de respuesta.

## Runbook mínimo de incidente de privacidad

1. **Detectar**: clasificar la señal y delimitar el componente/tenant potencialmente afectado sin copiar contenido sensible a GitHub.
2. **Contener**: detener la acción o flujo afectado y, cuando sea reversible y autorizado, revocar acceso o aislar el artefacto.
3. **Preservar evidencia segura**: conservar identificadores opacos, hashes, timestamps, tenant/ref técnica y logs minimizados en superficies autorizadas.
4. **Escalar**: usar el responsable operativo cuando exista. Si el responsable sigue placeholder o el alcance es incierto, el tratamiento afectado permanece detenido.
5. **Revisión jurídica**: exposición potencial, notificación, retención/borrado dudosos, terceros/proveedores o cualquier obligación no demostrada pasan a `legal_review_required`.
6. **Reanudar**: solo cuando la causa técnica esté contenida y exista autoridad explícita para el alcance aplicable.

Fail-closed: si no puede demostrarse con evidencia mínima segura que el incidente está acotado, no se presume seguridad ni cumplimiento y no se reanuda el tratamiento afectado.

## Fronteras pre-live

- `datos.yml` conserva `phase=construccion`.
- Las bases y consentimientos documentados permanecen `review_required`.
- Las retenciones permanecen `review_required` salvo la retención técnica ya observada de `traffic_dedupe`.
- Los documentos generados de Privacy-as-Code siguen siendo técnicos y no jurídicamente aprobados.
- `GrindFlow#185` sigue siendo el gate humano pre-live; este runbook no lo sustituye ni lo debilita.
- Portal DSAR self-service, legal hold y compliance avanzado son POST-MVP salvo nueva decisión explícita.
