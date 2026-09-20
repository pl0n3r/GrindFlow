import { useEffect, useState, type FormEvent } from 'react';
import { VaultPanel } from './VaultPanel';

type Context = {
  user: { display_name: string };
  organization_name_csrf: string | null;
  profile_name_csrf: string | null;
  vault_upload_csrf: string | null;
  organization: { id: string; name: string; role: string };
  permissions: {
    workspace_view: boolean;
    organization_manage: boolean;
    content_prepare: boolean;
    content_review: boolean;
  };
};

type State =
  | { kind: 'loading' }
  | { kind: 'ready'; context: Context }
  | { kind: 'error'; message: string };

const roleNames: Record<string, string> = {
  admin: 'Administración',
  studio: 'Estudio',
  editor: 'Edición',
  model: 'Modelo',
};

export function AdminApp() {
  const [state, setState] = useState<State>({ kind: 'loading' });
  const [name, setName] = useState('');
  const [profileName, setProfileName] = useState('');
  const [savingProfile, setSavingProfile] = useState(false);
  const [profileFeedback, setProfileFeedback] = useState('');
  const [saving, setSaving] = useState(false);
  const [feedback, setFeedback] = useState('');

  useEffect(() => {
    const controller = new AbortController();

    fetch('/api/admin/context', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok) {
          throw new Error(body?.error?.message ?? 'No pudimos cargar tu espacio.');
        }
        return body.data as Context;
      })
      .then((context) => {
        setName(context.organization.name);
        setProfileName(context.user.display_name);
        setState({ kind: 'ready', context });
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setState({
          kind: 'error',
          message: error instanceof Error ? error.message : 'No pudimos cargar tu espacio.',
        });
      });

    return () => controller.abort();
  }, []);


  async function rename(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (state.kind !== 'ready' || !state.context.permissions.organization_manage ||
        !state.context.organization_name_csrf || saving) return;

    setSaving(true);
    setFeedback('');
    try {
      const response = await fetch('/api/admin/organization/name', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': state.context.organization_name_csrf,
        },
        body: JSON.stringify({ name }),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo guardar el nombre.');
      }
      const organization = body.data.organization as Context['organization'];
      setState((previous) => previous.kind === 'ready'
        ? { kind: 'ready', context: { ...previous.context, organization } } : previous);
      setName(organization.name);
      setFeedback('Nombre de la organización actualizado.');
    } catch (error) {
      setFeedback(error instanceof Error ? error.message : 'No se pudo guardar el nombre.');
    } finally {
      setSaving(false);
    }
  }


  async function renameProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (state.kind !== 'ready' || !state.context.profile_name_csrf || savingProfile) return;

    setSavingProfile(true);
    setProfileFeedback('');
    try {
      const response = await fetch('/api/admin/profile/name', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': state.context.profile_name_csrf,
        },
        body: JSON.stringify({ name: profileName }),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo actualizar tu perfil.');
      }
      const updated = body.data.user.display_name as string;
      setState((previous) => previous.kind === 'ready'
        ? { kind: 'ready', context: { ...previous.context, user: { display_name: updated } } }
        : previous);
      setProfileName(updated);
      setProfileFeedback('Nombre de tu perfil actualizado.');
    } catch (error) {
      setProfileFeedback(error instanceof Error ? error.message : 'No se pudo actualizar tu perfil.');
    } finally {
      setSavingProfile(false);
    }
  }

  if (state.kind === 'loading') {
    return <section className="admin-state" role="status"><span className="admin-pulse" />Cargando contexto seguro…</section>;
  }

  if (state.kind === 'error') {
    return (
      <section className="admin-state admin-error" role="alert">
        <strong>No se pudo abrir el espacio</strong>
        <span>{state.message}</span>
        <a href="/organizations">Volver a seleccionar organización</a>
      </section>
    );
  }

  const { context } = state;
  const role = roleNames[context.organization.role] ?? context.organization.role;

  return (
    <div className="admin-layout">
      <aside className="admin-sidebar">
        <a className="admin-brand" href="/" aria-label="GrindFlow, inicio">GRIND<span>FLOW</span></a>
        <nav aria-label="Navegación administrativa">
          <a className="active" href="/admin" aria-current="page"><span>01</span>Resumen</a>
          <a href="#biblioteca"><span>02</span>Biblioteca <small>S2</small></a>
          <span aria-disabled="true"><span>03</span>Programación <small>S3</small></span>
        </nav>
        <div className="admin-tenant">
          <small>ORGANIZACIÓN ACTUAL</small>
          <strong>{context.organization.name}</strong>
          <span>{role}</span>
        </div>
      </aside>
      <section className="admin-workspace">
        <header className="admin-header">
          <div><small>ESPACIO PRIVADO</small><strong>{context.organization.name}</strong></div>
          <div className="admin-user"><span>{context.user.display_name}</span><span className="role-chip">{role}</span></div>
        </header>
        <main className="admin-content">
          <span className="admin-kicker"><span className="admin-pulse" /> CONTEXTO VERIFICADO</span>
          <h1>Tu espacio,<br/><em>con permisos claros.</em></h1>
          <p className="admin-lead">La sesión y la membresía se revalidan en el servidor. Esta entrega muestra únicamente acciones que tu rol puede realizar.</p>
          <section className="admin-grid" aria-label="Capacidades de la cuenta">
            <article className="admin-card featured">
              <span>ACCESO ACTIVO</span>
              <h2>Resumen del espacio</h2>
              <p>La organización seleccionada coincide con una membresía vigente.</p>
              <strong className="permission yes">Habilitado</strong>
            </article>
            <article className="admin-card">
              <span>CONTENIDO</span>
              <h2>Preparar recursos</h2>
              <p>Disponibilidad calculada desde el rol de la membresía actual.</p>
              <strong className={'permission ' + (context.permissions.content_prepare ? 'yes' : 'no')}>
                {context.permissions.content_prepare ? 'Permitido' : 'Solo lectura'}
              </strong>
            </article>
            <article className="admin-card">
              <span>ORGANIZACIÓN</span>
              <h2>Administrar equipo</h2>
              <p>Reservado para roles administrativos del espacio.</p>
              <strong className={'permission ' + (context.permissions.organization_manage ? 'yes' : 'no')}>
                {context.permissions.organization_manage ? 'Permitido' : 'Sin permiso'}
              </strong>
            </article>
          </section>
          <section className="admin-settings" aria-labelledby="organization-settings-title">
            <span className="admin-kicker">AJUSTES DEL ESPACIO</span>
            <h2 id="organization-settings-title">Nombre de la organización</h2>
            {context.permissions.organization_manage && context.organization_name_csrf
              ? <form onSubmit={rename} className="admin-rename-form">
                  <label htmlFor="organization-name">Nombre visible</label>
                  <div className="admin-rename-controls">
                    <input id="organization-name" value={name} minLength={2} maxLength={120}
                      required onChange={(event) => setName(event.target.value)} />
                    <button type="submit" disabled={saving}>{saving ? 'Guardando…' : 'Guardar cambios'}</button>
                  </div>
                </form>
              : <p>Tu rol permite consultar este espacio, pero no cambiar el nombre de la organización.</p>}
            {feedback && <p role="status">{feedback}</p>}
          </section>
          <section className="admin-settings" aria-labelledby="profile-settings-title">
            <span className="admin-kicker">TU CUENTA</span>
            <h2 id="profile-settings-title">Perfil personal</h2>
            <p>Este nombre aparece en tu sesión y no modifica ninguna organización.</p>
            <p className="admin-profile-current">Nombre actual: <strong>{context.user.display_name}</strong></p>
            <form onSubmit={renameProfile} className="admin-rename-form">
              <label htmlFor="profile-name">Nombre en tu perfil</label>
              <div className="admin-rename-controls">
                <input id="profile-name" value={profileName} minLength={2} maxLength={120}
                  required onChange={(event) => setProfileName(event.target.value)} />
                <button type="submit" disabled={savingProfile || !context.profile_name_csrf}>
                  {savingProfile ? 'Guardando…' : 'Guardar perfil'}
                </button>
              </div>
            </form>
            {profileFeedback && <p role="status">{profileFeedback}</p>}
          </section>
          <VaultPanel canUpload={context.permissions.content_prepare} csrf={context.vault_upload_csrf} />
          <section className="admin-notice" role="status">
            <strong>Alcance S2 inicial</strong>
            <p>La biblioteca privada admite imágenes; los videos, la programación y las conexiones externas todavía no están habilitados en Symfony.</p>
          </section>
        </main>
      </section>
    </div>
  );
}
