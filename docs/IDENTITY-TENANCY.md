# Identity and tenancy

This document defines the Laravel identity and organization boundary after the
MariaDB architecture pivot.

## Target database

Laravel targets **MariaDB** in production and uses Laravel's `mysql` driver.

The legacy TypeScript/Supabase implementation may continue to use PostgreSQL
temporarily until each module reaches parity and is retired. PostgreSQL-specific
RLS is therefore a legacy implementation detail, not a Laravel target.

## Target model

- `users` is global identity and authentication.
- `organizations` is the tenant boundary.
- `memberships` connects users to organizations with an organization-specific role.
- tenant-owned domain tables carry a non-null `organization_id`.
- IDs remain UUIDs so legacy identifiers can be mapped without key conversion.

Supported roles remain compatible with the legacy model:
`admin`, `studio`, `model`, and `editor`.

## Defense in depth

MariaDB does not provide PostgreSQL-style Row Level Security, so GrindFlow does
not pretend that an equivalent database policy exists.

Tenant isolation uses explicit layers:

1. Laravel authentication, Policies and server-side authorization.
2. `TenantContext` carrying the actor and active organization.
3. `BelongsToOrganization` + `TenantScope` for tenant-owned models:
   - no organization context => zero rows;
   - create without organization context => rejected;
   - cross-tenant create => rejected;
   - `organization_id` cannot be changed through the model.
4. MariaDB structural barriers:
   - foreign keys;
   - unique membership per organization/user;
   - ENUM-backed role/type values;
   - a database trigger that makes membership identity immutable.
5. Negative tests actively attempt cross-tenant access and invalid database mutations.

This means authorization lives primarily in Laravel while MariaDB protects
structural invariants that should survive application bugs.

## Mandatory tenant-owned model contract

Every new Laravel model whose rows belong to an organization must:

- contain `organization_id`;
- use `App\Models\Concerns\BelongsToOrganization`;
- be created inside `TenantContext::runWithinOrganization(...)`;
- perform mutation authorization with a Policy or equivalent server-side gate;
- have negative tests for cross-tenant reads and writes.

Using `withoutGlobalScope(TenantScope::class)` is an exceptional privileged
operation and requires explicit review plus targeted tests.

## Email identity

Email is canonicalized to lowercase in the Laravel model and at login.
MariaDB uses a case-insensitive `utf8mb4_unicode_ci` collation by default for
this application, so the unique email index rejects case-only duplicates.

A legacy-user import must lowercase source emails first and resolve collisions
that differ only by case before inserting into Laravel.

## Membership invariants

- A user can belong to multiple organizations.
- `(organization_id, user_id)` is unique.
- Membership identity is immutable; moving a membership means replacing it.
- A non-platform-admin user cannot change their own membership role through an
  authorized application path.
- Organization management roles are `admin` and `studio`.
- Platform admins can cross organization boundaries through explicit privileged paths.
- MariaDB rejects direct changes to membership `organization_id` or `user_id`
  through the `memberships_identity_immutable` trigger.

## Queue contract

Organization-sensitive jobs implement `OrganizationAwareJob` and use
`UseOrganizationContext`. The middleware carries actor + organization IDs and
revalidates membership immediately before the job body runs. A queued job does
not inherit authorization forever from the moment it was created.

## Production database account

The application should use a normal MariaDB account limited to the GrindFlow
database. Schema changes are explicit operator actions. If the hosting plan
allows separate database users/privileges, migrations and runtime access should
use separate credentials; otherwise the application still treats migrations as
a separate manual operation and never runs them automatically during deploy.

## CI contract

The database gate runs MariaDB 11.4 and:

- applies Laravel migrations from scratch;
- runs database-sensitive tests;
- verifies membership identity immutability;
- verifies invalid ENUM values are rejected.

SQLite remains useful for fast feature tests but does not replace the MariaDB
gate for database-specific behavior.

## Legacy coexistence

Laravel migrations target MariaDB. They do not modify the existing Supabase
migration history. Legacy PostgreSQL code remains a functional reference only
until the corresponding Laravel module reaches validated parity and a deliberate
data migration is performed.
