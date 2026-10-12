import { useEffect, useState } from 'react';

type Day = {
  date_utc: string;
  prepared_attempts: number;
  completed_reports: number;
  failed_attempts: number;
};


type Mvp = {
  content: { ready: boolean; available_assets: number | null; original_blobs: number | null; used_bytes: number | null };
  upcoming: { ready: boolean; total: number | null; items: {
    asset_name: string; scheduled_at_utc: string; provider: string | null; destination: string | null;
  }[] };
  deliveries: { ready: boolean; counts: Record<string, number> | null; recent: {
    asset_name: string; status: string; updated_at: string; provider: string; destination: string;
  }[] };
  traffic: { ready: boolean; clicks: null };
};
const deliveryLabels: Record<string, string> = {
  published: 'Publicada', ambiguous: 'Resultado incierto',
  rate_limited: 'Proveedor limitado', authentication_failed: 'Autenticación requerida',
  rejected: 'Rechazada', in_flight: 'En curso',
};

function formatBytes(bytes: number | null): string {
  if (bytes === null) return 'No disponible';
  if (bytes < 1048576) return new Intl.NumberFormat('es-CO').format(bytes) + ' bytes';
  return (bytes / 1048576).toFixed(1) + ' MB';
}

type Summary = {
  ready: boolean;
  week_start_utc: string;
  week_end_exclusive_utc: string;
  days: Day[];
  totals: Omit<Day, 'date_utc'> | null;
  traffic: { ready: boolean; clicks: number | null };
  external_publications_verified: null;
  provider_calls: false;
  mvp: Mvp;
};

type SummaryState =
  | { kind: 'loading' }
  | { kind: 'error'; message: string }
  | { kind: 'ready'; summary: Summary };

function currentMondayUtc(): string {
  const now = new Date();
  const daysSinceMonday = (now.getUTCDay() + 6) % 7;
  const monday = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(),
    now.getUTCDate() - daysSinceMonday));
  return monday.toISOString().slice(0, 10);
}

