# Paquete completo de revisión jurídica · GrindFlow

Estado: `documented_not_legally_approved` (sin aprobación jurídica).

---

## docs/privacidad/aviso-privacidad.md

# Aviso de privacidad y autorización

> Estado: borrador técnico generado; **revisión jurídica requerida**.

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Producto

`pl0n3r/GrindFlow`

## Tratamientos documentados

| Tratamiento | Categoría | Campos | Finalidad | Base documentada | Consentimiento | Proveedores | Retención |
| --- | --- | --- | --- | --- | --- | --- | --- |
| account_credentials | authentication | password, password_hash, remember_token, password_reset_token_hash, password_reset_expires_at, security_event | account_security | review_required | review_required | ninguno_declarado | review_required |
| account_identity | contact | name, display_name, handle, email, email_verified_at, platform_role, role | account_access | review_required | review_required | supabase | review_required |
| cloud_connections | authentication | authorized_by_user_id, created_by, provider, label, account_identifier, account_email, access_ciphertext, refresh_ciphertext, token_expires_at, scopes, cursor, delta_cursor, root_path, root_folder_id, root_folder_path, default_profile_id, metadata | cloud_media_connection | review_required | review_required | dropbox, google_drive, supabase | review_required |
| legacy_supabase_link_clicks | usage | tracking_link_id, occurred_at, country, referrer, ua_family, network | traffic_click_analytics | review_required | review_required | supabase | review_required |
| legacy_supabase_platform_credentials | authentication | profile_id, platform, credential_type, label, account_identifier, secret_ciphertext, refresh_ciphertext, token_expires_at, scopes, settings, active, last_used_at | publishing_account_connection | review_required | review_required | supabase | review_required |
| legacy_supabase_upload_audit | location | upload_link_id, r2_key, bytes, mime_type, ip, occurred_at | upload_security_audit | review_required | review_required | supabase | review_required |
| media_vault | usage | ingested_by_user_id, profile_id, assigned_at, assigned_by_user_id, original_filename, source_type, source_ref, storage_disk, storage_key, r2_key, sha256, checksum_sha256, byte_size, file_type, mime_type, outfit_tag, session_date, metadata, derivatives | media_management | review_required | review_required | cloudflare_r2, supabase | review_required |
| organization_membership | identification | user_id, organization_id, role | tenant_authorization | review_required | review_required | supabase | review_required |
| staff_access_metadata | usage | last_access_at, failed_login_count | staff_security_monitoring | review_required | review_required | ninguno_declarado | review_required |
| staff_contact | contact | email | staff_invitation_and_recovery | review_required | review_required | ninguno_declarado | review_required |
| staff_identity | identification | staff_id, name, role, status | staff_administration | review_required | review_required | ninguno_declarado | review_required |
| traffic_dedupe | usage | visitor_hash, last_counted_at | traffic_deduplication | review_required | review_required | ninguno_declarado | dedupe_24h |
| traffic_links | usage | created_by_user_id, profile_id, slug, label, destination_url, channel, campaign, network, status, clicks_count, expires_at | traffic_attribution | review_required | review_required | supabase | review_required |
| traffic_metrics | usage | metric_date, clicks | traffic_measurement | review_required | review_required | ninguno_declarado | review_required |

## Autorización técnica pendiente

La integración que recoja autorización debe presentar una **casilla no premarcada** y un enlace visible a la política de tratamiento antes de registrar la decisión de la persona. Para tratamientos que requieran consentimiento explícito, la implementación debe conservar evidencia verificable de esa decisión.

Este borrador **no acredita que exista consentimiento**, no sustituye la revisión jurídica y no autoriza por sí mismo ningún tratamiento.


---

## docs/privacidad/canal-derechos.md

# Canal para ejercer derechos sobre datos personales

