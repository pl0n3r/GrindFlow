<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            create schema if not exists app;
            revoke all on schema app from public;

            alter table public.users
                add constraint users_platform_role_check
                check (platform_role in ('admin', 'studio', 'model', 'editor'));

            alter table public.users
                add constraint users_email_lowercase_check
                check (email = lower(email));

            alter table public.organizations
                add constraint organizations_type_check
                check (type in ('studio', 'independent'));

            alter table public.organizations
                add constraint organizations_slug_check
                check (slug ~ '^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$');

            alter table public.memberships
                add constraint memberships_role_check
                check (role in ('admin', 'studio', 'model', 'editor'));

            create or replace function app.current_user_id()
            returns uuid
            language sql
            stable
            set search_path = public, pg_temp
            as $$
                select nullif(current_setting('app.current_user_id', true), '')::uuid;
            $$;

            create or replace function app.is_platform_admin()
            returns boolean
            language sql
            stable
            security definer
            set search_path = public, pg_temp
            as $$
                select exists (
                    select 1
                    from public.users
                    where id = app.current_user_id()
                      and platform_role = 'admin'
                );
            $$;

            create or replace function app.org_role(p_org uuid)
            returns text
            language sql
            stable
            security definer
            set search_path = public, pg_temp
            as $$
                select role
                from public.memberships
                where organization_id = p_org
                  and user_id = app.current_user_id();
            $$;

            create or replace function app.is_org_member(p_org uuid)
            returns boolean
            language sql
            stable
            security definer
            set search_path = public, pg_temp
            as $$
                select app.is_platform_admin() or app.org_role(p_org) is not null;
            $$;

            create or replace function app.can_manage_org(p_org uuid)
            returns boolean
            language sql
            stable
            security definer
            set search_path = public, pg_temp
            as $$
                select app.is_platform_admin()
                    or app.org_role(p_org) in ('admin', 'studio');
            $$;

            create or replace function app.protect_membership_identity()
            returns trigger
            language plpgsql
            set search_path = public, pg_temp
            as $$
            begin
                if new.organization_id is distinct from old.organization_id
                    or new.user_id is distinct from old.user_id then
                    raise exception 'authorization: membership identity is immutable'
                        using errcode = 'insufficient_privilege';
                end if;

                if new.role is distinct from old.role
                    and old.user_id = app.current_user_id()
                    and not app.is_platform_admin() then
                    raise exception 'authorization: users cannot change their own membership role'
                        using errcode = 'insufficient_privilege';
                end if;

                return new;
            end;
            $$;

            drop trigger if exists memberships_protect_identity on public.memberships;
            create trigger memberships_protect_identity
                before update on public.memberships
                for each row execute function app.protect_membership_identity();

            alter table public.organizations enable row level security;
            alter table public.memberships enable row level security;

            drop policy if exists organizations_select on public.organizations;
            create policy organizations_select on public.organizations
                for select
                using (app.is_org_member(id));

            drop policy if exists organizations_update on public.organizations;
            create policy organizations_update on public.organizations
                for update
                using (app.can_manage_org(id))
                with check (app.can_manage_org(id));

            drop policy if exists organizations_insert on public.organizations;
            create policy organizations_insert on public.organizations
                for insert
                with check (app.is_platform_admin());

            drop policy if exists organizations_delete on public.organizations;
            create policy organizations_delete on public.organizations
                for delete
                using (app.is_platform_admin());

            drop policy if exists memberships_select on public.memberships;
            create policy memberships_select on public.memberships
                for select
                using (
                    user_id = app.current_user_id()
                    or app.can_manage_org(organization_id)
                );

            drop policy if exists memberships_insert on public.memberships;
            create policy memberships_insert on public.memberships
                for insert
                with check (app.can_manage_org(organization_id));

            drop policy if exists memberships_update on public.memberships;
            create policy memberships_update on public.memberships
                for update
                using (app.can_manage_org(organization_id))
                with check (app.can_manage_org(organization_id));

            drop policy if exists memberships_delete on public.memberships;
            create policy memberships_delete on public.memberships
                for delete
                using (app.can_manage_org(organization_id));
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            drop policy if exists memberships_delete on public.memberships;
            drop policy if exists memberships_update on public.memberships;
            drop policy if exists memberships_insert on public.memberships;
            drop policy if exists memberships_select on public.memberships;
            drop policy if exists organizations_delete on public.organizations;
            drop policy if exists organizations_insert on public.organizations;
            drop policy if exists organizations_update on public.organizations;
            drop policy if exists organizations_select on public.organizations;

            drop trigger if exists memberships_protect_identity on public.memberships;

            alter table public.memberships disable row level security;
            alter table public.organizations disable row level security;

            alter table public.memberships
                drop constraint if exists memberships_role_check;
            alter table public.organizations
                drop constraint if exists organizations_slug_check;
            alter table public.organizations
                drop constraint if exists organizations_type_check;
            alter table public.users
                drop constraint if exists users_email_lowercase_check;
            alter table public.users
                drop constraint if exists users_platform_role_check;

            drop function if exists app.protect_membership_identity();
            drop function if exists app.can_manage_org(uuid);
            drop function if exists app.is_org_member(uuid);
            drop function if exists app.org_role(uuid);
            drop function if exists app.is_platform_admin();
            drop function if exists app.current_user_id();
            SQL);
    }
};
