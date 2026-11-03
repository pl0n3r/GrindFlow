# Piloto GrindFlow — onboarding de los cinco primeros creadores

**Estado:** PRE-LIVE / borrador para revisión del dueño y asesoría jurídica. **NO-GO** para invitaciones, registro real, tratamiento de datos o activación del servicio. Relacionado con #271 y #800.

**Responsable del piloto:** [COMPLETAR POR EL DUEÑO]  
**Responsable de soporte y canal seguro:** [COMPLETAR POR EL DUEÑO]  
**Versión de términos y consentimiento validada por asesoría jurídica:** [COMPLETAR POR EL DUEÑO]  
**Fecha de aprobación y autorización explícita de live:** [COMPLETAR POR EL DUEÑO]

## 1. Mensaje de bienvenida — plantilla NO ENVIAR

**Asunto:** Bienvenida al piloto privado de GrindFlow

Hola, [COMPLETAR POR EL DUEÑO]:

Estamos preparando un piloto limitado de GrindFlow. Esta plantilla no constituye una invitación activa, oferta comercial ni autorización de uso. Si el dueño aprueba posteriormente el live, el equipo explicará el alcance, riesgos, controles de publicación, manejo seguro de contenido y canal de ayuda.

Antes de aceptar cualquier acceso se comunicarán las condiciones aprobadas por asesoría jurídica, el tratamiento de datos aplicable y la posibilidad de retirar el consentimiento cuando corresponda. No compartas contraseñas, tokens, documentos privados ni accesos a redes sociales por correo o soporte.

**Enlace de alta aprobado:** [COMPLETAR POR EL DUEÑO]  
**Canal y horarios de soporte:** [COMPLETAR POR EL DUEÑO]

**Estado de envío:** BLOQUEADO hasta aprobación del dueño, revisión jurídica y puerta de live.

## 2. Formulario de alta — diseño de campos, sin captura real

El formulario es una **especificación offline**: no está publicado ni recolecta información.

| Campo previsto | Tipo | Regla pre-live |
| --- | --- | --- |
| Identificador interno de plaza | Selección opaca (PILOTO-01…PILOTO-05) | Solo fixture sintético, sin nombre real |
| Nombre o alias del creador | Texto | [COMPLETAR POR EL DUEÑO] antes de usar datos reales |
| Correo de contacto | Correo | [COMPLETAR POR EL DUEÑO]; sin valores reales en el repositorio |
| Tenant/organización | Identificador opaco | Aislamiento por tenant verificado antes de live |
| Caso de uso y expectativas | Resumen voluntario | No recopilar contenido sensible |
| Aceptación informada de condiciones | Casilla inicialmente desmarcada | Requiere texto jurídico aprobado y versión identificable |
| Consentimiento de tratamiento de datos | Casilla independiente inicialmente desmarcada | Requiere finalidad, política, responsable y base legal aprobados |
| Preferencias de contacto opcional | Casilla independiente inicialmente desmarcada | Nunca premarcada ni obligatoria |
| Fecha/hora y versión del consentimiento | Registro de auditoría | Sincronizar solo en servicio autorizado, no en documento |

**Regla:** ninguna casilla premarcada; no interpretar ausencia, silencio o acceso técnico como consentimiento. Ante versión de términos desconocida, validación jurídica pendiente o falta de verificación del aislamiento: **NO-GO**. No se solicitan contraseñas de redes, cookies, claves API, tokens OAuth ni datos de terceros.

## 3. Checklist individual, cinco plazas sintéticas

Cada plaza debe tener su propia evidencia de consentimiento y seguridad, sin trasladar evidencia entre tenants. El siguiente registro es un esquema *sin datos personales*.

| Plaza ficticia | Alta habilitada | Consentimiento aprobado | Tenant verificado | Soporte designado | Decisión |
| --- | --- | --- | --- | --- | --- |
| PILOTO-01 | NO | PENDIENTE | PENDIENTE | PENDIENTE | NO-GO |
| PILOTO-02 | NO | PENDIENTE | PENDIENTE | PENDIENTE | NO-GO |
| PILOTO-03 | NO | PENDIENTE | PENDIENTE | PENDIENTE | NO-GO |
| PILOTO-04 | NO | PENDIENTE | PENDIENTE | PENDIENTE | NO-GO |
| PILOTO-05 | NO | PENDIENTE | PENDIENTE | PENDIENTE | NO-GO |

**Para cada plaza, antes del live:**

- [ ] El dueño autorizó explícitamente invitar a esa plaza concreta.
- [ ] Asesoría jurídica aprobó textos de condiciones, privacidad y consentimiento con versión y fecha.
- [ ] Existe un canal seguro de alta; no se envían secretos por mensajes.
- [ ] El creador aceptó por separado condiciones y consentimiento informado; se conserva evidencia de versión, fecha y retirada.
- [ ] El tenant propio solo ve datos propios; permisos y roles mínimos comprobados sin mezclar organizaciones.
- [ ] Se explicaron Biblioteca, programación, borradores, revisión humana y límites de publicación; nunca se activa publicación automática por el onboarding.
- [ ] Se probó la revocación de acceso, retirada de consentimiento y escalamiento al soporte.
- [ ] El responsable del piloto revisó resultados y registró decisión GO/NO-GO fuera de este repositorio, con evidencias protegidas.

## 4. Puertas de aprobación y reversión

**El documento no ejecuta el alta ni da permiso de invitar.** La decisión por defecto es NO-GO. El dueño debe aprobar la fase live conforme a las compuertas de Factory; las condiciones jurídicas las valida asesoría competente. Si falla consentimiento, aislamiento por tenant, revisión de seguridad o soporte, no se habilita ninguna plaza.

Ante incidente: suspender invitaciones, revocar acceso conforme a procedimiento aprobado, preservar únicamente evidencia sanitizada e informar al responsable designado. Reversión del cambio documental: revert del squash #800; ninguna acción sobre sistemas externos.

## 5. Ensayo sintético y aceptación

La prueba `tests/OnboardingPilotDocsTest.php` lee únicamente este archivo del repositorio; verifica cinco plazas ficticias únicas, texto de bienvenida, esquema de alta, casillas separadas sin premarcar, checklist, NO-GO y marcadores de aprobación. Comprueba también patrones de secretos y posibles direcciones de correo reales. No envía solicitudes, no llama a servicios y no almacena datos personales.

**Requisito de aceptación:** `Factory CI reusable / Validar` terminal verde sobre el HEAD exacto. Un test o una guía verde no sustituyen aprobación humana, asesoría jurídica, live ni pruebas reales del servicio.
