import { StrictMode, useEffect, useState, type FormEvent } from 'react';
import { createRoot } from 'react-dom/client';
import './preview.css';

type Context = {
  user: { display_name: string };
  organization: { id: string; name: string; role: string };
  permissions: { rename_organization: boolean };
  csrf_token: string | null;
};

function AdminApp({ version }: { version: string }) {
  const [context, setContext] = useState<Context | null>(null);
  const [error, setError] = useState('');
  const [name, setName] = useState('');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  useEffect(() => {
    const abort = new AbortController();
    fetch('/api/admin/context', {
      credentials: 'same-origin',
      signal: abort.signal,
      headers: { Accept: 'application/json' }
    }).then(async (response) => {
      if (!response.ok) throw new Error(response.status === 409
        ? 'Selecciona una organización.' : 'Tu acceso cambió. Vuelve a ingresar.');
      return response.json() as Promise<Context>;
    }).then((data) => {
      setContext(data);
      setName(data.organization.name);
    }).catch((cause: unknown) => {
      if (!abort.signal.aborted) {
        setError(cause instanceof Error ? cause.message : 'No se pudo cargar el espacio.');
      }
    });
    return () => abort.abort();
  }, []);

  async function rename(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!context?.permissions.rename_organization || !context.csrf_token || saving) return;
    setSaving(true);
    setMessage('');
    try {
      const response = await fetch('/api/admin/organization/name', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': context.csrf_token
        },
        body: JSON.stringify({ name })
      });
      if (!response.ok) {
        throw new Error(response.status === 403
          ? 'Tu permiso cambió o la sesión venció.' : 'No se pudo guardar el nombre.');
      }
      const body = await response.json() as Pick<Context, 'organization'>;
      setContext({ ...context, organization: body.organization });
      setName(body.organization.name);
      setMessage('Nombre actualizado.');
    } catch (cause) {
      setMessage(cause instanceof Error ? cause.message : 'No se pudo guardar el nombre.');
    } finally {
      setSaving(false);
    }
  }

  return <main id="contenido" className="preview-shell admin-shell">
    <aside className="preview-aside">
      <a className="brand" href="/" aria-label="GrindFlow, inicio">GRIND<span>FLOW</span></a>
      <span className="preview-label">ADMINISTRACIÓN PRIVADA · S1</span>
      <nav aria-label="Espacio privado">
        <a className="preview-tab selected" aria-current="page" href="/admin">Organización</a>
        <a className="preview-tab" href="/organizations">Cambiar organización</a>
      </nav>
      <div className="preview-aside-bottom"><span className="status-dot" /> Entorno Symfony aislado</div>
    </aside>
    <div className="preview-workspace">
      <header className="preview-toolbar">
        <span>GRINDFLOW / ESPACIO PRIVADO</span>
        <span className="version-tag">V {version}</span>
      </header>
      <div className="preview-content">
        <span className="eyebrow">CUENTA Y ORGANIZACIÓN</span>
        {!context && !error && <p role="status">Comprobando tu acceso…</p>}
        {error && <div className="preview-banner" role="alert">
          {error} <a href="/organizations">Elegir organización</a>
        </div>}
        {context && <>
          <h1 className="admin-title">{context.organization.name}</h1>
          <p className="preview-lead">Hola, {context.user.display_name}. Tu rol aquí es <strong>{context.organization.role}</strong>.</p>
          <div className="preview-banner" role="status">
            La biblioteca y la distribución todavía no están conectadas en Symfony.
            No se muestran estadísticas inventadas.
          </div>
          <section className="preview-board" aria-labelledby="org-settings">
            <h2 id="org-settings">Configuración de organización</h2>
            {context.permissions.rename_organization && <form className="admin-form" onSubmit={rename}>
              <label htmlFor="org-name">Nombre de la organización</label>
              <input id="org-name" value={name} maxLength={120} minLength={2}
                required onChange={(e) => setName(e.target.value)} />
              <button className="admin-save" disabled={saving} type="submit">
                {saving ? 'Guardando…' : 'Guardar nombre'}
              </button>
            </form>}
            {!context.permissions.rename_organization &&
              <p>Tu rol permite consultar este espacio, pero no cambiar el nombre de la organización.</p>}
            {message && <p role="status">{message}</p>}
          </section>
          <div className="preview-bottom">
            <a href="/organizations">← Cambiar organización</a>
            <span>Datos limitados a tu membresía activa.</span>
          </div>
        </>}
      </div>
    </div>
  </main>;
}

const root = document.getElementById('grindflow-admin');
if (root) createRoot(root).render(
  <StrictMode><AdminApp version={root.dataset.version ?? 'unavailable'} /></StrictMode>
);
