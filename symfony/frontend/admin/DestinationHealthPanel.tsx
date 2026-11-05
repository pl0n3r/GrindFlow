import { useCallback, useEffect, useState } from 'react';

type HealthState =
  | 'connected' | 'attention_required' | 'blocked'
  | 'temporarily_unavailable' | 'unknown';
type Destination = {
  provider: 'facebook_page' | 'instagram_reels';
  state: HealthState;
  reason_code: string;
  action: 'none' | 'reconnect' | 'review' | 'retry_later';
  can_enqueue: boolean;
  evidence_at: string | null;
};
type Snapshot = {
  scope: 'selected_organization';
  organization: { id: string; name: string };
  view: 'independent' | 'studio';
  destinations: Destination[];
  provider_calls: false;
  verified_provider_connection: false;
  read_only: true;
};
type LoadState =
  | { kind: 'loading' }
  | { kind: 'ready'; data: Snapshot }
  | { kind: 'error' };

const statusLabel: Record<HealthState, string> = {
  connected: 'Conectada',
  attention_required: 'Requiere atención',
  blocked: 'Bloqueada',
  temporarily_unavailable: 'Temporalmente no disponible',
  unknown: 'Sin verificación reciente',
};
const providerLabel: Record<Destination['provider'], string> = {
  facebook_page: 'Facebook Page',
  instagram_reels: 'Instagram Reels',
};
const reasonLabel: Record<string, string> = {
  recent_verified_authorization: 'Permisos y autorización comprobados recientemente.',
  destination_disconnected: 'Este destino está desconectado o deshabilitado.',
  token_revoked: 'La autorización puede estar revocada. Revisa la conexión.',
  token_expired: 'La autorización caducó. Es necesario reconectar.',
  permission_revoked: 'Revisa los permisos para publicar en esta red.',
  ambiguous_delivery: 'Entrega incierta: no reintentar automáticamente.',
  provider_temporarily_unavailable: 'El proveedor requiere un nuevo intento más tarde.',
  insufficient_verified_evidence: 'No hay evidencia actual suficiente para publicar de forma segura.',
  destination_not_available: 'El destino no está disponible para esta organización.',
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function isDestination(value: unknown): value is Destination {
  return isRecord(value) &&
    (value.provider === 'facebook_page' || value.provider === 'instagram_reels') &&
    typeof value.state === 'string' && Object.hasOwn(statusLabel, value.state) &&
    typeof value.reason_code === 'string' && Object.hasOwn(reasonLabel, value.reason_code) &&
    ['none', 'reconnect', 'review', 'retry_later'].includes(String(value.action)) &&
    typeof value.can_enqueue === 'boolean' &&
    (value.evidence_at === null || typeof value.evidence_at === 'string');
}
function parseSnapshot(value: unknown): Snapshot | null {
  if (!isRecord(value) || !isRecord(value.data)) return null;
  const data = value.data;
  if (data.scope !== 'selected_organization' || !isRecord(data.organization) ||
    typeof data.organization.id !== 'string' || typeof data.organization.name !== 'string' ||
    (data.view !== 'independent' && data.view !== 'studio') ||
    data.provider_calls !== false || data.verified_provider_connection !== false ||
    data.read_only !== true || !Array.isArray(data.destinations) ||
    !data.destinations.every(isDestination) || data.destinations.length > 10) return null;
  return data as Snapshot;
}

/**
 * This component is safe to mount in the existing admin shell; it does not
 * claim to reconnect a provider or invoke a remote API. Integration into the
 * shell needs an independent, coordinated claim on AdminApp.tsx.
 */
export function DestinationHealthPanel() {
  const [state, setState] = useState<LoadState>({ kind: 'loading' });
  const [reload, setReload] = useState(0);

  const refresh = useCallback(() => setReload((current) => current + 1), []);

  useEffect(() => {
    const controller = new AbortController();
    async function load() {
      setState({ kind: 'loading' });
      try {
        const response = await fetch('/api/admin/destination-health', {
          method: 'GET',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });
        if (!response.ok) throw new Error('not_available');
        const data = parseSnapshot(await response.json());
        if (!data) throw new Error('invalid_contract');
        if (!controller.signal.aborted) setState({ kind: 'ready', data });
      } catch {
        if (!controller.signal.aborted) setState({ kind: 'error' });
      }
    }
    void load();
    return () => controller.abort();
  }, [reload]);

  if (state.kind === 'loading') return <section role="status" aria-live="polite"><p>Consultando salud de destinos…</p></section>;
  if (state.kind === 'error') {
    return <section role="alert" aria-live="assertive">
      <h2>Salud de destinos no disponible</h2>
      <p>No se ha podido verificar el estado. No se asumirán conexiones activas.</p>
      <button type="button" onClick={refresh}>Reintentar consulta</button>
    </section>;
  }

  const { data } = state;
  return <section id="salud-destinos" aria-labelledby="destination-health-title" style={{ maxWidth: '100%', minWidth: 0 }}>
    <h2 id="destination-health-title">Salud de cuentas y destinos</h2>
    <p>{data.view === 'studio'
      ? 'Vista Studio: redes vinculadas a la organización seleccionada; no incluye otros creadores sin autorización.'
      : 'Revisa tus destinos antes de programar una publicación.'}</p>
    <p role="status" aria-live="polite">Solo diagnóstico interno: no se han consultado APIs externas ni se ha confirmado una conexión actual.</p>
    <button type="button" onClick={refresh} aria-label="Actualizar estado de destinos">Actualizar estado</button>
    {data.destinations.length === 0
      ? <p role="status">No hay destinos configurados para este espacio.</p>
      : <ul style={{ listStyle: 'none', padding: 0, display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 17rem), 1fr))', gap: '1rem' }}>
        {data.destinations.map((destination) => <li key={destination.provider} style={{ border: '1px solid currentColor', borderRadius: '0.7rem', padding: '1rem', minWidth: 0 }}>
          <h3>{providerLabel[destination.provider]}</h3>
          <p><strong>{statusLabel[destination.state]}</strong></p>
          <p>{reasonLabel[destination.reason_code]}</p>
          <p>{destination.can_enqueue ? 'Elegible con evidencia verificada.' : 'Envío automático no autorizado sin nueva verificación.'}</p>
          {destination.action !== 'none' && <a href="/admin#programacion">Revisar programación y conexión</a>}
        </li>)}
      </ul>}
  </section>;
}