> Estado: borrador técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/GrindFlow`

## Responsable y canal

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Procedimiento pendiente

- Requisitos de la solicitud: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Flujo interno de atención: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Plazos aplicables: [COMPLETAR POR EL DUEÑO] — deben ser definidos con revisión jurídica; este kit no inventa un plazo legal.

El canal debe permitir solicitudes de acceso, corrección, actualización, supresión o revocación según corresponda. Este documento describe una frontera técnica y no constituye aprobación jurídica.


---

## docs/privacidad/operacion-derechos-incidentes.md

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


---

## docs/privacidad/politica-tratamiento.md

# Política de tratamiento de datos personales

> Estado: documento técnico generado; **no constituye aprobación jurídica**.

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Producto

`pl0n3r/GrindFlow`

## Tratamientos documentados

| Tratamiento | Categoría | Campos | Finalidad | Base documentada | Consentimiento | Proveedores | Retención |
| --- | --- | --- | --- | --- | --- | --- | --- |
| account_credentials | authentication | password, password_hash, remember_token, password_reset_token_hash, password_reset_expires_at, security_event | account_security | review_required | review_required | ninguno_declarado | review_required |
| account_identity | contact | name, display_name, handle, email, email_verified_at, platform_role, role | account_access | review_required | review_required | supabase | review_required |
| cloud_connections | authentication | authorized_by_user_id, created_by, provider, label, account_identifier, account_email, access_ciphertext, refresh_ciphertext, token_expires_at, scopes, cursor, delta_cursor, root_path, root_folder_id, root_folder_path, default_profile_id, metadata | cloud_media_connection | review_required | review_required | dropbox, google_drive, supabase | review_required |
| legacy_supabase_link_clicks | usage | tracking_link_id, occurred_at, country, referrer, ua_family, network | traffic_click_analytics | review_required | review_required | supabase | review_required |
| legacy_supabase_platform_credentials | authentication | profile_id, platform, credential_type, label, account_identifier, secret_ciphertext, refresh_ciphertext, token_expires_at, scopes, settings, active, last_used_at | publishing_account_connection | review_required | review_required | supabase | review_required |
| legacy_supabase_upload_audit | location | upload_link_id, r2_key, bytes, mime_type, ip, occurred_at | upload_security_audit | review_required | review_required | supabase | review_required |
| media_vault | usage | ingested_by_user_id, profile_id, assigned_at, assigned_by_user_id, original_filename, source_type, source_ref, storage_disk, storage_key, r2_key, sha256, checksum_sha256, byte_size, file_type, mime_type, outfit_tag, session_date, metadata, derivatives | media_management | review_required | review_required | cloudflare_r2, supabase | review_required |
| organization_membership | identification | user_id, organization_id, role | tenant_authorization | review_required | review_required | supabase | review_required |
| staff_access_metadata | usage | last_access_at, failed_login_count | staff_security_monitoring | review_required | review_required | ninguno_declarado | review_required |
| staff_contact | contact | email | staff_invitation_and_recovery | review_required | review_required | ninguno_declarado | review_required |
| staff_identity | identification | staff_id, name, role, status | staff_administration | review_required | review_required | ninguno_declarado | review_required |
| traffic_dedupe | usage | visitor_hash, last_counted_at | traffic_deduplication | review_required | review_required | ninguno_declarado | dedupe_24h |
| traffic_links | usage | created_by_user_id, profile_id, slug, label, destination_url, channel, campaign, network, status, clicks_count, expires_at | traffic_attribution | review_required | review_required | supabase | review_required |
| traffic_metrics | usage | metric_date, clicks | traffic_measurement | review_required | review_required | ninguno_declarado | review_required |

## Derechos y revisión

Las solicitudes de acceso, corrección, actualización, supresión o revocación se canalizan mediante el canal de derechos indicado arriba. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.


---

## docs/privacidad/registro-tratamientos.md

# Registro de tratamientos

> Estado: inventario técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/GrindFlow`

## account_credentials

- Categoría: `authentication`
- Campos de software: `password`, `password_hash`, `remember_token`, `password_reset_token_hash`, `password_reset_expires_at`, `security_event`
- Finalidad: `account_security`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## account_identity

- Categoría: `contact`
- Campos de software: `name`, `display_name`, `handle`, `email`, `email_verified_at`, `platform_role`, `role`
- Finalidad: `account_access`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `supabase`
- Retención: `review_required`

## cloud_connections

- Categoría: `authentication`
- Campos de software: `authorized_by_user_id`, `created_by`, `provider`, `label`, `account_identifier`, `account_email`, `access_ciphertext`, `refresh_ciphertext`, `token_expires_at`, `scopes`, `cursor`, `delta_cursor`, `root_path`, `root_folder_id`, `root_folder_path`, `default_profile_id`, `metadata`
- Finalidad: `cloud_media_connection`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `dropbox`, `google_drive`, `supabase`
- Retención: `review_required`

## legacy_supabase_link_clicks

