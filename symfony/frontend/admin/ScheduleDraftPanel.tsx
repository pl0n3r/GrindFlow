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

type FanoutDestination = { provider: 'facebook_page'; destination_id: string };

type FanoutDelivery = {
  id: string;
  provider: string;
  destination_id: string;
  status: 'pending' | 'in_flight' | 'published' | 'rate_limited'
    | 'authentication_failed' | 'rejected' | 'ambiguous';
  external_publication_id: string | null;
  updated_at: string | null;
  automatic_retry: false;
};

type FanoutGroup = {
  id: string;
  draft_id: string;
  total: number;
  sent: number;
  pending: number;
  failed: number;
  deliveries: FanoutDelivery[];
  automatic_retry: false;
};

type Draft = {
  id: string;
  asset_id: string;
  asset_name: string;
  fanout_groups?: FanoutGroup[];
  scheduled_at_utc: string;
  timezone: string;
  local_date: string;
  local_time: string;
  status: 'draft' | 'cancelled';
  caption: string | null;
  delivery_provider: string | null;
  delivery_destination_id: string | null;
  delivery_locked_at: string | null;
  external_delivery_status:
    | 'in_flight'
    | 'published'
    | 'rate_limited'
    | 'authentication_failed'
    | 'rejected'
    | 'ambiguous'
    | null;
  external_publication_id: string | null;
  published_at: string | null;
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
  const [externalDeliveryReady, setExternalDeliveryReady] = useState(false);
  const [fanoutReady, setFanoutReady] = useState(false);
  const [fanoutDestinations, setFanoutDestinations] = useState<FanoutDestination[]>([]);
  const [fanoutDestinationByDraft, setFanoutDestinationByDraft] = useState<Record<string, string[]>>({});
  const [fanoutRequestByDraft, setFanoutRequestByDraft] = useState<Record<string, string>>({});
  const [externalDestinationId, setExternalDestinationId] = useState<string | null>(null);
  const [captionByDraft, setCaptionByDraft] = useState<Record<string, string>>({});
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
  const [destinationLoadError, setDestinationLoadError] = useState('');
  const [queueLoadError, setQueueLoadError] = useState('');

  const eligibleAssets = assets.filter((asset) => asset.eligible);
  const activeDestinations = useMemo(
    () => destinations.filter((destination) => destination.active),
    [destinations],
  );

  async function load(signal?: AbortSignal) {
    setError('');
    setDestinationLoadError('');
    setQueueLoadError('');

    try {
      const [scheduleResult, destinationResult, queueResult] = await Promise.allSettled([
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

      if (signal?.aborted) return;
      if (scheduleResult.status !== 'fulfilled') {
        throw scheduleResult.reason;
      }

      const scheduleBody = await scheduleResult.value.json();
      if (!scheduleResult.value.ok) {
        throw new Error(scheduleBody?.error?.message ?? 'No se pudo abrir la agenda.');
      }

      const nextDrafts = scheduleBody.data.drafts as Draft[];
      setDrafts(nextDrafts);
      setTotal(scheduleBody.data.total as number);
      setHandoffReady(scheduleBody.data.manual_handoff_ready === true);
      setExternalDeliveryReady(scheduleBody.data.external_delivery_ready === true);
      setFanoutReady(scheduleBody.data.fanout_ready === true);
      setFanoutDestinations(
        Array.isArray(scheduleBody.data.fanout_destinations)
          ? (scheduleBody.data.fanout_destinations as FanoutDestination[]) : [],
      );
      setExternalDestinationId(
        typeof scheduleBody.data.external_destination_id === 'string'
          ? scheduleBody.data.external_destination_id
          : null,
      );
      setCaptionByDraft((current) => {
        const next = { ...current };
        for (const draft of nextDrafts) {
          if (!(draft.id in next)) {
            next[draft.id] = draft.caption ?? '';
          }
        }
        return next;
      });
      setDestinationByDraft((current) => {
        const next = { ...current };
        for (const draft of nextDrafts) {
          if (!next[draft.id] && draft.manual_destination?.id) {
            next[draft.id] = draft.manual_destination.id;
          }
        }
        return next;
      });

      if (destinationResult.status === 'fulfilled') {
        try {
          const destinationBody = await destinationResult.value.json();
          if (!destinationResult.value.ok) {
            throw new Error(
              destinationBody?.error?.message ?? 'No se pudieron cargar los destinos manuales.',
            );
          }
          setDestinations(destinationBody.data.destinations as ManualDestination[]);
          setDestinationReady(destinationBody.data.ready === true);
        } catch (caught) {
          setDestinationReady(false);
          setDestinationLoadError(
            caught instanceof Error
              ? caught.message
              : 'No se pudieron cargar los destinos manuales.',
          );
        }
      } else {
        setDestinationReady(false);
        setDestinationLoadError('No se pudieron cargar los destinos manuales.');
      }

      if (queueResult.status === 'fulfilled') {
        try {
          const queueBody = await queueResult.value.json();
          if (!queueResult.value.ok) {
            throw new Error(queueBody?.error?.message ?? 'No se pudo cargar la cola manual.');
          }
          setQueue(queueBody.data.items as QueueItem[]);
          setQueueTotal(queueBody.data.total as number);
          setQueueReady(queueBody.data.ready === true);
        } catch (caught) {
          setQueueReady(false);
          setQueueLoadError(
            caught instanceof Error ? caught.message : 'No se pudo cargar la cola manual.',
          );
        }
      } else {
        setQueueReady(false);
        setQueueLoadError('No se pudo cargar la cola manual.');
      }
    } catch (caught) {
      if (signal?.aborted || (caught instanceof DOMException && caught.name === 'AbortError')) return;
      setError(caught instanceof Error ? caught.message : 'No se pudo abrir la agenda.');
    } finally {
      if (!signal?.aborted) setLoading(false);
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
      if (body?.data?.provider_calls !== false) {
        throw new Error('No se confirmó el límite de destino interno.');
      }
      setFeedback(body.data.changed
        ? 'Destino manual creado. Sigue siendo una referencia interna.'
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
        ? 'Destino manual desactivado para nuevas preparaciones.'
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


  async function saveDeliveryIntent(draft: Draft) {
    if (!canEdit || !csrf || (!externalDeliveryReady && !(fanoutReady && fanoutDestinations.length > 0))
      || busyId !== null || draft.status !== 'draft' || draft.delivery_locked_at !== null) return;
    const caption = (captionByDraft[draft.id] ?? draft.caption ?? '').trim();
    if (caption.length < 1 || caption.length > 5000) {
      setError('El caption debe contener entre 1 y 5000 caracteres.');
      return;
    }

    setBusyId('delivery-intent-' + draft.id);
    setFeedback('');
    setError('');
    try {
      const response = await fetch(
        '/api/admin/schedules/' + encodeURIComponent(draft.id) + '/delivery-intent',
        {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
          },
          body: JSON.stringify({ caption }),
        },
      );
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo guardar la intención de entrega.');
      }
      if (body?.data?.publishes !== false || body?.data?.provider_calls !== false) {
        throw new Error('No se confirmó el límite de preparación segura.');
      }
      setFeedback(body.data.changed
        ? 'Caption e intención guardados. Aún no se ha publicado nada.'
        : 'La intención de entrega ya estaba guardada.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error
        ? caught.message
        : 'No se pudo guardar la intención de entrega.');
    } finally {
      setBusyId(null);
    }
  }

  async function publishFacebook(draft: Draft) {
    if (!canEdit || !csrf || !externalDeliveryReady || busyId !== null
      || draft.status !== 'draft' || draft.external_delivery_status === 'ambiguous'
      || draft.external_delivery_status === 'authentication_failed'
      || draft.external_delivery_status === 'rejected'
      || draft.external_delivery_status === 'published') return;

    setBusyId('publish-' + draft.id);
    setFeedback('');
    setError('');
    try {
      const response = await fetch(
        '/api/admin/schedules/' + encodeURIComponent(draft.id) + '/publish-facebook',
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
        },
      );
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo completar la entrega externa.');
      }
      if (body?.data?.status !== 'published' || body?.data?.automatic_retry !== false) {
        throw new Error('No se confirmó una publicación externa segura.');
      }
      setFeedback(
        'Facebook confirmó la publicación'
        + (body.data.external_publication_id ? ' · ' + body.data.external_publication_id : '')
        + '.',
      );
      await load();
    } catch (caught) {
      setError(caught instanceof Error
        ? caught.message
        : 'No se pudo completar la entrega externa.');
      await load();
    } finally {
      setBusyId(null);
    }
  }


  async function prepareFanout(draft: Draft) {
    const chosen = fanoutDestinations.filter((destination) =>
      (fanoutDestinationByDraft[draft.id] ?? [])
        .includes(destination.provider + ':' + destination.destination_id),
    );
    if (!canEdit || !csrf || !fanoutReady || chosen.length === 0
      || !draft.caption || busyId !== null || draft.status !== 'draft'
      || draft.delivery_locked_at !== null) return;

    const requestKey = fanoutRequestByDraft[draft.id] || crypto.randomUUID();
    setFanoutRequestByDraft((previous) => ({ ...previous, [draft.id]: requestKey }));
    setBusyId('fanout-prepare-' + draft.id);
    setError('');
    setFeedback('');
    try {
      const response = await fetch(
        '/api/admin/schedules/' + encodeURIComponent(draft.id) + '/fanout',
        {
          method: 'POST', credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
          },
          body: JSON.stringify({
            request_key: requestKey,
            destinations: chosen,
          }),
        },
      );
      const result = await response.json();
      if (!response.ok) throw new Error(result?.error?.message ?? 'No se pudo preparar el grupo.');
      if (result?.data?.publishes !== false || result?.data?.provider_calls !== false
        || result?.data?.group?.automatic_retry !== false) {
        throw new Error('Falta confirmación de preparación sin publicación.');
      }
      setFanoutRequestByDraft((previous) => ({ ...previous, [draft.id]: '' }));
      setFeedback('Grupo de distribución guardado. Ningún destino se ha publicado.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo preparar el grupo.');
    } finally {
      setBusyId(null);
    }
  }

  async function deliverFanout(draft: Draft, group: FanoutGroup, delivery: FanoutDelivery) {
    if (!canEdit || !csrf || busyId !== null
      || !['pending', 'rate_limited'].includes(delivery.status)) return;
    setBusyId('fanout-deliver-' + delivery.id);
    setError('');
    setFeedback('');
    try {
      const response = await fetch(
        '/api/admin/schedules/' + encodeURIComponent(draft.id)
        + '/fanout/' + encodeURIComponent(group.id)
        + '/deliver/' + encodeURIComponent(delivery.id),
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
        },
      );
      const result = await response.json();
      if (!response.ok) throw new Error(result?.error?.message ?? 'No se pudo entregar ese destino.');
      if (result?.data?.automatic_retry !== false || result?.data?.group?.automatic_retry !== false) {
        throw new Error('La respuesta no confirmó el modo sin reintentos automáticos.');
      }
      setFeedback('Destino procesado por el proveedor; verifica su recibo individual.');
      await load();
    } catch (caught) {
      const deliveryError = caught instanceof Error ? caught.message : 'No se pudo entregar ese destino.';
      await load();
      setError((reloadError) => reloadError
        ? deliveryError + ' · Además, falló la recarga: ' + reloadError
        : deliveryError);
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
        prepare: 'Salida manual preparada para el destino elegido. Aún no se ha publicado nada.',
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
        <h3 id="schedule-draft-title">Borradores y salida manual</h3>
        <p>Reserva un recurso revisado en un slot semanal. La entrega externa, cuando esté configurada, exige una acción explícita y conserva evidencia separada.</p>
      </div>

      {canManualHandoff && manualDestinationCsrf && destinationReady &&
        <section className="manual-destination-panel" aria-labelledby="manual-destination-title">
          <div>
            <h4 id="manual-destination-title">Destinos manuales</h4>
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
                      <small>{destination.active ? 'Activo para nuevas preparaciones' : 'Desactivado'}</small>
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

      {destinationLoadError &&
        <p className="weekly-warning" role="status">
          Agenda cargada parcialmente: {destinationLoadError} Los borradores siguen disponibles.
        </p>}
      {!destinationLoadError && canManualHandoff && manualDestinationCsrf && !destinationReady &&
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
                    {draft.delivery_destination_id &&
                      <small>Facebook Page: {draft.delivery_destination_id}</small>}
                    {draft.delivery_locked_at &&
                      <small>Intención externa bloqueada antes del envío</small>}
                    {draft.external_delivery_status &&
                      <small className={'external-delivery-status ' + draft.external_delivery_status}>
                        {draft.external_delivery_status === 'in_flight' && 'Entrega externa en curso'}
                        {draft.external_delivery_status === 'published' && 'Publicada en Facebook'}
                        {draft.external_delivery_status === 'rate_limited' && 'Facebook limitó temporalmente la entrega'}
                        {draft.external_delivery_status === 'authentication_failed' && 'Facebook requiere revisar autenticación'}
                        {draft.external_delivery_status === 'rejected' && 'Facebook rechazó la entrega'}
                        {draft.external_delivery_status === 'ambiguous' && 'Resultado externo incierto · no reintentar a ciegas'}
                      </small>}
                    {draft.external_delivery_status === 'published' && draft.external_publication_id &&
                      <small>ID externo: {draft.external_publication_id}</small>}
                    {draft.published_at && <small>Confirmada: {draft.published_at}</small>}

                    {(draft.fanout_groups ?? []).map((group) =>
                      <section key={group.id} aria-label={'Grupo de distribución ' + group.id}>
                        <strong>Grupo de distribución</strong>
                        <small aria-live="polite">
                          {group.total} destinos · {group.sent} enviados · {group.pending} pendientes
                          · {group.failed} fallidos
                        </small>
                        <ul>
                          {group.deliveries.map((delivery) =>
                            <li key={delivery.id}>
                              <span>{delivery.provider} · cuenta {delivery.destination_id}</span>
                              <small>
                                {delivery.status === 'pending' && 'Pendiente, sin publicar'}
                                {delivery.status === 'in_flight' && 'En curso o resultado incierto: no reintentar'}
                                {delivery.status === 'published' && 'Enviado, no reenviar'}
                                {delivery.status === 'rate_limited' && 'Falló: límite temporal (reintento manual)'}
                                {delivery.status === 'authentication_failed' && 'Falló: cuenta revocada o vencida'}
                                {delivery.status === 'rejected' && 'Falló: rechazo terminal'}
                                {delivery.status === 'ambiguous' && 'Resultado incierto: prohibido reintentar'}
                              </small>
                              {delivery.status === 'published' && delivery.external_publication_id &&
                                <small>Recibo externo: {delivery.external_publication_id}</small>}
                              {canEdit && csrf && draft.status === 'draft'
                                && ['pending', 'rate_limited'].includes(delivery.status) &&
                                <button type="button" disabled={busyId !== null}
                                  onClick={() => void deliverFanout(draft, group, delivery)}>
                                  {busyId === 'fanout-deliver-' + delivery.id
                                    ? 'Procesando…' : 'Entregar solo este destino'}
                                </button>}
                            </li>)}
                        </ul>
                      </section>)}
                    {draft.manual_destination &&
                      <small>Destino: {draft.manual_destination.label}</small>}
                    {handoffReady &&
                      <small className={'manual-handoff-status ' + draft.manual_handoff_status}>
                        {draft.manual_handoff_status === 'prepared' && 'Salida manual preparada'}
                        {draft.manual_handoff_status === 'completed' && 'Salida manual registrada como realizada'}
                        {draft.manual_handoff_status === 'failed' && 'Salida manual con fallo registrado'}
                        {draft.manual_handoff_status === 'none' && 'Salida manual sin preparar'}
                      </small>}
                  </div>

                  <div className="schedule-draft-actions">
                    {canEdit && csrf && externalDeliveryReady && draft.status === 'draft'
                      && (draft.fanout_groups?.length ?? 0) === 0
                      && !['prepared', 'completed'].includes(draft.manual_handoff_status) &&
                      <div className="external-delivery-actions">
                        {draft.delivery_locked_at === null
                          ? <>
                              <label>
                                Caption para Facebook
                                <textarea
                                  aria-label={'Caption para ' + draft.asset_name}
                                  maxLength={5000}
                                  value={captionByDraft[draft.id] ?? draft.caption ?? ''}
                                  onChange={(event) => setCaptionByDraft((current) => ({
                                    ...current,
                                    [draft.id]: event.target.value,
                                  }))}
                                />
                              </label>
                              <small>
                                Destino configurado: Facebook Page {externalDestinationId ?? 'no disponible'}
                              </small>
                              <button type="button"
                                disabled={busyId !== null
                                  || (captionByDraft[draft.id] ?? draft.caption ?? '').trim().length === 0}
                                onClick={() => void saveDeliveryIntent(draft)}>
                                {busyId === 'delivery-intent-' + draft.id
                                  ? 'Guardando…'
                                  : 'Guardar caption y destino'}
                              </button>
                              <button type="button"
                                disabled={busyId !== null
                                  || !draft.caption
                                  || externalDestinationId === null
                                  || draft.delivery_provider !== 'facebook_page'
                                  || draft.delivery_destination_id !== externalDestinationId
                                  || (captionByDraft[draft.id] ?? draft.caption ?? '').trim() !== draft.caption}
                                onClick={() => void publishFacebook(draft)}>
                                {busyId === 'publish-' + draft.id
                                  ? 'Publicando…'
                                  : 'Publicar foto en Facebook'}
                              </button>
                            </>
                          : <>
                              {draft.external_delivery_status === null &&
                                <button type="button" disabled={busyId !== null}
                                  onClick={() => void publishFacebook(draft)}>
                                  {busyId === 'publish-' + draft.id
                                    ? 'Publicando…'
                                    : 'Continuar entrega bloqueada'}
                                </button>}
                              {draft.external_delivery_status === 'rate_limited' &&
                                <button type="button" disabled={busyId !== null}
                                  onClick={() => void publishFacebook(draft)}>
                                  {busyId === 'publish-' + draft.id
                                    ? 'Reintentando…'
                                    : 'Reintentar manualmente'}
                                </button>}
                            </>}
                      </div>}

                    {canEdit && csrf && fanoutReady && fanoutDestinations.length > 0
                      && (draft.fanout_groups?.length ?? 0) === 0
                      && draft.status === 'draft' && draft.delivery_locked_at === null
                      && !['prepared', 'completed'].includes(draft.manual_handoff_status) &&
                      <fieldset className="external-delivery-actions">
                        <legend>Selecciona cuentas de distribución · sin conversión automática</legend>
                        {!externalDeliveryReady &&
                          <>
                            <label>
                              Caption para distribución
                              <textarea
                                aria-label={'Caption de distribución para ' + draft.asset_name}
                                maxLength={5000}
                                value={captionByDraft[draft.id] ?? draft.caption ?? ''}
                                onChange={(event) => setCaptionByDraft((current) => ({
                                  ...current, [draft.id]: event.target.value,
                                }))} />
                            </label>
                            <button type="button"
                              disabled={busyId !== null ||
                                (captionByDraft[draft.id] ?? draft.caption ?? '').trim().length === 0}
                              onClick={() => void saveDeliveryIntent(draft)}>
                              {busyId === 'delivery-intent-' + draft.id ? 'Guardando…' : 'Guardar caption'}
                            </button>
                          </>}
                        {fanoutDestinations.map((destination) => {
                          const identity = destination.provider + ':' + destination.destination_id;
                          return <label key={identity}>
                            <input type="checkbox"
                              checked={(fanoutDestinationByDraft[draft.id] ?? []).includes(identity)}
                              onChange={(event) => setFanoutDestinationByDraft((current) => {
                                const prior = current[draft.id] ?? [];
                                return { ...current, [draft.id]: event.target.checked
                                  ? [...prior.filter((item) => item !== identity), identity]
                                  : prior.filter((item) => item !== identity) };
                              })} />
                            Facebook Page · {destination.destination_id}
                          </label>;
                        })}
                        <small>
                          Cada destino mantiene una clave y un recibo independientes. Preparar
                          no publica; entregar después exige verificación actual de la cuenta.
                        </small>
                        <button type="button"
                          disabled={busyId !== null || (fanoutDestinationByDraft[draft.id] ?? []).length === 0}
                          onClick={() => void prepareFanout(draft)}>
                          {busyId === 'fanout-prepare-' + draft.id
                            ? 'Preparando…' : 'Preparar grupo sin publicar'}
                        </button>
                      </fieldset>}
                    {canEdit && csrf && !fanoutReady && draft.status === 'draft'
                      && <small>Grupos de distribución no disponibles hasta aplicar la migración.</small>}
                    {canManualHandoff && manualHandoffCsrf && handoffReady && draft.status === 'draft'
                      && draft.delivery_locked_at === null &&
                      <>
                        {(draft.manual_handoff_status === 'none' || draft.manual_handoff_status === 'failed') &&
                          <>
                            <label className="manual-destination-choice">
                              Destino manual
                              <select aria-label={'Destino manual para ' + draft.asset_name}
                                value={destinationByDraft[draft.id] ?? draft.manual_destination?.id ?? ''}
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
                      && draft.delivery_locked_at === null
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

      {!loading && queueLoadError &&
        <p className="weekly-warning" role="status">
          Agenda cargada parcialmente: {queueLoadError} El historial de borradores sigue disponible.
        </p>}

      {!loading && queueReady &&
        <section className="manual-handoff-queue" aria-labelledby="manual-handoff-queue-title">
          <div>
            <h4 id="manual-handoff-queue-title">Trabajo manual pendiente · {queueTotal}</h4>
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
        <strong>Destinos y cola manual: solo organización interna.</strong>
        La salida manual no llama a proveedores ni acredita una publicación externa.
        La acción «Entregar solo este destino» sí puede llamar al proveedor y publicar contenido.
      </p>
    </section>
  );
}
