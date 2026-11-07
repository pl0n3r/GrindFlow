import { useState } from 'react';

type Format = 'vertical' | 'square';
type Provider = 'facebook_page' | 'instagram_reels';
type Props = {
  provider: Provider;
  destinationLabel: string;
  role: 'independent' | 'studio';
  /** UI hint only; a backend save handler must authenticate RBAC and tenant. */
  canManagePolicy: boolean;
  eligibleFormats: Format[];
  initialOrder?: Format[];
  onSavePolicy?: (order: Format[]) => Promise<void>;
};

const formats: Record<Format, string> = {
  vertical: 'Vertical (9:16)',
  square: 'Cuadrada (1:1)',
};
const defaults: Record<Provider, Format[]> = {
  facebook_page: ['vertical', 'square'],
  instagram_reels: ['vertical'],
};
function valid(provider: Provider, list: unknown): list is Format[] {
  return Array.isArray(list) && list.length <= 2 &&
    new Set(list).size === list.length &&
    list.every((item) => defaults[provider].includes(item as Format));
}

/** Standalone preview; never calls providers or claims publication. */
export function DistributionFallbackPanel({
  provider, destinationLabel, role, canManagePolicy, eligibleFormats,
  initialOrder, onSavePolicy,
}: Props) {
  const choices = defaults[provider];
  const [order, setOrder] = useState<Format[]>(
    valid(provider, initialOrder) ? initialOrder : [...choices],
  );
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const editable = role === 'studio' && canManagePolicy && onSavePolicy !== undefined;
  const selected = order.find((item) => eligibleFormats.includes(item) && choices.includes(item));

  function toggle(item: Format, checked: boolean) {
    setMessage('');
    setOrder((previous) => checked ? [...previous, item] : previous.filter((value) => value !== item));
  }
  function move(item: Format, by: number) {
    setMessage('');
    setOrder((previous) => {
      const i = previous.indexOf(item), j = i + by;
      if (i < 0 || j < 0 || j >= previous.length) return previous;
      const next = [...previous];
      [next[i], next[j]] = [next[j], next[i]];
      return next;
    });
  }
  async function save() {
    if (!editable || saving || !valid(provider, order) || !onSavePolicy) return;
    setSaving(true);
    setMessage('');
    try {
      await onSavePolicy([...order]);
      setMessage('Solicitud de política enviada. El servidor debe verificar permisos y organización.');
    } catch {
      setMessage('No se guardó la política. Ningún contenido se envió al proveedor.');
    } finally {
      setSaving(false);
    }
  }

  return <section aria-labelledby="fallback-title" style={{ maxWidth: '42rem', minWidth: 0 }}>
    <h2 id="fallback-title">Alternativas de distribución</h2>
    <p><strong>{destinationLabel}</strong> · {provider === 'facebook_page' ? 'Facebook' : 'Instagram Reels'}</p>
    <p>{role === 'studio'
      ? 'Studio: orden recomendado con excepciones autorizadas por cuenta o creador.'
      : 'Creador independiente: primero la alternativa compatible recomendada.'}</p>
    <p role="status">Vista previa interna. No publica, no transforma archivos ni confirma conexiones externas.</p>
    <fieldset disabled={!editable || saving}>
      <legend>Orden de formatos admitidos</legend>
      {choices.map((item) => {
        const index = order.indexOf(item);
        return <div key={item} style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', padding: '0.5rem 0' }}>
          <label>
            <input type="checkbox" checked={index >= 0}
              onChange={(event) => toggle(item, event.target.checked)} />
            {formats[item]}
          </label>
          {index >= 0 && <span>Prioridad {index + 1}</span>}
          <button type="button" disabled={index <= 0} onClick={() => move(item, -1)}
            aria-label={'Subir ' + formats[item]}>Subir</button>
          <button type="button" disabled={index < 0 || index >= order.length - 1}
            onClick={() => move(item, 1)} aria-label={'Bajar ' + formats[item]}>Bajar</button>
        </div>;
      })}
    </fieldset>
    <p role="status" aria-live="polite">
      {selected
        ? <>Primera variante posible: <strong>{formats[selected]}</strong>. El backend debe volver a validar formato, titularidad y estado externo.</>
        : <strong>Revisión humana requerida. Sin alternativa compatible; no se enviará al proveedor.</strong>}
    </p>
    {editable
      ? <div>
        <button type="button" disabled={saving} onClick={() => void save()}>
          {saving ? 'Guardando…' : 'Guardar política'}
        </button>
        <button type="button" disabled={saving} onClick={() => { setOrder([...choices]); setMessage(''); }}>
          Restaurar recomendación
        </button>
      </div>
      : <p>Solo lectura: la edición requiere permiso Studio y guardado autenticado.</p>}
    {message && <p role="status" aria-live="polite">{message}</p>}
  </section>;
}
