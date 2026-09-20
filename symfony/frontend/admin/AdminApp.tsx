import { useEffect, useState } from 'react';

type Context = {
  user: { display_name: string };
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
      .then((context) => setState({ kind: 'ready', context }))
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setState({
          kind: 'error',
          message: error instanceof Error ? error.message : 'No pudimos cargar tu espacio.',
        });
      });

    return () => controller.abort();
  }, []);

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
          <span aria-disabled="true"><span>02</span>Biblioteca <small>S2</small></span>
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
          <section className="admin-notice" role="status">
            <strong>Alcance S1</strong>
            <p>Biblioteca, programación y conexiones externas todavía no están habilitadas en Symfony. No se muestran datos simulados como si fueran reales.</p>
          </section>
        </main>
      </section>
    </div>
  );
}
