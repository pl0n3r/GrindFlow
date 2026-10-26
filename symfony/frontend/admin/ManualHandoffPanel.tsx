import { useEffect, useState } from 'react';

// Read-only S4 projection. Backend membership/session checks remain authoritative.
type QueueRow = {
  draft_id: string;
  asset_name: string;
  scheduled_at_utc: string;
  status: 'prepared' | 'failed';
  due: boolean;
  destination: { id: string; label: string; active: boolean } | null;
};
type Destination = { id: string; label: string; active: boolean };
type View<T> =
  | { kind: 'loading' }
  | { kind: 'error'; message: string }
  | { kind: 'unknown' }
  | { kind: 'ready'; items: T[]; total: number };

const uuid = (v: unknown): v is string =>
  typeof v === 'string' &&
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(v);
const record = (v: unknown): v is Record<string, unknown> =>
  v !== null && typeof v === 'object' && !Array.isArray(v);
const nonNegative = (v: unknown): v is number =>
  typeof v === 'number' && Number.isSafeInteger(v) && v >= 0;
const isDestination = (v: unknown): v is Destination =>
  record(v) && uuid(v.id) && typeof v.label === 'string' &&
  typeof v.active === 'boolean';
const isQueueRow = (v: unknown): v is QueueRow =>
  record(v) && uuid(v.draft_id) && typeof v.asset_name === 'string' &&
  typeof v.scheduled_at_utc === 'string' &&
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(v.scheduled_at_utc) &&
  (v.status === 'prepared' || v.status === 'failed') &&
  typeof v.due === 'boolean' &&
  (v.destination === null || isDestination(v.destination));

async function readOnlyData(url: string, signal: AbortSignal): Promise<unknown> {
  let response: Response;
  try {
    response = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal,
    });
  } catch (error) {
    if (signal.aborted) throw error;
    throw new Error('Error de red. No se pudo consultar esta sección.');
  }
  if ([401, 403, 409].includes(response.status)) {
    throw new Error('Acceso denegado o sesión/organización cambiada (401/403/409).');
  }
  if (!response.ok) throw new Error('El servidor no pudo entregar esta sección.');
  try {
    const body: unknown = await response.json();
    return record(body) ? body.data : null;
  } catch {
    return null;
  }
}

function queueView(data: unknown): View<QueueRow> {
  if (!record(data) || data.ready !== true || data.provider_calls !== false ||
    !Array.isArray(data.items) || !data.items.every(isQueueRow) ||
    !nonNegative(data.total) || data.total < data.items.length ||
    !nonNegative(data.limit) || data.limit === 0 || data.items.length > data.limit) {
    return { kind: 'unknown' };
  }
  return { kind: 'ready', items: data.items as QueueRow[], total: data.total };
}

function destinationView(data: unknown): View<Destination> {
  if (!record(data) || data.ready !== true || data.provider_calls !== false ||
    !Array.isArray(data.destinations) || !data.destinations.every(isDestination) ||
    !nonNegative(data.total) || data.total < data.destinations.length ||
    !nonNegative(data.limit) || data.limit === 0 ||
    data.destinations.length > data.limit) {
    return { kind: 'unknown' };
  }
  return { kind: 'ready', items: data.destinations as Destination[], total: data.total };
}

export function ManualHandoffPanel() {
  const [queue, setQueue] = useState<View<QueueRow>>({ kind: 'loading' });
  const [destinations, setDestinations] = useState<View<Destination>>({ kind: 'loading' });

  useEffect(() => {
    const controller = new AbortController();
    const { signal } = controller;
    async function load<T>(
      endpoint: string, normalize: (data: unknown) => View<T>,
      update: (result: View<T>) => void,
    ) {
      try {
        const data = await readOnlyData(endpoint, signal);
        if (!signal.aborted) update(normalize(data));
      } catch (error) {
        if (!signal.aborted) update({
          kind: 'error',
          message: error instanceof Error ? error.message : 'UNKNOWN: lectura fallida.',
        });
      }
    }
    void load('/api/admin/manual-handoff-queue', queueView, setQueue);
    void load('/api/admin/manual-destinations', destinationView, setDestinations);
    return () => controller.abort();
  }, []);

  return (
    <section className="admin-settings" id="entregas-manuales" aria-labelledby="manual-handoff-title">
      <span className="admin-kicker">S4 · ENTREGA HUMANA · SOLO LECTURA</span>
      <h2 id="manual-handoff-title">Entregas manuales</h2>
      <p>Esta cola es una preparación interna para entrega humana. No acredita publicación
        externa ni permite publicar desde esta pantalla.</p>

      <section aria-labelledby="manual-queue-title">
        <h3 id="manual-queue-title">Cola de entregas pendientes</h3>
        {queue.kind === 'loading' && <p role="status">Cargando cola manual…</p>}
        {queue.kind === 'error' && <p role="alert">No se pudo cargar la cola. {queue.message}</p>}
        {queue.kind === 'unknown' && <p role="status">UNKNOWN: cola no verificable.</p>}
        {queue.kind === 'ready' && <>
          {queue.items.length === 0 && <p role="status">No hay entregas manuales pendientes.</p>}
          {queue.items.length > 0 && <div style={{ maxWidth: '100%', overflowX: 'auto' }}>
            <table style={{ width: '100%', minWidth: 640 }}>
              <caption>Entregas manuales pendientes en esta organización</caption>
              <thead><tr><th scope="col">Recurso</th><th scope="col">Horario UTC</th>
                <th scope="col">Estado interno</th><th scope="col">Destino</th></tr></thead>
              <tbody>{queue.items.map((item) => <tr key={item.draft_id}>
                <td>{item.asset_name}</td>
                <td>{item.scheduled_at_utc}{item.due ? ' · Horario alcanzado' : ''}</td>
                <td>{item.status === 'prepared' ? 'Preparada' : 'Intento fallido'}</td>
                <td>{item.destination?.label ?? 'UNKNOWN'}</td>
              </tr>)}</tbody>
            </table>
          </div>}
          {queue.total > queue.items.length &&
            <p role="status">Mostrando {queue.items.length} de {queue.total} entregas pendientes.</p>}
        </>}
      </section>

      <section aria-labelledby="manual-destinations-title">
        <h3 id="manual-destinations-title">Catálogo de destinos de entrega</h3>
        {destinations.kind === 'loading' && <p role="status">Cargando destinos…</p>}
        {destinations.kind === 'error' && <p role="alert">No se pudieron cargar los destinos. {destinations.message}</p>}
        {destinations.kind === 'unknown' && <p role="status">UNKNOWN: destinos no verificables.</p>}
        {destinations.kind === 'ready' && <>
          {destinations.items.length === 0 && <p role="status">No hay destinos manuales registrados.</p>}
          {destinations.items.length > 0 && <div style={{ maxWidth: '100%', overflowX: 'auto' }}>
            <table style={{ width: '100%', minWidth: 440 }}>
              <caption>Destinos registrados en la organización seleccionada</caption>
              <thead><tr><th scope="col">Destino</th><th scope="col">Disponibilidad</th></tr></thead>
              <tbody>{destinations.items.map((item) => <tr key={item.id}>
                <td>{item.label}</td><td>{item.active ? 'Activo' : 'Deshabilitado'}</td>
              </tr>)}</tbody>
            </table>
          </div>}
          {destinations.total > destinations.items.length &&
            <p role="status">Mostrando {destinations.items.length} de {destinations.total} destinos.</p>}
        </>}
      </section>
    </section>
  );
}
