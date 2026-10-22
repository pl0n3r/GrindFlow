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

## Autoridad temporal y plazos

Este procedimiento no define SLA ni plazo jurídico de respuesta. Cualquier plazo, excepción, obligación de notificación o compromiso frente al titular debe provenir de la revisión humana aplicable; el software no los infiere ni los completa por defecto.

## Solicitudes de derechos

Tipos operativos contemplados (sujetos a revisión jurídica humana):
- acceso;
- rectificación/corrección;
- actualización;
- portabilidad;
- supresión/eliminación;
- revocación del consentimiento, cuando corresponda.

El `request_type` **documental** admite exactamente los valores `access`,
`rectification`, `update`, `portability`, `deletion` y `revocation`.
«Corrección» es sinónimo de rectificación y «eliminación» de supresión;
actualización y revocación son mecanismos separados, no se omiten por sinónimos.
Este catálogo no afirma que exista un formulario, API ni enum de producción.

### Recepción y trazabilidad mínima

Cuando exista un canal aprobado, registrar únicamente:

- `request_ref`: identificador opaco;
- `request_type`: uno de `access|rectification|update|portability|deletion|revocation` del catálogo documental anterior;
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

Antes de tramitar cualquiera de los seis mecanismos (acceso, rectificación/corrección, actualización, portabilidad, supresión/eliminación o revocación), verificar identidad, legitimación y representación según corresponda por un canal privado aprobado. Si identidad, representación o alcance no son suficientes, mover a `verification_required` y no ejecutar cambios ni divulgar datos. Revocar consentimiento no implica automáticamente suprimir datos.

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

## Handoff del paquete jurídico tras alinear las fuentes

La coherencia de los seis mecanismos y del catálogo **documental** de `request_type` exige contrastar **tres** documentos: este runbook, `docs/privacidad/canal-derechos.md` y `docs/privacidad/politica-tratamiento.md`. Los dos últimos son **salidas generadas** por el kit de privacidad de Factory a partir de `datos.yml`: no corregirlas a mano ni aceptar un canal/política que omita portabilidad. Verificar identidad y legitimación sigue siendo obligatorio; el texto no promete una API de exportación ni una aprobación jurídica.

La plantilla de los seis mecanismos está fijada en el kit inmutable de Factory `7d520aa7bede0f19d2ca43852212fd607f9e94b4`, consumido por `.github/workflows/privacidad.yml` y `.github/workflows/grindflow-ci.yml`. Los derivados `docs/privacidad/canal-derechos.md` y `docs/privacidad/politica-tratamiento.md` se generan byte-exactos desde `datos.yml` con el mismo kit; no deben editarse a mano. La coherencia de los **seis derechos** y de los valores documentales `request_type` en los tres archivos debe comprobarse con `Privacidad como código`, tests PHP y `validate` en el HEAD definitivo. Un CI verde no acredita aprobación legal ni un canal de derechos activo en producción.

El generador del expediente para abogado **ya está integrado** por GrindFlow #493/PR #498. Una vez fusionadas las fuentes definitivas, ejecutar `python3 scripts/generar_revision_juridica.py` **solamente** en el PR de regeneración con reserva V3 independiente para `revision-juridica/`, seguido de `python3 scripts/generar_revision_juridica.py --check`. Verificar las copias byte-exactas, `00-LEEME.md`, la huella de origen y `paquete-completo.md`. El workflow `revision-juridica-sync.yml` detecta desfase tras cambios en `main` y abre un Issue deduplicado con etiqueta `revision-juridica-desfase`; no escribe en `main`. No modificar copias del abogado en este PR ni presentar el paquete como aprobado. Estado hasta completar ese handoff: `documented_not_legally_approved` y `BLOCKED_REAL_DATA`.

## Fronteras pre-live

- `datos.yml` conserva `phase=construccion`.
- Las bases y consentimientos documentados permanecen `review_required`.
- Las retenciones permanecen `review_required` salvo la retención técnica ya observada de `traffic_dedupe`.
- Los documentos generados de Privacy-as-Code siguen siendo técnicos y no jurídicamente aprobados.
- `GrindFlow#185` sigue siendo el gate humano pre-live; este runbook no lo sustituye ni lo debilita.
- Portal DSAR self-service, legal hold y compliance avanzado son POST-MVP salvo nueva decisión explícita.
