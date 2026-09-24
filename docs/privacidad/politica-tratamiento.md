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
| account_identity | contact | name, display_name, handle, email, email_verified_at, platform_role, role | account_access | review_required | review_required | supabase | review_required |
| cloud_connections | authentication | authorized_by_user_id, created_by, provider, label, account_identifier, account_email, access_ciphertext, refresh_ciphertext, token_expires_at, scopes, cursor, delta_cursor, root_path, root_folder_id, root_folder_path, default_profile_id, metadata | cloud_media_connection | review_required | review_required | dropbox, google_drive, supabase | review_required |
| legacy_supabase_link_clicks | usage | tracking_link_id, occurred_at, country, referrer, ua_family, network | traffic_click_analytics | review_required | review_required | supabase | review_required |
| legacy_supabase_platform_credentials | authentication | profile_id, platform, credential_type, label, account_identifier, secret_ciphertext, refresh_ciphertext, token_expires_at, scopes, settings, active, last_used_at | publishing_account_connection | review_required | review_required | supabase | review_required |
| legacy_supabase_upload_audit | location | upload_link_id, r2_key, bytes, mime_type, ip, occurred_at | upload_security_audit | review_required | review_required | supabase | review_required |
| media_vault | usage | ingested_by_user_id, profile_id, original_filename, source_type, source_ref, storage_disk, storage_key, r2_key, sha256, checksum_sha256, byte_size, file_type, mime_type, outfit_tag, session_date, metadata, derivatives | media_management | review_required | review_required | supabase | review_required |
| organization_membership | identification | user_id, organization_id, role | tenant_authorization | review_required | review_required | supabase | review_required |
| traffic_dedupe | usage | visitor_hash, last_counted_at | traffic_deduplication | review_required | review_required | ninguno_declarado | dedupe_24h |
| traffic_links | usage | created_by_user_id, profile_id, slug, label, destination_url, channel, campaign, network, status, clicks_count, expires_at | traffic_attribution | review_required | review_required | supabase | review_required |
| traffic_metrics | usage | metric_date, clicks | traffic_measurement | review_required | review_required | ninguno_declarado | review_required |

## Derechos y revisión

Las solicitudes de acceso, corrección, actualización, supresión o revocación se canalizan mediante el canal de derechos indicado arriba. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.
