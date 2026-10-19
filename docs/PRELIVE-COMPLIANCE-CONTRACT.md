# GrindFlow · diagnóstico offline de requisitos pre-live (#502)

Este módulo implementa exclusivamente la hoja de [requisitos #354](https://github.com/pl0n3r/GrindFlow/issues/354), relacionada con [privacidad como código #149](https://github.com/pl0n3r/GrindFlow/issues/149). No sustituye el expediente ni el abogado: la revisión jurídica humana sigue obligatoria antes de live y la carpeta `revision-juridica/` conserva `documented_not_legally_approved`.

## Contrato de entrada (schema_version 1)

`evaluate(facts)` en `scripts/prelive_compliance_gate.py` recibe únicamente indicadores *declarados por otro sistema*, **no** evidencia autenticada independiente. No consulta red, DB, secretos ni proveedores, y nunca guarda PII. Las referencias `ref:...` son identificadores opacos sintéticos/enlace a evidencia, no archivos, documentos de identidad ni certificados de verdad. Un consumidor futuro deberá validar origen, vigencia, titular/tenant y rol antes de confiar en cualquier indicador: este módulo **no autoriza** publicación ni acción de runtime.

- `consent`: `status=VERIFIED`, referencia y `revoked=false` booleano explícito; cualquier revocación, `UNKNOWN` o falta de evidencia bloquea.
- `ownership`: `status=VERIFIED` y referencia de titularidad/derechos. No se infiere del rol ni de la cuenta.
- `age_eligibility`: `status=ELIGIBLE` con evidencia opaca de resultado, sin fecha de nacimiento, documento ni PII.
- `platform_eligibility`: `status=ELIGIBLE` y evidencia; elegibilidad no se infiere de scopes.
- `platform_scopes`: `status=VERIFIED`, referencia, arrays de `required` y `granted` **exactos**, no vacíos, únicos y sin comodines. Faltantes o excesivos bloquean.
- `privacy`: `status=DOCUMENTED` con referencia; refleja inventario, **no aprobación jurídica**. Los borradores de `datos.yml` y `docs/privacidad/` requieren revisión.
- `legal_review`: exclusivamente `status=PENDING_HUMAN_REVIEW`; ni el solicitante ni este código pueden otorgar `LEGAL_APPROVED`.

`distribution_mode` puede ser `EXTERNAL_PLATFORM`, o `NO_EXTERNAL_PLATFORM` cuando no existe destino externo. Solo en este último caso `platform_eligibility` y `platform_scopes` admiten `NOT_APPLICABLE`, cada uno **con `reason_code=NO_EXTERNAL_DESTINATION`**; consentimiento, edad, titularidad, privacidad y revisión jurídica no admiten esta excepción. La identidad del destino y los scopes efectivos deberán revalidarse en la futura integración autenticada.

## Resultado y límites

El máximo posible es `READY_FOR_HUMAN_REVIEW`. Cualquier ausencia, entrada mal formada, estado `UNKNOWN` o contradicción retorna `BLOCKED` con códigos no sensibles. La salida siempre mantiene `publish_allowed=false`, `legal_approved=false`, `go_live_allowed=false` y `requires_human_review=true`. No ofrece `PUBLISH_ALLOWED`, no concede privilegios, no cambia la fase de construcción y no activa ningún conector.

**Puertas exteriores intactas:** protección de datos y revisión jurídica humana, D-059/Hostinger, seguridad de auto-merge #494, aprobación explícita del dueño para go-live, backup/deploy y trazabilidad. La integración posterior en Library/Scheduler/Distribution requiere contrato propio con pruebas de tenant, roles, scopes y revocación; no se incluye aquí. El expediente y sus documentos fuente solo se corrigen en sus issues legales; el evaluador no modifica `datos.yml`.

Prueba reproducible con fixtures sintéticos: `python3 -m unittest discover -s tests -p test_prelive_compliance_gate.py`. No se ejecuta tráfico ni se cargan credenciales, información personal o muestras de clientes.
