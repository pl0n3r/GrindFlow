import { useEffect, useState } from 'react';

// Read-only projection. Tenant membership and permission checks remain server-side.
type Row = Record<string, unknown>;
type WeeklyRule = {
  timezone: string; weekdays: string[]; local_time: string;
  max_per_day: number; mode: 'review_only'; updated_at: string;
};
type Preview = {
  slots: { scheduled_at_utc: string }[];
  assets: { id: string; name: string; eligible: boolean; blocking_reasons: string[] }[];
  visible: number; total_active_assets: number;
};
type Automation = {
  state: 'active' | 'paused'; local_times: string[]; destination_id: string | null;
  source: 'eligible_assets'; provider: 'facebook_page';
  max_per_day: number; timezone: string;
};
type View<T> =
  | { kind: 'loading' }
  | { kind: 'error'; message: string }
  | { kind: 'unknown' }
  | { kind: 'ready'; item: T };

const record = (v: unknown): v is Row => v !== null && typeof v === 'object' && !Array.isArray(v);
const natural = (v: unknown): v is number => Number.isSafeInteger(v) && (v as number) >= 0;
const days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const dayLabel: Record<string, string> = {
  mon: 'Lunes', tue: 'Martes', wed: 'Miércoles', thu: 'Jueves',
  fri: 'Viernes', sat: 'Sábado', sun: 'Domingo',
};
const isRule = (v: unknown): v is WeeklyRule =>
  record(v) && typeof v.timezone === 'string' && v.timezone.length > 0 &&
  Array.isArray(v.weekdays) && v.weekdays.every((d) => days.includes(d)) &&
  typeof v.local_time === 'string' && /^\d{2}:\d{2}$/.test(v.local_time) &&
  natural(v.max_per_day) && v.max_per_day > 0 && v.mode === 'review_only' &&
  typeof v.updated_at === 'string';

const isSlot = (v: unknown): v is { scheduled_at_utc: string } =>
  record(v) && typeof v.scheduled_at_utc === 'string' &&
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(v.scheduled_at_utc);
const isAsset = (v: unknown): v is Preview['assets'][number] =>
  record(v) && typeof v.id === 'string' && typeof v.name === 'string' &&
  typeof v.eligible === 'boolean' && Array.isArray(v.blocking_reasons) &&
  v.blocking_reasons.every((r) => typeof r === 'string');
const isAutomation = (v: unknown): v is Automation =>
  record(v) && (v.state === 'active' || v.state === 'paused') &&
  Array.isArray(v.local_times) &&
  v.local_times.every((t) => typeof t === 'string' && /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(t)) &&
  (v.destination_id === null || typeof v.destination_id === 'string') &&
  v.source === 'eligible_assets' && v.provider === 'facebook_page' &&
  natural(v.max_per_day) && typeof v.timezone === 'string';

const weeklyView = (v: unknown): View<WeeklyRule | null> =>
  record(v) && Object.hasOwn(v, 'rule') && (v.rule === null || isRule(v.rule))
    ? { kind: 'ready', item: v.rule as WeeklyRule | null } : { kind: 'unknown' };

function previewView(v: unknown): View<Preview> {
  if (!record(v) || v.can_publish !== false || v.mode !== 'review_only' ||
    !Array.isArray(v.slots) || !v.slots.every(isSlot) ||
    !Array.isArray(v.assets) || !v.assets.every(isAsset) ||
    !natural(v.visible) || v.visible !== v.assets.length ||
    !natural(v.total_active_assets) || v.total_active_assets < v.visible ||
    !natural(v.limit) || v.limit === 0 || v.visible > v.limit) {
    return { kind: 'unknown' };
  }
  return { kind: 'ready', item: {
    slots: v.slots as Preview['slots'], assets: v.assets as Preview['assets'],
    visible: v.visible as number, total_active_assets: v.total_active_assets as number,
  } };
}

const automationView = (v: unknown): View<Automation | null> =>
  record(v) && v.ready === true && v.provider_calls === false &&
  Object.hasOwn(v, 'rule') && (v.rule === null || isAutomation(v.rule))
    ? { kind: 'ready', item: v.rule as Automation | null } : { kind: 'unknown' };

async function getOnly(url: string, signal: AbortSignal): Promise<unknown> {
  let response: Response;
  try {
    response = await fetch(url, { method: 'GET', credentials: 'same-origin',
      headers: { Accept: 'application/json' }, signal });
  } catch (error) {
    if (signal.aborted) throw error;
    throw new Error('Error de red. No se pudo consultar la información.');
  }
  if ([401, 403, 409].includes(response.status)) {
    throw new Error('Acceso denegado o sesión/organización cambiada (401/403/409).');
  }
  if (!response.ok) throw new Error('El servidor no pudo completar la consulta.');
  try {
    const json: unknown = await response.json();
    return record(json) ? json.data : null;
  } catch {
    return null;
  }
}

