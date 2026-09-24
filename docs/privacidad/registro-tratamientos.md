# Registro de tratamientos

> Estado: inventario técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/GrindFlow`

## account_credentials

- Categoría: `authentication`
- Campos de software: `password`, `remember_token`
- Finalidad: `account_security`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## account_identity

- Categoría: `contact`
- Campos de software: `name`, `email`, `email_verified_at`, `platform_role`
- Finalidad: `account_access`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## cloud_connections

- Categoría: `authentication`
- Campos de software: `authorized_by_user_id`, `provider`, `label`, `account_identifier`, `access_ciphertext`, `refresh_ciphertext`, `token_expires_at`, `scopes`, `cursor`, `root_path`, `metadata`
- Finalidad: `cloud_media_connection`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `dropbox`, `google_drive`
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
- Campos de software: `ingested_by_user_id`, `original_filename`, `source_type`, `source_ref`, `storage_disk`, `storage_key`, `sha256`, `byte_size`, `mime_type`, `metadata`
- Finalidad: `media_management`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## organization_membership

- Categoría: `identification`
- Campos de software: `user_id`, `organization_id`, `role`
- Finalidad: `tenant_authorization`
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
- Campos de software: `created_by_user_id`, `label`, `destination_url`, `channel`, `campaign`, `status`
- Finalidad: `traffic_attribution`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## traffic_metrics

- Categoría: `usage`
- Campos de software: `metric_date`, `clicks`
- Finalidad: `traffic_measurement`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`