- Categoría: `usage`
- Campos de software: `tracking_link_id`, `occurred_at`, `country`, `referrer`, `ua_family`, `network`
- Finalidad: `traffic_click_analytics`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `supabase`
- Retención: `review_required`

## legacy_supabase_platform_credentials

- Categoría: `authentication`
- Campos de software: `profile_id`, `platform`, `credential_type`, `label`, `account_identifier`, `secret_ciphertext`, `refresh_ciphertext`, `token_expires_at`, `scopes`, `settings`, `active`, `last_used_at`
- Finalidad: `publishing_account_connection`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `supabase`
- Retención: `review_required`

## legacy_supabase_upload_audit

- Categoría: `location`
- Campos de software: `upload_link_id`, `r2_key`, `bytes`, `mime_type`, `ip`, `occurred_at`
- Finalidad: `upload_security_audit`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `supabase`
- Retención: `review_required`

## media_vault

- Categoría: `usage`
- Campos de software: `ingested_by_user_id`, `profile_id`, `assigned_at`, `assigned_by_user_id`, `original_filename`, `source_type`, `source_ref`, `storage_disk`, `storage_key`, `r2_key`, `sha256`, `checksum_sha256`, `byte_size`, `file_type`, `mime_type`, `outfit_tag`, `session_date`, `metadata`, `derivatives`
- Finalidad: `media_management`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `cloudflare_r2`, `supabase`
- Retención: `review_required`

## organization_membership

- Categoría: `identification`
- Campos de software: `user_id`, `organization_id`, `role`
- Finalidad: `tenant_authorization`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `supabase`
- Retención: `review_required`

## staff_access_metadata

- Categoría: `usage`
- Campos de software: `last_access_at`, `failed_login_count`
- Finalidad: `staff_security_monitoring`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## staff_contact

- Categoría: `contact`
- Campos de software: `email`
- Finalidad: `staff_invitation_and_recovery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## staff_identity

- Categoría: `identification`
- Campos de software: `staff_id`, `name`, `role`, `status`
- Finalidad: `staff_administration`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## traffic_dedupe

- Categoría: `usage`
- Campos de software: `visitor_hash`, `last_counted_at`
- Finalidad: `traffic_deduplication`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `dedupe_24h`

## traffic_links

- Categoría: `usage`
- Campos de software: `created_by_user_id`, `profile_id`, `slug`, `label`, `destination_url`, `channel`, `campaign`, `network`, `status`, `clicks_count`, `expires_at`
- Finalidad: `traffic_attribution`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `supabase`
- Retención: `review_required`

## traffic_metrics

- Categoría: `usage`
- Campos de software: `metric_date`, `clicks`
- Finalidad: `traffic_measurement`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`


---

## docs/privacidad/retencion.md

# Tabla de retención

> Estado: calendario técnico documentado; **revisión jurídica requerida**.

Producto: `pl0n3r/GrindFlow`

| Tratamiento | Categoría | Retención declarada | Máximo común |
| --- | --- | --- | --- |
| account_credentials | authentication | review_required | review_required |
| account_identity | contact | review_required | review_required |
| cloud_connections | authentication | review_required | review_required |
| legacy_supabase_link_clicks | usage | review_required | review_required |
| legacy_supabase_platform_credentials | authentication | review_required | review_required |
| legacy_supabase_upload_audit | location | review_required | review_required |
| media_vault | usage | review_required | review_required |
| organization_membership | identification | review_required | review_required |
| staff_access_metadata | usage | review_required | review_required |
| staff_contact | contact | review_required | review_required |
| staff_identity | identification | review_required | review_required |
| traffic_dedupe | usage | dedupe_24h | review_required |
| traffic_links | usage | review_required | review_required |
| traffic_metrics | usage | review_required | review_required |


---

## docs/privacidad/terminos-condiciones.md

# Términos y condiciones

> Estado: borrador técnico generado; **revisión jurídica requerida antes de publicación**.

Producto: `pl0n3r/GrindFlow`

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Condiciones pendientes de definición

- Condiciones comerciales: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Niveles de servicio (SLA), si aplican: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Garantías y limitaciones aplicables: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Jurisdicción y mecanismo de solución de controversias: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.

Este documento no inventa ni presume condiciones del producto. Los hechos comerciales y jurídicos anteriores deben completarse y aprobarse antes de su uso público.