function shiftWeek(start: string, days: number): string {
  const date = new Date(start + 'T00:00:00Z');
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function dayLabel(day: string): string {
  return new Date(day + 'T12:00:00Z').toLocaleDateString('es-CO', {
    timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short',
  });
}

/** Internal work counts only; never claim a reported handoff proves publication. */
export function PilotWeeklySummaryPanel() {
  const [week, setWeek] = useState(currentMondayUtc);
  const [state, setState] = useState<SummaryState>({ kind: 'loading' });

  useEffect(() => {
    const controller = new AbortController();
    setState({ kind: 'loading' });
    fetch('/api/admin/pilot/weekly-summary?week=' + encodeURIComponent(week), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok) throw new Error(body?.error?.message ?? 'No se pudo consultar la semana.');
        const data = body?.data as Summary;
        if (!data || data.provider_calls !== false
          || data.external_publications_verified !== null) {
          throw new Error('El servidor no confirmó el alcance de la medición interna.');
        }
        return data;
      })
      .then((summary) => {
        if (!controller.signal.aborted) setState({ kind: 'ready', summary });
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        setState({
          kind: 'error',
          message: error instanceof Error ? error.message : 'No se pudo consultar la semana.',
        });
      });

    return () => controller.abort();
  }, [week]);

  const current = currentMondayUtc();
  const oldest = shiftWeek(current, -77);
  const first = state.kind === 'ready' ? state.summary.week_start_utc : week;
  return (
    <section className="pilot-summary" id="piloto" aria-labelledby="pilot-summary-title">
      <span className="admin-kicker">S5 · PILOTO · SOLO LECTURA</span>
      <h2 id="pilot-summary-title">Resumen semanal del piloto</h2>
      <p>Actividad interna registrada para la organización seleccionada, con días en UTC.
        Un registro «realizada» es una declaración humana, no prueba de publicación externa.</p>
      <nav className="pilot-week-controls" aria-label="Semanas del piloto">
        <button type="button" disabled={week <= oldest}
          onClick={() => setWeek(shiftWeek(week, -7))}>Semana anterior</button>
        <strong>Semana del {first} (UTC)</strong>
        <button type="button" disabled={week >= current}
          onClick={() => setWeek(shiftWeek(week, 7))}>Semana siguiente</button>
      </nav>

      {state.kind === 'ready' && <div aria-label="Resumen de operaciones MVP">
        <div className="pilot-mvp-scroll" style={{ maxWidth: '100%', overflowX: 'auto' }}>
          <table style={{ minWidth: 440, width: '100%', borderCollapse: 'collapse' }}>
            <caption>Métricas del piloto</caption>
            <thead><tr><th scope="col">Originales disponibles (no autoriza publicación)</th>
              <th scope="col">Próximos contenidos</th>
              <th scope="col">Entregas registradas como publicadas</th></tr></thead>
            <tbody><tr>
              <td>{state.summary.mvp?.content.ready
                ? state.summary.mvp.content.available_assets : '—'}</td>
              <td>{state.summary.mvp?.upcoming.ready
                ? state.summary.mvp.upcoming.total : '—'}</td>
              <td>{state.summary.mvp?.deliveries.ready
                ? state.summary.mvp.deliveries.counts?.published ?? 0 : '—'}</td>
            </tr></tbody>
          </table>
        </div>
        <p role="status">Almacenamiento de originales únicos: {' '}
          {state.summary.mvp?.content.ready
            ? formatBytes(state.summary.mvp.content.used_bytes) : 'No disponible'}.
          Tráfico atribuible: no disponible sin evidencia verificable.</p>
        <div className="pilot-mvp-scroll" style={{ maxWidth: '100%', overflowX: 'auto' }}>
          <table style={{ minWidth: 440, width: '100%', borderCollapse: 'collapse' }}>
            <caption>Próximas programaciones (UTC, hasta cinco)</caption>
            <thead><tr><th scope="col">Contenido</th><th scope="col">Fecha UTC</th>
              <th scope="col">Proveedor y destino</th></tr></thead>
            <tbody>{state.summary.mvp?.upcoming.items.map((item, index) => <tr key={index}>
              <td>{item.asset_name}</td><td>{item.scheduled_at_utc}</td>
              <td>{item.provider && item.destination
                ? item.provider + ' / ' + item.destination : 'Destino pendiente'}</td>
            </tr>)}</tbody>
          </table>
        </div>
        {state.summary.mvp?.upcoming.ready && state.summary.mvp.upcoming.total === 0
          && <p role="status">No hay próximas programaciones.</p>}
        {!state.summary.mvp?.upcoming.ready
          && <p role="status">La agenda no está disponible. No se presentan ceros.</p>}

        {state.summary.mvp?.deliveries.ready && <p role="status">
          Estados del ledger externo: {' '}
          {['ambiguous', 'rate_limited', 'authentication_failed', 'rejected', 'in_flight']
            .map((status) => deliveryLabels[status] + ': ' +
              (state.summary.mvp.deliveries.counts?.[status] ?? 0))
            .join(' · ')}. «Publicada» refleja exclusivamente el resultado almacenado del proveedor,
          no un piloto live auditado.
        </p>}
        <div className="pilot-mvp-scroll" style={{ maxWidth: '100%', overflowX: 'auto' }}>
          <table style={{ minWidth: 440, width: '100%', borderCollapse: 'collapse' }}>
            <caption>Últimas entregas del ledger externo (hasta seis)</caption>
            <thead><tr><th scope="col">Contenido</th><th scope="col">Estado</th>
              <th scope="col">Destino</th><th scope="col">Fecha UTC</th></tr></thead>
            <tbody>{state.summary.mvp?.deliveries.recent.map((item, index) => <tr key={index}>
              <td>{item.asset_name}</td><td>{deliveryLabels[item.status] ?? 'Desconocido'}</td>
              <td>{item.provider + ' / ' + item.destination}</td><td>{item.updated_at}</td>
            </tr>)}</tbody>
          </table>
        </div>
        {!state.summary.mvp?.deliveries.ready
          && <p role="status">No hay evidencia de entregas externas disponible.</p>}
        {state.summary.mvp?.deliveries.ready
          && state.summary.mvp.deliveries.recent.length === 0
          && <p role="status">No hay entregas externas registradas.</p>}
      </div>}
      {state.kind === 'loading' && <p role="status">Cargando resumen semanal…</p>}
      {state.kind === 'error' && <p role="alert">{state.message}</p>}
      {state.kind === 'ready' && !state.summary.ready &&
        <p role="status">La auditoría de salidas manuales todavía requiere migración.
          No hay métricas disponibles para esta semana.</p>}
      {state.kind === 'ready' && state.summary.ready && state.summary.totals && <>
        <div className="pilot-totals" aria-label="Totales de actividad interna">
          <div><small>Preparaciones</small><strong>{state.summary.totals.prepared_attempts}</strong></div>
          <div><small>Realizadas según registro humano</small>
            <strong>{state.summary.totals.completed_reports}</strong></div>
          <div><small>Intentos fallidos</small><strong>{state.summary.totals.failed_attempts}</strong></div>
        </div>
        {state.summary.totals.prepared_attempts === 0
          && state.summary.totals.completed_reports === 0
          && state.summary.totals.failed_attempts === 0
          && <p role="status">No hay actividad interna registrada en esta semana.</p>}
        <a className="pilot-csv-download"
          href={'/api/admin/pilot/weekly-summary.csv?week=' + encodeURIComponent(week)}>
          Descargar resumen CSV
        </a>
        <div className="pilot-day-scroll">
          <table>
            <caption>Actividad por día, semana en UTC</caption>
            <thead><tr><th scope="col">Día</th><th scope="col">Preparaciones</th>
              <th scope="col">Realizadas</th><th scope="col">Fallidas</th></tr></thead>
            <tbody>{state.summary.days.map((day) =>
              <tr key={day.date_utc}>
                <th scope="row">{dayLabel(day.date_utc)}</th>
                <td>{day.prepared_attempts}</td><td>{day.completed_reports}</td>
                <td>{day.failed_attempts}</td>
              </tr>)}</tbody>
          </table>
        </div>
      </>}
      <p className="pilot-traffic-note">Tráfico y conversiones: aún no integrados en Symfony.
        Las entregas externas visibles proceden del ledger S4; no se infieren clics,
        ingresos ni evidencia de un piloto live.</p>
    </section>
  );
}
