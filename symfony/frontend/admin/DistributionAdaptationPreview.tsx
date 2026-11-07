import { useId, useState } from 'react';

type Provider = 'facebook_page' | 'instagram_reels';
type Format = 'vertical' | 'square';

export type NetworkAdaptationRow = {
  provider: Provider;
  destination_id: string;
  status: 'ready' | 'needs_review';
  requires_human_review: boolean;
  variant_id: string | null;
  format: Format | null;
  caption: string | null;
  caption_origin: 'original' | 'authorized_override' | null;
  policy_origin: 'recommended' | 'studio_override' | null;
  reason_code: string;
};

export type NetworkAdaptationPlan = {
  status: 'ready' | 'needs_review';
  matrix_version: string;
  reason_code?: string;
  requires_human_review: boolean;
  destinations: NetworkAdaptationRow[];
  provider_calls: false;
};

type Props = {
  plan: NetworkAdaptationPlan;
  role: 'independent' | 'studio';
  /** Hints for controls only: backend MUST independently verify role, tenant
   * ownership, destination and CSRF before accepting any override. */
  canEditCaption: boolean;
  onRequestCaptionOverride?: (
    provider: Provider, destinationId: string, caption: string,
  ) => Promise<void>;
};

const labels: Record<Provider, string> = {
  facebook_page: 'Facebook Page',
  instagram_reels: 'Instagram Reels',
};
const formats: Record<Format, string> = {
  vertical: 'Vertical',
  square: 'Cuadrada',
};
const reasons: Record<string, string> = {
  compatible_preview: 'Vista previa compatible; requiere validar de nuevo antes del envío.',
  no_compatible_variant: 'No hay variante admitida para este destino.',
  variant_catalog_unverifiable: 'No se pudo verificar la variante o su titularidad.',
  external_outcome_ambiguous: 'El estado externo es incierto; no repetir el envío.',
  external_delivery_not_ready: 'El destino no está listo para una entrega.',
  policy_not_authorized: 'La política de formatos no está autorizada.',
  policy_requires_review: 'La política requiere decisión humana.',
  caption_override_invalid: 'El texto propuesto es inválido.',
  caption_override_not_authorized: 'No hay permiso para modificar el texto.',
  caption_invalid_or_too_long: 'El texto supera el límite comprobado o no es válido.',
  destination_not_authorized: 'El destino no está autorizado para esta cuenta.',
  fallback_unavailable: 'No se pudo determinar una alternativa segura.',
  invalid_plan_context: 'El plan no puede verificarse; comprueba su contexto y sus destinos.',
  asset_not_authorized_or_archived: 'El recurso no está autorizado o ya no es elegible.',
};

function reasonText(code: string): string {
  return reasons[code] ?? 'Se requiere revisión humana antes de continuar.';
}

/** A preview of trusted server-side planning decisions. No provider calls,
 * file transformations, publication claims or raw private media paths. */
export function DistributionAdaptationPreview({
  plan, role, canEditCaption, onRequestCaptionOverride,
}: Props) {
  const rootId = useId();
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<Record<string, string>>({});
  const editable = canEditCaption && onRequestCaptionOverride !== undefined;

  function keyOf(row: NetworkAdaptationRow): string {
    return row.provider + ':' + row.destination_id;
  }

  async function requestOverride(row: NetworkAdaptationRow) {
    const key = keyOf(row);
    const caption = drafts[key] ?? row.caption ?? '';
    if (!editable || saving !== null || !onRequestCaptionOverride || caption.trim() === '') {
      return;
    }
    setSaving(key);
    setFeedback((prev) => ({ ...prev, [key]: '' }));
    try {
      await onRequestCaptionOverride(row.provider, row.destination_id, caption);
      setFeedback((prev) => ({
        ...prev,
        [key]: 'Cambio recibido. Espera una nueva validación del servidor antes de publicar.',
      }));
    } catch {
      setFeedback((prev) => ({
        ...prev, [key]: 'No se pudo guardar. El plan permanece sin autorización para publicar.',
      }));
    } finally {
      setSaving(null);
    }
  }

  return <section aria-labelledby={rootId + '-title'} style={{ maxWidth: '56rem' }}>
    <h2 id={rootId + '-title'}>Adaptación por destino</h2>
    <p>Capacidades verificadas: {plan.matrix_version}. Vista previa interna; ningún contenido se publica desde aquí.</p>
    <p role="status" aria-live="polite">
      {plan.status === 'ready' && !plan.requires_human_review && plan.destinations.length > 0
        ? 'Plan preparado para revisión. Cada entrega requiere autorización y comprobación al enviarse.'
        : 'Revisión humana requerida. No se enviará contenido incompatible ni se reintentará un estado ambiguo.'}
    </p>
    {plan.destinations.length === 0 &&
      <p>No hay variantes/destinos verificables: {reasonText(plan.reason_code ?? 'fallback_unavailable')}.</p>}
    <ul style={{ listStyle: 'none', padding: 0 }}>
      {plan.destinations.map((row) => {
        const key = keyOf(row);
        const id = rootId + '-' + row.provider + '-' + row.destination_id;
        const caption = drafts[key] ?? row.caption ?? '';
        const ready = row.status === 'ready' && !row.requires_human_review;
        return <li key={key} style={{ border: '1px solid currentColor', padding: '1rem', marginBlock: '0.75rem' }}>
          <h3>{labels[row.provider]} · destino {row.destination_id}</h3>
          <p role="status">
            <strong>{ready ? 'Variante seleccionada' : 'Necesita revisión'}</strong>
            {' · '}{reasonText(row.reason_code)}
          </p>
          <p>Formato: {ready && row.format ? formats[row.format] : 'Por decidir'}</p>
          <p>Variante: {ready && row.variant_id ? row.variant_id : 'No seleccionada'}</p>
          <p>Caption: {ready ? (row.caption_origin === 'authorized_override' ? 'Personalizado autorizado' : 'Original') : 'Pendiente'}</p>
          <label htmlFor={id + '-caption'}>Texto propuesto para este destino</label>
          <textarea
            id={id + '-caption'}
            rows={4}
            value={caption}
            readOnly={!editable}
            disabled={saving !== null}
            onChange={(event) => {
              const value = event.target.value;
              setDrafts((prev) => ({ ...prev, [key]: value }));
              setFeedback((prev) => ({ ...prev, [key]: '' }));
            }}
            aria-describedby={id + '-help'}
            style={{ display: 'block', width: '100%', maxWidth: '100%', boxSizing: 'border-box' }}
          />
          <p id={id + '-help'}>
            {role === 'studio'
              ? 'Studio: la edición depende de permisos de la cuenta y del creador.'
              : 'Independiente: únicamente el contenido propio autorizado.'}
            {' '}El servidor comprobará el límite sin truncar ni modificar el original.
          </p>
          {editable && <button type="button"
            disabled={saving !== null || caption.trim() === '' || caption === (row.caption ?? '')}
            onClick={() => void requestOverride(row)}>
            {saving === key ? 'Guardando…' : 'Solicitar cambio de caption'}
          </button>}
          {!editable && <p>Solo lectura: faltan permisos de edición o un guardado autenticado.</p>}
          {feedback[key] && <p role="status" aria-live="polite">{feedback[key]}</p>}
        </li>;
      })}
    </ul>
    <p>Las variantes originales permanecen intactas; la previsualización no transforma archivos ni invoca proveedores.</p>
  </section>;
}
