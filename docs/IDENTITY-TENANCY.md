# Identity and tenancy

This document defines the Laravel identity and organization boundary introduced
by **GF-MIG-002**.

## Target model

- `users` is global identity and authentication.
- `organizations` is the tenant boundary.
- `memberships` connects users to organizations with an organization-specific role.
- IDs remain UUIDs so legacy identifiers can be mapped without converting key types.

Supported roles remain compatible with the legacy model:
`admin`, `studio`, `model`, and `editor`.

## Defense in depth

Authorization has two layers:

1. Laravel Policies, explicit organization-aware queries and authenticated route middleware.
2. PostgreSQL Row Level Security on `organizations` and `memberships`.

Laravel sets `app.current_user_id` with PostgreSQL `set_config(..., true)`
inside a transaction. If that value is absent, tenant RLS policies fail closed.

The RLS helper functions are `SECURITY DEFINER` to avoid recursive membership
policy evaluation. They use a fixed search path.

## Database-role contract

Production HTTP/queue traffic **must use a PostgreSQL runtime role that is not a
superuser, does not have `BYPASSRLS`, and does not own the protected tables**.
Schema migrations should run with a separate migration/owner role.

The local `postgres` value in `.env.example` is for development/bootstrap
only. CI verifies the RLS contract by switching to a dedicated unprivileged role
before attempting cross-tenant access.

## Email identity

Email is canonicalized to lowercase in the Laravel model and again at login.
PostgreSQL additionally enforces `email = lower(email)`, so the existing unique
constraint is effectively case-insensitive for valid production rows.

A future legacy-user import must lowercase source emails first and explicitly
resolve any collisions that differ only by case before inserting into the
Laravel-managed `users` table.

## Membership invariants

- A user can belong to multiple organizations.
- `(organization_id, user_id)` is unique.
- Membership identity is immutable; moving a membership means replacing it.
- A non-platform-admin user cannot change their own membership role.
- Organization management roles are `admin` and `studio`.
- Platform admins can cross organization boundaries.

## Queue contract

Organization-sensitive jobs implement `OrganizationAwareJob` and use
`UseOrganizationContext`. The middleware carries actor + organization IDs and
revalidates membership immediately before the job body runs. A queued job does
not inherit authorization forever from the moment it was created.

## Legacy coexistence

These Laravel migrations target the new Laravel-managed PostgreSQL schema.
They do not modify the existing Supabase migration history. Legacy code remains
the functional reference until the corresponding Laravel module reaches
validated parity and a deliberate data migration is performed.
