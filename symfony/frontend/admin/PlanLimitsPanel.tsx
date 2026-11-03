import { useEffect, useState, type FormEvent } from 'react';

type Dimension = 'storage_bytes' | 'traffic_bytes' | 'queued_jobs' | 'api_calls';
type Plan = { plan: string; revision: number; updated_at: string; limits: Record<Dimension, number | null> };
type State = { kind: 'loading' } | { kind: 'unavailable'; reason: string } | {
  kind: 'ready'; plans: Plan[]; csrf: string;
};
const DIMENSIONS: readonly Dimension[] = ['storage_bytes', 'traffic_bytes', 'queued_jobs', 'api_calls'];
const NAMES: Record<Dimension, string> = {
  storage_bytes: 'Almacenamiento (bytes)', traffic_bytes: 'Tráfico (bytes)',
  queued_jobs: 'Trabajos en cola', api_calls: 'Llamadas API',
};
const KEY = /^[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}$/;

/** This component must only be mounted after server-provided platform-admin permission. */
export function PlanLimitsPanel({ permitted }: { permitted: boolean }) {
  const [state, setState] = useState<State>({ kind: 'loading' });
  const [selected, setSelected] = useState('');
  const [fields, setFields] = useState<Record<Dimension, string>>({
    storage_bytes: '', traffic_bytes: '', queued_jobs: '', api_calls: '',
  });
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState('');

  useEffect(() => {
    if (!permitted) { setState({ kind: 'unavailable', reason: 'No autorizado' }); return; }
    let active = true;
    void (async () => {
      try {
        const response = await fetch('/api/admin/plans', { method: 'GET', credentials: 'same-origin', cache: 'no-store' });
        const payload = await response.json();
        if (!active) return;
        if (!response.ok || !Array.isArray(payload?.data?.plans) || typeof payload?.data?.csrf !== 'string') {
          setState({ kind: 'unavailable', reason: 'Los límites no están disponibles o tu acceso ha cambiado.' }); return;
        }
        const plans: Plan[] = payload.data.plans;
        if (plans.some(p => !KEY.test(p.plan) || !Number.isSafeInteger(p.revision)
          || p.revision < 1 || DIMENSIONS.some(d => p.limits[d] !== null
            && (!Number.isSafeInteger(p.limits[d]) || p.limits[d]! < 0)))) {
          setState({ kind: 'unavailable', reason: 'Valores fuera del rango seguro. Recarga desde el servidor.' }); return;
        }
        setState({ kind: 'ready', plans, csrf: payload.data.csrf });
      } catch {
        if (active) setState({ kind: 'unavailable', reason: 'No se pudo consultar el catálogo.' });
      }
    })();
    return () => { active = false; };
  }, [permitted]);

  if (!permitted) return null;
  if (state.kind === 'loading') return <section aria-live="polite">Cargando límites…</section>;
  if (state.kind === 'unavailable') return <section role="alert">{state.reason}</section>;

  const plan = state.plans.find(p => p.plan === selected);
  const choose = (id: string) => {
    setSelected(id);
    const entry = state.plans.find(p => p.plan === id);
    setFields(Object.fromEntries(DIMENSIONS.map(d => [d, entry?.limits[d] == null ? '' : String(entry.limits[d])])) as Record<Dimension, string>);
    setFeedback('');
  };

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!KEY.test(selected) || busy) return;
    const limits = {} as Record<Dimension, number | null>;
    for (const dimension of DIMENSIONS) {
      const value = fields[dimension];
      if (value === '') { limits[dimension] = null; continue; }
      if (!/^(0|[1-9][0-9]*)$/.test(value)) { setFeedback('Solo números enteros no negativos. El vacío significa desconocido.'); return; }
      const number = Number(value);
      if (!Number.isSafeInteger(number)) { setFeedback('El número excede la precisión segura del navegador.'); return; }
      limits[dimension] = number;
    }
    setBusy(true);
    setFeedback('');
    try {
      const response = await fetch('/api/admin/plans/' + encodeURIComponent(selected), {
        method: 'PUT', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
        body: JSON.stringify({ expected_revision: plan?.revision ?? 0, limits }),
      });
      const payload = await response.json();
      if (!response.ok || payload?.data?.plan == null) {
        setFeedback(response.status === 409 ? 'Otro cambio ganó la carrera; recarga la página antes de editar.'
          : 'La edición no pudo completarse. Tu acceso o el esquema pueden haber cambiado.');
        return;
      }
      const updated: Plan = payload.data.plan;
      setState(previous => previous.kind === 'ready'
        ? { ...previous, plans: [...previous.plans.filter(p => p.plan !== updated.plan), updated].sort((a,b) => a.plan.localeCompare(b.plan)) }
        : previous);
      setFeedback(payload.data.changed ? 'Cambio guardado con auditoría.' : 'Sin cambios.');
    } catch {
      setFeedback('No se pudo confirmar la edición. Recarga el catálogo antes de repetir.');
    } finally {
      setBusy(false);
    }
  };
  return <section id="planes" className="admin-settings" aria-label="Límites globales de planes">
    <h2>Límites por plan</h2>
    <p>Solo administradores globales. Un campo vacío significa «desconocido»; no significa ilimitado.</p>
    <label htmlFor="plan-limit-key">Identificador del plan</label>
    <input id="plan-limit-key" list="plan-keys" value={selected} onChange={e => choose(e.target.value)}
      maxLength={128} autoComplete="off" />
    <datalist id="plan-keys">{state.plans.map(p => <option value={p.plan} key={p.plan} />)}</datalist>
    <form onSubmit={save}>
      {DIMENSIONS.map(d => <label key={d} htmlFor={'limit-' + d}>{NAMES[d]}
        <input id={'limit-' + d} inputMode="numeric" value={fields[d]}
          onChange={e => setFields(f => ({...f, [d]: e.target.value}))} disabled={busy} />
      </label>)}
      <p>Revisión: {plan?.revision ?? 'nuevo'}.</p>
      <button disabled={busy || !KEY.test(selected)} type="submit">{busy ? 'Guardando…' : 'Guardar límites'}</button>
    </form>
    {feedback && <p role="status">{feedback}</p>}
  </section>;
}
