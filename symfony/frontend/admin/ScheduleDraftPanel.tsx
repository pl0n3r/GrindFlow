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

type Destination = {
  id: string;
  label: string;
  active: boolean;
  created_at: string;
  disabled_at: string | null;
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
  const [destinationReady, setDestinationReady] = useState(false);
  const [destinations, setDestinations] = useState<Destination[]>([]);
  const [queueItems, setQueueItems] = useState<QueueItem[]>([]);
  const [queueTotal, setQueueTotal] = useState(0);
  const [queueReady, setQueueReady] = useState(false);
  const [assetId, setAssetId] = useState('');
  const [utcSlot, setUtcSlot] = useState('');
  const [destinationLabel, setDestinationLabel] = useState('');
  const [destinationChoice, setDestinationChoice] = useState<Record<string, string>>({});
  const [busyId, setBusyId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [feedback, setFeedback] = useState('');
  const [error, setError] = useState('');

  const eligibleAssets = useMemo(() => assets.filter((asset) => asset.eligible), [assets]);
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
        throw new Error(destinationBody?.error?.message ?? 'No se pudieron leer los destinos manuales.');
      }
      if (!queueResponse.ok) {
        throw new Error(queueBody?.error?.message ?? 'No se pudo abrir la cola manual.');
      }

      setDrafts(scheduleBody.data.drafts as Draft[]);
      setTotal(scheduleBody.data.total as number);
      setHandoffReady(scheduleBody.data.manual_handoff_ready === true);
      setDestinationReady(destinationBody.data.ready === true);
      setDestinations(destinationBody.data.destinations as Destination[]);
      setQueueReady(queueBody.data.ready === true);
      setQueueItems(queueBody.data.items as QueueItem[]);
      setQueueTotal(queueBody.data.total as number);
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

  async function createDestination(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const label = destinationLabel.trim();
    if (!canManualHandoff || !manualDestinationCsrf || !destinationReady
      || label.length < 2 || busyId !== null) return;

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
        ? 'Destino manual creado. Sigue siendo una referencia interna.'
        : 'Ese destino manual ya existía.');
      setDestinationLabel('');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo crear el destino manual.');
    } finally {
      setBusyId(null);
    }
  }

  async function toggleDestination(destination: Destination) {
    if (!canManualHandoff || !manualDestinationCsrf || !destinationReady || busyId !== null) return;
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
        throw new Error(body?.error?.message ?? 'No se pudo cambiar el destino manual.');
      }
      setFeedback(destination.active
        ? 'Destino manual desactivado para nuevas preparaciones.'
        : 'Destino manual reactivado.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo cambiar el destino manual.');
    } finally {
      setBusyId(null);
    }
  }

  async function updateManualHandoff(
    draft: Draft,
    action: 'prepare' | 'complete' | 'fail',
  ) {
    if (!canManualHandoff || !manualHandoffCsrf || !handoffReady || !destinationReady
      || busyId !== null || draft.status !== 'draft') return;

    const selectedDestination = destinationChoice[draft.id] ?? '';
    if (action === 'prepare'
      && !activeDestinations.some((destination) => destination.id === selectedDestination)) return;

    setBusyId('handoff-' + draft.id);
    setFeedback('');
    setError('');
    try {
      const payload = action === 'prepare'
        ? { action, destination_id: selectedDestination }
        : { action };
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
          body: JSON.stringify(payload),
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
        prepare: 'Salida manual preparada para el destino elegido. Aún no se ha publicado nada.',
        complete: 'Salida manual registrada como realizada por una persona.',
        fail: 'Fallo manual registrado. Puedes preparar un nuevo intento.',
      };
      setFeedback(labels[action]);
      setDestinationChoice((current) => ({ ...current, [draft.id]: '' }));
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
        <h3 id="schedule-draft-title">Borradores y salida manual</h3>
        <p>
          Reserva recursos en slots internos y asigna un destino humano explícito.
          Nada de este panel conecta redes ni publica automáticamente.
        </p>
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
      {(!canEdit || !csrf) &&
        <p>Tu rol puede consultar los borradores, pero no crearlos ni cancelarlos.</p>}

      <div className="manual-destination-panel" aria-labelledby="manual-destination-title">
        <div>
          <span className="admin-kicker">S4 · DESTINOS INTERNOS</span>
          <h4 id="manual-destination-title">Destinos manuales</h4>
          <p>
            Son etiquetas de trabajo para una persona. No guardan credenciales,
            IDs remotos ni conexiones con plataformas externas.
          </p>
        </div>
        {!destinationReady && <p>El catálogo de destinos aún requiere la migración S4.</p>}
        {destinationReady && canManualHandoff && manualDestinationCsrf &&
          <form className="manual-destination-form" onSubmit={createDestination}>
            <label htmlFor="manual-destination-label">Nuevo destino</label>
            <input id="manual-destination-label" value={destinationLabel}
              minLength={2} maxLength={80} placeholder="Ej. Canal manual principal"
              onChange={(event) => setDestinationLabel(event.target.value)} />
            <button type="submit"
              disabled={destinationLabel.trim().length < 2 || busyId !== null}>
              {busyId === 'destination-create' ? 'Creando…' : 'Crear destino'}
            </button>
          </form>}
        {destinationReady &&
          <ul className="manual-destination-list">
            {destinations.length === 0 && <li>No hay destinos manuales definidos.</li>}
            {destinations.map((destination) =>
              <li key={destination.id}>
                <div>
                  <strong>{destination.label}</strong>
                  <small>{destination.active ? 'Activo para nuevas preparaciones' : 'Desactivado'}</small>
                </div>
                {canManualHandoff && manualDestinationCsrf &&
                  <button type="button" disabled={busyId !== null}
                    onClick={() => void toggleDestination(destination)}>
                    {busyId === 'destination-' + destination.id
                      ? 'Guardando…'
                      : destination.active ? 'Desactivar' : 'Reactivar'}
                  </button>}
              </li>)}
          </ul>}
      </div>

      {feedback && <p className="weekly-feedback" role="status">{feedback}</p>}
      {error && <p className="weekly-error" role="alert">{error}</p>}

      {loading
        ? <p role="status">Cargando agenda y cola…</p>
        : <>
            <div className="schedule-draft-history">
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
                      {draft.manual_destination &&
                        <small>Destino: {draft.manual_destination.label}</small>}
                    </div>
                    <div className="schedule-draft-actions">
                      {canManualHandoff && manualHandoffCsrf && destinationReady
                        && draft.status === 'draft' &&
                        <>
                          {(draft.manual_handoff_status === 'none' || draft.manual_handoff_status === 'failed') &&
                            <>
                              <label className="sr-only" htmlFor={'manual-destination-' + draft.id}>
                                {'Destino manual para ' + draft.asset_name}
                              </label>
                              <select id={'manual-destination-' + draft.id}
                                value={destinationChoice[draft.id] ?? ''}
                                onChange={(event) => setDestinationChoice((current) => ({
                                  ...current,
                                  [draft.id]: event.target.value,
                                }))}>
                                <option value="">Selecciona destino</option>
                                {activeDestinations.map((destination) =>
                                  <option key={destination.id} value={destination.id}>
                                    {destination.label}
                                  </option>)}
                              </select>
                              <button type="button"
                                disabled={busyId !== null || !(destinationChoice[draft.id] ?? '')}
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
            </div>

            <div className="manual-handoff-queue" aria-labelledby="manual-handoff-queue-title">
              <div>
                <span className="admin-kicker">S4 · COLA HUMANA</span>
                <h4 id="manual-handoff-queue-title">Trabajo manual pendiente · {queueTotal}</h4>
              </div>
              {!queueReady && <p>La cola requiere la migración de destinos manuales.</p>}
              {queueReady && queueItems.length === 0 &&
                <p>No hay handoffs preparados ni fallidos pendientes.</p>}
              {queueReady && queueItems.length > 0 &&
                <ul>
                  {queueItems.map((item) =>
                    <li key={item.draft_id}>
                      <div>
                        <strong>{item.asset_name}</strong>
                        <span>{item.local_date} · {item.local_time} ({item.timezone})</span>
                        <small>{item.destination?.label ?? 'Destino histórico no disponible'}</small>
                      </div>
                      <div className="manual-queue-state">
                        <strong>{item.due ? 'Ya corresponde atender' : 'Programado'}</strong>
                        <small>{item.status === 'prepared' ? 'Preparado' : 'Fallo registrado'}</small>
                        {item.destination && !item.destination.active &&
                          <small>Destino desactivado para nuevas preparaciones</small>}
                      </div>
                    </li>)}
                </ul>}
            </div>
          </>}

      <p className="weekly-safety">
        <strong>Sin publicación automática.</strong> Los destinos y la cola son referencias internas.
        Preparar, completar o fallar un handoff no llama proveedores, no mueve contenido fuera de GrindFlow
        y no constituye evidencia de una publicación externa.
      </p>
    </section>
  );
}