export function RulesPanel() {
  const [weekly, setWeekly] = useState<View<WeeklyRule | null>>({ kind: 'loading' });
  const [preview, setPreview] = useState<View<Preview>>({ kind: 'loading' });
  const [automation, setAutomation] = useState<View<Automation | null>>({ kind: 'loading' });

  useEffect(() => {
    const controller = new AbortController();
    async function load<T>(path: string, validate: (data: unknown) => View<T>,
      update: (result: View<T>) => void) {
      try {
        const data = await getOnly(path, controller.signal);
        if (!controller.signal.aborted) update(validate(data));
      } catch (error) {
        if (!controller.signal.aborted) update({
          kind: 'error', message: error instanceof Error ? error.message : 'Error desconocido.',
        });
      }
    }
    void load('/api/admin/rules/weekly', weeklyView, setWeekly);
    void load('/api/admin/rules/weekly/preview', previewView, setPreview);
    void load('/api/admin/rules/automation', automationView, setAutomation);
    return () => controller.abort();
  }, []);

  return (
    <section className="admin-settings" id="reglas" aria-labelledby="rules-panel-title">
      <span className="admin-kicker">REGLAS · SOLO LECTURA</span>
      <h2 id="rules-panel-title">Reglas semanales y automatización</h2>
      <p>Configuración interna. No ejecuta, autoriza ni acredita publicaciones externas.</p>
      <section aria-labelledby="weekly-title">
        <h3 id="weekly-title">Regla semanal vigente</h3>
        {weekly.kind === 'loading' && <p role="status">Cargando regla semanal…</p>}
        {weekly.kind === 'error' && <p role="alert">No se pudo cargar la regla. {weekly.message}</p>}
        {weekly.kind === 'unknown' && <p role="status">UNKNOWN: regla semanal no verificable.</p>}
        {weekly.kind === 'ready' && weekly.item === null &&
          <p role="status">No hay una regla semanal configurada.</p>}
        {weekly.kind === 'ready' && weekly.item !== null && <dl>
          <dt>Días</dt><dd>{weekly.item.weekdays.map((d) => dayLabel[d]).join(', ') || 'Sin días'}</dd>
          <dt>Hora local</dt><dd>{weekly.item.local_time} · {weekly.item.timezone}</dd>
          <dt>Máximo diario</dt><dd>{weekly.item.max_per_day}</dd>
          <dt>Modo</dt><dd>Revisión humana, sin publicación automática</dd>
        </dl>}
      </section>
      <section aria-labelledby="preview-title">
        <h3 id="preview-title">Vista previa de programación</h3>
        {preview.kind === 'loading' && <p role="status">Cargando vista previa…</p>}
        {preview.kind === 'error' && <p role="alert">No se pudo cargar la vista previa. {preview.message}</p>}
        {preview.kind === 'unknown' && <p role="status">UNKNOWN: vista previa no verificable.</p>}
        {preview.kind === 'ready' && <>
          {preview.item.slots.length === 0
            ? <p role="status">No hay horarios propuestos.</p>
            : <ol aria-label="Horarios propuestos UTC">
              {preview.item.slots.map((slot) =>
                <li key={slot.scheduled_at_utc}>{slot.scheduled_at_utc}</li>)}
            </ol>}
          <p>{preview.item.visible} de {preview.item.total_active_assets} recursos visibles.</p>
          {preview.item.assets.length > 0 &&
            <div style={{ maxWidth: '100%', overflowX: 'auto' }}>
              <table>
                <caption>Elegibilidad interna de recursos, sin publicación</caption>
                <thead><tr><th scope="col">Recurso</th><th scope="col">Elegibilidad</th></tr></thead>
                <tbody>{preview.item.assets.map((asset) =>
                  <tr key={asset.id}><td>{asset.name}</td>
                    <td>{asset.eligible ? 'Elegible para preparación' :
                      'Pendiente: ' + (asset.blocking_reasons.join(', ') || 'UNKNOWN')}</td></tr>)}
                </tbody>
              </table>
            </div>}
        </>}
      </section>
      <section aria-labelledby="automation-title">
        <h3 id="automation-title">Automatización interna</h3>
        {automation.kind === 'loading' && <p role="status">Cargando automatización…</p>}
        {automation.kind === 'error' && <p role="alert">No se pudo cargar la automatización. {automation.message}</p>}
        {automation.kind === 'unknown' && <p role="status">UNKNOWN: automatización no verificable.</p>}
        {automation.kind === 'ready' && automation.item === null &&
          <p role="status">No hay una automatización configurada.</p>}
        {automation.kind === 'ready' && automation.item !== null && <dl>
          <dt>Preferencia</dt><dd>{automation.item.state === 'active' ? 'Activa (configuración interna)' : 'Pausada'}</dd>
          <dt>Horas locales</dt><dd>{automation.item.local_times.join(', ') || 'Sin horarios'}</dd>
          <dt>Zona horaria</dt><dd>{automation.item.timezone}</dd>
          <dt>Máximo diario</dt><dd>{automation.item.max_per_day}</dd>
          <dt>Destino</dt><dd>{automation.item.destination_id === null ? 'UNKNOWN' : 'Configurado internamente'}</dd>
        </dl>}
        <p>Esta consulta no llama al proveedor y no demuestra entrega ni publicación.</p>
      </section>
    </section>
  );
}
