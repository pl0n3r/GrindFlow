import { useEffect, useState } from 'react';

type Day = {
  date_utc: string;
  prepared_attempts: number;
  completed_reports: number;
  failed_attempts: number;
};

type Summary = {
  ready: boolean;
  week_start_utc: string;
  week_end_exclusive_utc: string;
  days: Day[];
  totals: Omit<Day, 'date_utc'> | null;
  traffic: { ready: boolean; clicks: number | null };
  external_publications_verified: null;
  provider_calls: false;
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
        No se presentan clics, entregas externas ni ingresos estimados.</p>
    </section>
  );
}
