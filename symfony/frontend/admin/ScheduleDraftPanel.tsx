import { useEffect, useMemo, useState, type FormEvent } from 'react';

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

type ManualDestination = {
  id: string;
  label: string;
  active: boolean;
  created_at: string;
  disabled_at: string | null;
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
  manual_destination: { id: string; label: string } | null;
};

type QueueItem = {
  draft_id: string;
  asset_id: string;
  asset_name: string;
  scheduled_at_utc: string;
  local_date: string;
  local_time: string;
  timezone: string;
  status: 'prepared' | 'failed';
  due: boolean;
  handoff_updated_at: string;
  destination: { id: string; label: string; active: boolean } | null;
};

type Props = {
  slots: Slot[];
  assets: Asset[];
  canEdit: boolean;
  csrf: string | null;
  canManualHandoff: boolean;
  manualHandoffCsrf: string | null;
  manualDestinationCsrf: string | null;
};

export function ScheduleDraftPanel({
  slots,
  assets,
  canEdit,
  csrf,
  canManualHandoff,
  manualHandoffCsrf,
  manualDestinationCsrf,
}: Props) {
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [total, setTotal] = useState(0);
  const [handoffReady, setHandoffReady] = useState(false);
  const [destinations, setDestinations] = useState<ManualDestination[]>([]);
  const [destinationReady, setDestinationReady] = useState(false);
  const [queue, setQueue] = useState<QueueItem[]>([]);
  const [queueTotal, setQueueTotal] = useState(0);
  const [queueReady, setQueueReady] = useState(false);
  const [destinationByDraft, setDestinationByDraft] = useState<Record<string, string>>({});
  const [newDestinationLabel, setNewDestinationLabel] = useState('');
  const [assetId, setAssetId] = useState('');
  const [utcSlot, setUtcSlot] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [feedback, setFeedback] = useState('');
  const [error, setError] = useState('');

  const eligibleAssets = assets.filter((asset) => asset.eligible);
  const activeDestinations = useMemo(
    () => destinations.filter((destination) => destination.active),
    [destinations],
  );

  async function load(signal?: AbortSignal) {
    try {
      const [scheduleResponse, destinationResponse, queueResponse] = await Promise.all([
        fetch('/api/admin/schedules', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal,
        }),
        fetch('/api/admin/manual-destinations', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal,
        }),
        fetch('/api/admin/manual-handoff-queue', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal,
        }),
      ]);
      const [scheduleBody, destinationBody, queueBody] = await Promise.all([
        scheduleResponse.json(),
        destinationResponse.json(),
        queueResponse.json(),
      ]);
      if (!scheduleResponse.ok) {
        throw new Error(scheduleBody?.error?.message ?? 'No se pudo abrir la agenda.');
      }
      if (!destinationResponse.ok) {
        throw new Error(destinationBody?.error?.message ?? 'No se pudieron cargar los destinos manuales.');
      }
      if (!queueResponse.ok) {
        throw new Error(queueBody?.error?.message ?? 'No se pudo cargar la cola manual.');
      }

      const nextDrafts = scheduleBody.data.drafts as Draft[];
      const nextDestinations = destinationBody.data.destinations as ManualDestination[];
      const nextQueue = queueBody.data.items as QueueItem[];
      setDrafts(nextDrafts);
      setTotal(scheduleBody.data.total as number);
      setHandoffReady(scheduleBody.data.manual_handoff_ready === true);
      setDestinations(nextDestinations);
      setDestinationReady(destinationBody.data.ready === true);
      setQueue(nextQueue);
      setQueueTotal(queueBody.data.total as number);
      setQueueReady(queueBody.data.ready === true);

      setDestinationByDraft((current) => {
        const next = { ...current };
        for (const draft of nextDrafts) {
          if (!next[draft.id] && draft.manual_destination?.id) {
            next[draft.id] = draft.manual_destination.id;
          }
        }
        return next;
      });
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

  async function createDestination(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canManualHandoff || !manualDestinationCsrf || busyId !== null) return;
    const label = newDestinationLabel.trim();
    if (label.length < 2) return;

    setBusyId('destination-create');
    setError('');
    setFeedback('');
    try {
      const response = await fetch('/api/admin/manual-destinations', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': manualDestinationCsrf,
        },
        body: JSON.stringify({ label }),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo crear el destino manual.');
      }
      setFeedback(body.data.changed
        ? 'Destino manual creado. Sigue siendo una etiqueta interna, sin conexión externa.'
        : 'Ese destino manual ya existía.');
      setNewDestinationLabel('');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo crear el destino manual.');
    } finally {
      setBusyId(null);
    }
  }

  async function toggleDestination(destination: ManualDestination) {
    if (!canManualHandoff || !manualDestinationCsrf || busyId !== null) return;
    setBusyId('destination-' + destination.id);
    setError('');
    setFeedback('');
    try {
      const response = await fetch('/api/admin/manual-destinations/' + encodeURIComponent(destination.id), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': manualDestinationCsrf,
        },
        body: JSON.stringify({ active: !destination.active }),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo actualizar el destino manual.');
      }
      if (body?.data?.provider_calls !== false) {
        throw new Error('No se confirmó el límite de destino interno.');
      }
      setFeedback(destination.active
        ? 'Destino manual desactivado. El historial se conserva.'
        : 'Destino manual reactivado.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo actualizar el destino manual.');
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

    const destinationId = destinationByDraft[draft.id] ?? draft.manual_destination?.id ?? '';
    if (action === 'prepare' && !activeDestinations.some((destination) => destination.id === destinationId)) {
      setError('Selecciona un destino manual activo antes de preparar la salida.');
      return;
    }

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
          body: JSON.stringify(action === 'prepare'
            ? { action, destination_id: destinationId }
            : { action }),
        },
      );
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo registrar la salida manual.');
      }
      if (body?.data?.publishes !== false || body?.data?.provider_calls !== false
        || body?.data?.external_evidence !== false) {
        throw new Error('No se confirmó el límite de salida manual segura.');
      }

      const labels: Record<typeof action, string> = {
        prepare: 'Salida manual preparada con destino explícito. Aún no se ha publicado nada.',
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

      {canManualHandoff && manualDestinationCsrf && destinationReady &&
        <section className="manual-destination-panel" aria-labelledby="manual-destination-title">
          <div>
            <strong id="manual-destination-title">Destinos manuales internos</strong>
            <p>Son etiquetas de trabajo humano. No contienen credenciales ni conectan una plataforma externa.</p>
          </div>
          <form onSubmit={createDestination} className="manual-destination-form">
            <label htmlFor="manual-destination-label">Nuevo destino</label>
            <div>
              <input id="manual-destination-label" minLength={2} maxLength={80}
                value={newDestinationLabel}
                onChange={(event) => setNewDestinationLabel(event.target.value)}
                placeholder="Ej. Canal editorial A" />
              <button type="submit"
                disabled={busyId !== null || newDestinationLabel.trim().length < 2}>
                {busyId === 'destination-create' ? 'Creando…' : 'Crear destino'}
              </button>
            </div>
          </form>
          {destinations.length === 0
            ? <p>No hay destinos manuales todavía.</p>
            : <ul className="manual-destination-list">
                {destinations.map((destination) =>
                  <li key={destination.id}>
                    <span>
                      <strong>{destination.label}</strong>
                      <small>{destination.active ? 'Activo' : 'Desactivado'}</small>
                    </span>
                    <button type="button" disabled={busyId !== null}
                      onClick={() => void toggleDestination(destination)}>
                      {busyId === 'destination-' + destination.id
                        ? 'Guardando…'
                        : destination.active ? 'Desactivar' : 'Reactivar'}
                    </button>
                  </li>)}
              </ul>}
        </section>}

      {canManualHandoff && manualDestinationCsrf && !destinationReady &&
        <p>El catálogo de destinos manuales todavía requiere migración.</p>}

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
                    {draft.manual_destination &&
                      <small>Destino manual: {draft.manual_destination.label}</small>}
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
                          <>
                            <label className="manual-destination-choice">
                              Destino manual
                              <select value={destinationByDraft[draft.id] ?? draft.manual_destination?.id ?? ''}
                                onChange={(event) => setDestinationByDraft((current) => ({
                                  ...current,
                                  [draft.id]: event.target.value,
                                }))}>
                                <option value="">Selecciona destino</option>
                                {activeDestinations.map((destination) =>
                                  <option key={destination.id} value={destination.id}>{destination.label}</option>)}
                              </select>
                            </label>
                            <button type="button" disabled={busyId !== null || activeDestinations.length === 0}
                              onClick={() => void updateManualHandoff(draft, 'prepare')}>
                              {busyId === 'handoff-' + draft.id ? 'Guardando…' : 'Preparar salida manual'}
                            </button>
                          </>}
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
                    {canEdit && csrf && draft.status === 'draft'
                      && !['prepared', 'completed'].includes(draft.manual_handoff_status) &&
                      <button type="button" disabled={busyId !== null}
                        aria-label={'Cancelar borrador de ' + draft.asset_name}
                        onClick={() => void cancel(draft)}>
                        {busyId === draft.id ? 'Cancelando…' : 'Cancelar borrador'}
                      </button>}
                  </div>
                </li>)}
            </ul>
          </div>}

      {!loading && queueReady &&
        <section className="manual-handoff-queue" aria-labelledby="manual-handoff-queue-title">
          <div>
            <strong id="manual-handoff-queue-title">Cola interna de handoff · {queueTotal}</strong>
            <p>Primero aparecen los borradores vencidos o listos para atención humana.</p>
          </div>
          {queue.length === 0
            ? <p>No hay salidas manuales preparadas ni fallidas.</p>
            : <ul>
                {queue.map((item) =>
                  <li key={item.draft_id}>
                    <div>
                      <strong>{item.asset_name}</strong>
                      <span>{item.local_date} · {item.local_time} ({item.timezone})</span>
                      <small>{item.destination?.label ?? 'Destino sin resolver'}</small>
                    </div>
                    <span className={'manual-queue-state ' + (item.due ? 'due' : 'future')}>
                      {item.status === 'failed'
                        ? 'Falló, requiere decisión'
                        : item.due ? 'Listo para atención' : 'Preparado, aún no vence'}
                    </span>
                  </li>)}
              </ul>}
        </section>}

      <p className="weekly-safety">
        <strong>Solo agenda y handoff humano.</strong> Destinos y cola son organización interna.
        No llaman proveedores, no mueven contenido fuera de GrindFlow y no prueban una publicación externa.
      </p>
    </section>
  );
}
