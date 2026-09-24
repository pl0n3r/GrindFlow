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
| account_credentials | authentication | password, remember_token | account_security | review_required | review_required | ninguno_declarado | review_required |
| account_identity | contact | name, email, email_verified_at, platform_role | account_access | review_required | review_required | ninguno_declarado | review_required |
| cloud_connections | authentication | authorized_by_user_id, provider, label, account_identifier, access_ciphertext, refresh_ciphertext, token_expires_at, scopes, cursor, root_path, metadata | cloud_media_connection | review_required | review_required | dropbox, google_drive | review_required |
| legacy_supabase_upload_audit | location | upload_link_id, r2_key, bytes, mime_type, ip, occurred_at | upload_security_audit | review_required | review_required | supabase | review_required |
| media_vault | usage | ingested_by_user_id, original_filename, source_type, source_ref, storage_disk, storage_key, sha256, byte_size, mime_type, metadata | media_management | review_required | review_required | ninguno_declarado | review_required |
| organization_membership | identification | user_id, organization_id, role | tenant_authorization | review_required | review_required | ninguno_declarado | review_required |
| traffic_dedupe | usage | visitor_hash, last_counted_at | traffic_deduplication | review_required | review_required | ninguno_declarado | dedupe_24h |
| traffic_links | usage | created_by_user_id, label, destination_url, channel, campaign, status | traffic_attribution | review_required | review_required | ninguno_declarado | review_required |
| traffic_metrics | usage | metric_date, clicks | traffic_measurement | review_required | review_required | ninguno_declarado | review_required |

## Derechos y revisión

Las solicitudes de acceso, corrección, actualización, supresión o revocación se canalizan mediante el canal de derechos indicado arriba. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.
