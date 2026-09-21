import { useEffect, useState, type FormEvent } from 'react';

type Slot = {
  local_date: string;
  local_time: string;
  timezone: string;
  capacity: number;
  scheduled_at_utc: string;
};

type Asset = {
  id: string;
  name: string;
  eligible: boolean;
};

type Draft = {
  id: string;
  asset_id: string;
  asset_name: string;
  scheduled_at_utc: string;
  timezone: string;
  local_date: string;
  local_time: string;
  status: 'draft' | 'cancelled';
  manual_handoff_status: 'none' | 'prepared' | 'completed' | 'failed';
  manual_handoff_updated_at: string | null;
};

type Props = {
  slots: Slot[];
  assets: Asset[];
  canEdit: boolean;
  csrf: string | null;
  canManualHandoff: boolean;
  manualHandoffCsrf: string | null;
};

export function ScheduleDraftPanel({
  slots, assets, canEdit, csrf, canManualHandoff, manualHandoffCsrf,
}: Props) {
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [total, setTotal] = useState(0);
  const [handoffReady, setHandoffReady] = useState(false);
  const [assetId, setAssetId] = useState('');
  const [utcSlot, setUtcSlot] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [feedback, setFeedback] = useState('');
  const [error, setError] = useState('');

  const eligibleAssets = assets.filter((asset) => asset.eligible);

  async function load(signal?: AbortSignal) {
    try {
      const response = await fetch('/api/admin/schedules', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal,
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo abrir la agenda.');
      }
      setDrafts(body.data.drafts as Draft[]);
      setTotal(body.data.total as number);
      setHandoffReady(body.data.manual_handoff_ready === true);
    } catch (caught) {
      if (caught instanceof DOMException && caught.name === 'AbortError') return;
      setError(caught instanceof Error ? caught.message : 'No se pudo abrir la agenda.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const controller = new AbortController();
    void load(controller.signal);
    return () => controller.abort();
  }, []);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canEdit || !csrf || !assetId || !utcSlot || busyId !== null) return;
    if (!eligibleAssets.some((asset) => asset.id === assetId)
      || !slots.some((slot) => slot.scheduled_at_utc === utcSlot)) return;

    setBusyId('create');
    setError('');
    setFeedback('');
    try {
      const response = await fetch('/api/admin/schedules', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({ asset_id: assetId, scheduled_at_utc: utcSlot }),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo crear el borrador.');
      }
      if (body?.data?.publishes !== false) {
        throw new Error('No se confirmó el límite de publicación segura.');
      }
      setFeedback(body.data.changed
        ? 'Borrador interno guardado. No se ha publicado nada.'
        : 'El borrador de ese recurso y horario ya existía.');
      setAssetId('');
      setUtcSlot('');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo crear el borrador.');
    } finally {
      setBusyId(null);
    }
  }

  async function cancel(draft: Draft) {
    if (!canEdit || !csrf || busyId !== null || draft.status !== 'draft') return;
    setBusyId(draft.id);
    setFeedback('');
    setError('');
    try {
      const response = await fetch('/api/admin/schedules/' + encodeURIComponent(draft.id) + '/cancel', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo cancelar el borrador.');
      }
      setFeedback('Borrador cancelado. El registro se conserva en el historial.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo cancelar el borrador.');
    } finally {
      setBusyId(null);
    }
  }

  async function updateManualHandoff(
    draft: Draft,
    action: 'prepare' | 'complete' | 'fail',
  ) {
    if (!canManualHandoff || !manualHandoffCsrf || !handoffReady
      || busyId !== null || draft.status !== 'draft') return;

    setBusyId('handoff-' + draft.id);
    setFeedback('');
    setError('');
    try {
      const response = await fetch(
        '/api/admin/schedules/' + encodeURIComponent(draft.id) + '/manual-handoff',
        {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': manualHandoffCsrf,
          },
          body: JSON.stringify({ action }),
        },
      );
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo registrar la salida manual.');
      }
      if (body?.data?.publishes !== false || body?.data?.provider_calls !== false) {
        throw new Error('No se confirmó el límite de salida manual segura.');
      }

      const labels: Record<typeof action, string> = {
        prepare: 'Salida manual preparada. Aún no se ha publicado nada.',
        complete: 'Salida manual registrada como realizada por una persona.',
        fail: 'Fallo manual registrado. Puedes preparar un nuevo intento.',
      };
      setFeedback(labels[action]);
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo registrar la salida manual.');
    } finally {
      setBusyId(null);
    }
  }


  return (
    <section className="schedule-draft-panel" aria-labelledby="schedule-draft-title">
      <div>
        <span className="admin-kicker">S4 · AGENDA INTERNA</span>
        <h3 id="schedule-draft-title">Borradores persistidos</h3>
        <p>Reserva un recurso revisado en un slot de la regla semanal. No conecta redes ni realiza publicaciones.</p>
      </div>
      {canEdit && csrf && eligibleAssets.length > 0 && slots.length > 0 &&
        <form className="schedule-draft-form" onSubmit={create}>
          <label htmlFor="schedule-draft-asset">Recurso listo</label>
          <select id="schedule-draft-asset" value={assetId}
            onChange={(event) => setAssetId(event.target.value)} required>
            <option value="">Selecciona un recurso</option>
            {eligibleAssets.map((asset) =>
              <option key={asset.id} value={asset.id}>{asset.name}</option>)}
          </select>
          <label htmlFor="schedule-draft-slot">Horario</label>
          <select id="schedule-draft-slot" value={utcSlot}
            onChange={(event) => setUtcSlot(event.target.value)} required>
            <option value="">Selecciona un slot</option>
            {slots.map((slot) =>
              <option key={slot.scheduled_at_utc} value={slot.scheduled_at_utc}>
                {slot.local_date} · {slot.local_time} ({slot.timezone}) · máximo {slot.capacity}
              </option>)}
          </select>
          <button disabled={!assetId || !utcSlot || busyId !== null} type="submit">
            {busyId === 'create' ? 'Guardando…' : 'Guardar borrador'}
          </button>
        </form>}
      {canEdit && csrf && eligibleAssets.length === 0 &&
        <p>No hay recursos listos para crear borradores. Resuelve primero los bloqueos S3.</p>}
      {(!canEdit || !csrf) && <p>Tu rol puede consultar los borradores, pero no crearlos ni cancelarlos.</p>}
      {feedback && <p className="weekly-feedback" role="status">{feedback}</p>}
      {error && <p className="weekly-error" role="alert">{error}</p>}
      {loading
        ? <p role="status">Cargando borradores…</p>
        : <div className="schedule-draft-history">
            <strong>Historial interno · {total} borradores</strong>
            {drafts.length === 0 && <p>No hay borradores registrados todavía.</p>}
            <ul>
              {drafts.map((draft) =>
                <li key={draft.id}>
                  <div>
                    <strong>{draft.asset_name}</strong>
                    <span>{draft.local_date} · {draft.local_time} ({draft.timezone})</span>
                    <small>{draft.status === 'draft' ? 'Borrador reservado' : 'Borrador cancelado'}</small>
                    {handoffReady &&
                      <small className={'manual-handoff-status ' + draft.manual_handoff_status}>
                        {draft.manual_handoff_status === 'prepared' && 'Salida manual preparada'}
                        {draft.manual_handoff_status === 'completed' && 'Salida manual registrada como realizada'}
                        {draft.manual_handoff_status === 'failed' && 'Salida manual con fallo registrado'}
                        {draft.manual_handoff_status === 'none' && 'Salida manual sin preparar'}
                      </small>}
                  </div>
                  <div className="schedule-draft-actions">
                    {canManualHandoff && manualHandoffCsrf && handoffReady && draft.status === 'draft' &&
                      <>
                        {(draft.manual_handoff_status === 'none' || draft.manual_handoff_status === 'failed') &&
                          <button type="button" disabled={busyId !== null}
                            onClick={() => void updateManualHandoff(draft, 'prepare')}>
                            {busyId === 'handoff-' + draft.id ? 'Guardando…' : 'Preparar salida manual'}
                          </button>}
                        {draft.manual_handoff_status === 'prepared' &&
                          <>
                            <button type="button" disabled={busyId !== null}
                              onClick={() => void updateManualHandoff(draft, 'complete')}>
                              {busyId === 'handoff-' + draft.id ? 'Guardando…' : 'Registrar realizada'}
                            </button>
                            <button type="button" disabled={busyId !== null}
                              onClick={() => void updateManualHandoff(draft, 'fail')}>
                              {busyId === 'handoff-' + draft.id ? 'Guardando…' : 'Registrar fallo'}
                            </button>
                          </>}
                      </>}
                    {canEdit && csrf && draft.status === 'draft' &&
                      <button type="button" disabled={busyId !== null}
                        aria-label={'Cancelar borrador de ' + draft.asset_name}
                        onClick={() => void cancel(draft)}>
                        {busyId === draft.id ? 'Cancelando…' : 'Cancelar borrador'}
                      </button>}
                  </div>
                </li>)}
            </ul>
          </div>}
      <p className="weekly-safety">
        <strong>Solo agenda y handoff humano.</strong> Preparar o cerrar una salida manual registra una decisión
        interna; no llama proveedores, no mueve contenido fuera de GrindFlow y no prueba una publicación externa.
      </p>
    </section>
  );
}
