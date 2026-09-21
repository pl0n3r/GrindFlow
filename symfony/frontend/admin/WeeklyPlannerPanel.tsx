import { ScheduleDraftPanel } from './ScheduleDraftPanel';
import { useEffect, useState, type FormEvent } from 'react';

type WeeklyRule = {
  timezone: string;
  weekdays: string[];
  local_time: string;
  max_per_day: number;
  mode: 'review_only';
  updated_at: string;
};

type WeeklySlot = {
  local_date: string;
  weekday: string;
  local_time: string;
  timezone: string;
  capacity: number;
  scheduled_at_utc: string;
};

type PreviewAsset = {
  id: string;
  name: string;
  mime_type: string;
  usage_scope: string;
  content_review_approved: boolean;
  content_review_updated_at: string | null;
  distribution_authorized: boolean;
  distribution_authorization_updated_at: string | null;
  eligible: boolean;
  blocking_reasons: string[];
};

type Preview = {
  rule: WeeklyRule | null;
  slots?: WeeklySlot[];
  assets: PreviewAsset[];
  visible: number;
  total_active_assets: number;
  limit: number;
  can_publish: false;
  mode: 'review_only';
};

type Props = {
  canEdit: boolean;
  csrf: string | null;
  canReview: boolean;
  reviewCsrf: string | null;
  scheduleCsrf: string | null;
  canAuthorize: boolean;
  authorizationCsrf: string | null;
  canManualHandoff: boolean;
  manualHandoffCsrf: string | null;
};

const days = [
  ['mon', 'Lun'],
  ['tue', 'Mar'],
  ['wed', 'Mié'],
  ['thu', 'Jue'],
  ['fri', 'Vie'],
  ['sat', 'Sáb'],
  ['sun', 'Dom'],
] as const;

const blockerLabels: Record<string, string> = {
  weekly_rule_missing: 'Falta guardar una regla semanal',
  classification_missing: 'Recurso sin clasificar',
  internal_only: 'Marcado para uso interno',
  content_review_required: 'Requiere revisión de contenido',
  distribution_authorization_missing: 'Falta autorización explícita de distribución',
};

function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  } catch {
    return 'UTC';
  }
}

export function WeeklyPlannerPanel({
  canEdit, csrf, canReview, reviewCsrf, scheduleCsrf, canAuthorize, authorizationCsrf,
  canManualHandoff, manualHandoffCsrf,
}: Props) {
  const [rule, setRule] = useState<WeeklyRule | null>(null);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [timezone, setTimezone] = useState(browserTimezone());
  const [weekdays, setWeekdays] = useState<string[]>(['mon', 'tue', 'wed', 'thu', 'fri']);
  const [localTime, setLocalTime] = useState('10:00');
  const [maxPerDay, setMaxPerDay] = useState(1);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [reviewingId, setReviewingId] = useState<string | null>(null);
  const [authorizingId, setAuthorizingId] = useState<string | null>(null);
  const [feedback, setFeedback] = useState('');
  const [error, setError] = useState('');

  async function load(signal?: AbortSignal) {
    setLoading(true);
    setError('');
    try {
      const [ruleResponse, previewResponse] = await Promise.all([
        fetch('/api/admin/rules/weekly', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal,
        }),
        fetch('/api/admin/rules/weekly/preview', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal,
        }),
      ]);
      const [ruleBody, previewBody] = await Promise.all([ruleResponse.json(), previewResponse.json()]);
      if (!ruleResponse.ok) {
        throw new Error(ruleBody?.error?.message ?? 'No se pudo cargar la regla semanal.');
      }
      if (!previewResponse.ok) {
        throw new Error(previewBody?.error?.message ?? 'No se pudo preparar la vista previa.');
      }

      const nextRule = (ruleBody?.data?.rule ?? null) as WeeklyRule | null;
      const nextPreview = previewBody?.data as Preview;
      setRule(nextRule);
      setPreview(nextPreview);
      if (nextRule) {
        setTimezone(nextRule.timezone);
        setWeekdays(nextRule.weekdays);
        setLocalTime(nextRule.local_time);
        setMaxPerDay(nextRule.max_per_day);
      }
    } catch (caught) {
      if (caught instanceof DOMException && caught.name === 'AbortError') return;
      setError(caught instanceof Error ? caught.message : 'No se pudo preparar la programación.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const controller = new AbortController();
    void load(controller.signal);
    return () => controller.abort();
  }, []);

  function toggleDay(day: string) {
    setWeekdays((current) => current.includes(day)
      ? current.filter((value) => value !== day)
      : [...current, day]);
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canEdit || !csrf || saving) return;

    setSaving(true);
    setFeedback('');
    setError('');
    try {
      const response = await fetch('/api/admin/rules/weekly', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({
          timezone,
          weekdays,
          local_time: localTime,
          max_per_day: maxPerDay,
        }),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo guardar la regla semanal.');
      }
      setRule(body.data.rule as WeeklyRule);
      setFeedback('Regla semanal guardada. La vista previa continúa en modo revisión.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo guardar la regla semanal.');
    } finally {
      setSaving(false);
    }
  }

  async function setContentReview(asset: PreviewAsset, approved: boolean) {
    if (!canReview || !reviewCsrf || reviewingId !== null) return;

    setReviewingId(asset.id);
    setFeedback('');
    setError('');
    try {
      const response = await fetch('/api/admin/content-reviews/' + encodeURIComponent(asset.id), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': reviewCsrf,
        },
        body: JSON.stringify({ approved }),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo actualizar la revisión de contenido.');
      }

      setFeedback(approved
        ? 'Revisión humana aprobada. Aún falta cumplir los demás bloqueos.'
        : 'Aprobación de revisión revocada.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo actualizar la revisión de contenido.');
    } finally {
      setReviewingId(null);
    }
  }

  async function setDistributionAuthorization(asset: PreviewAsset, authorized: boolean) {
    if (!canAuthorize || !authorizationCsrf || authorizingId !== null) return;

    setAuthorizingId(asset.id);
    setFeedback('');
    setError('');
    try {
      const response = await fetch('/api/admin/distribution-authorizations/' + encodeURIComponent(asset.id), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': authorizationCsrf,
        },
        body: JSON.stringify({ authorized }),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(body?.error?.message ?? 'No se pudo actualizar la autorización de distribución.');
      }

      setFeedback(authorized
        ? 'Autorización interna de distribución registrada.'
        : 'Autorización interna de distribución revocada.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'No se pudo actualizar la autorización de distribución.');
    } finally {
      setAuthorizingId(null);
    }
  }

  return (
    <section id="programacion" className="admin-settings weekly-planner" aria-labelledby="weekly-planner-title">
      <span className="admin-kicker">S3 · PROGRAMACIÓN SEGURA</span>
      <div className="weekly-heading">
        <div>
          <h2 id="weekly-planner-title">Regla y vista previa semanal</h2>
          <p>Define cuándo quieres preparar contenido. Esta pantalla <strong>no publica</strong> ni crea entregas externas.</p>
        </div>
        <span className="weekly-mode">REVIEW ONLY</span>
      </div>

      {canEdit && csrf
        ? <form className="weekly-form" onSubmit={save}>
            <label htmlFor="weekly-timezone">Zona horaria IANA</label>
            <input id="weekly-timezone" value={timezone} required maxLength={64}
              onChange={(event) => setTimezone(event.target.value)} />

            <fieldset>
              <legend>Días activos</legend>
              <div className="weekly-days">
                {days.map(([value, label]) =>
                  <label key={value}>
                    <input type="checkbox" checked={weekdays.includes(value)}
                      onChange={() => toggleDay(value)} />
                    <span>{label}</span>
                  </label>
                )}
              </div>
            </fieldset>

            <div className="weekly-inline">
              <label>Hora local
                <input type="time" value={localTime} required
                  onChange={(event) => setLocalTime(event.target.value)} />
              </label>
              <label>Máximo por día
                <input type="number" min={1} max={12} value={maxPerDay} required
                  onChange={(event) => setMaxPerDay(Number(event.target.value))} />
              </label>
            </div>
            <button type="submit" disabled={saving || weekdays.length === 0}>
              {saving ? 'Guardando…' : rule ? 'Actualizar regla' : 'Guardar regla'}
            </button>
          </form>
        : <p className="weekly-readonly">Tu rol puede consultar la planificación, pero no modificar la regla semanal.</p>}

      {feedback && <p className="weekly-feedback" role="status">{feedback}</p>}
      {error && <p className="weekly-error" role="alert">{error}</p>}

      <div className="weekly-preview" aria-live="polite">
        <div className="weekly-preview-summary">
          <strong>Vista previa de elegibilidad</strong>
          {loading
            ? <span>Cargando…</span>
            : <span>{preview?.total_active_assets ?? 0} recursos activos · {preview?.visible ?? 0} mostrados</span>}
        </div>

        {!loading && preview && (preview.slots?.length ?? 0) > 0 &&
          <section className="weekly-slot-section" aria-labelledby="weekly-slot-title">
            <div className="weekly-slot-heading">
              <strong id="weekly-slot-title">Próximos slots</strong>
              <span>{preview.slots?.length} días configurados</span>
            </div>
            <ul className="weekly-slots">
              {preview.slots?.map((slot) =>
                <li key={slot.scheduled_at_utc}>
                  <div>
                    <strong>{slot.local_date} · {slot.local_time}</strong>
                    <small>{slot.timezone}</small>
                  </div>
                  <span>Capacidad {slot.capacity}/día</span>
                </li>
              )}
            </ul>
          </section>}

        {!loading && preview && preview.rule && (preview.slots?.length ?? 0) === 0 &&
          <p>No hay slots futuros para la regla semanal actual.</p>}

        {!loading && preview && preview.assets.length === 0 &&
          <p>No hay recursos activos en el Vault para previsualizar.</p>}

        {!loading && preview && preview.assets.length > 0 &&
          <ul className="weekly-assets">
            {preview.assets.map((asset) =>
              <li key={asset.id}>
                <div className="weekly-asset-summary">
                  <strong>{asset.name}</strong>
                  <small>{asset.mime_type} · {asset.usage_scope}</small>
                  {asset.usage_scope === 'needs_review' &&
                    <span className={'weekly-authorization ' + (asset.content_review_approved ? 'yes' : 'no')}>
                      {asset.content_review_approved
                        ? 'Revisión humana aprobada'
                        : 'Revisión humana pendiente'}
                    </span>}
                  {asset.usage_scope === 'needs_review' && canReview && reviewCsrf &&
                    <button type="button"
                      aria-label={(asset.content_review_approved
                        ? 'Revocar revisión de ' : 'Aprobar revisión de ') + asset.name}
                      disabled={reviewingId !== null || authorizingId !== null}
                      onClick={() => void setContentReview(asset, !asset.content_review_approved)}>
                      {reviewingId === asset.id
                        ? 'Guardando…'
                        : asset.content_review_approved
                          ? 'Revocar revisión'
                          : 'Aprobar revisión'}
                    </button>}
                  <span className={'weekly-authorization ' + (asset.distribution_authorized ? 'yes' : 'no')}>
                    {asset.distribution_authorized
                      ? 'Distribución autorizada internamente'
                      : 'Distribución sin autorizar'}
                  </span>
                  {canAuthorize && authorizationCsrf &&
                    <button type="button"
                      disabled={authorizingId !== null || reviewingId !== null}
                      onClick={() => void setDistributionAuthorization(asset, !asset.distribution_authorized)}>
                      {authorizingId === asset.id
                        ? 'Guardando…'
                        : asset.distribution_authorized
                          ? 'Revocar autorización'
                          : 'Autorizar distribución'}
                    </button>}
                </div>
                {asset.eligible &&
                  <strong className="weekly-ready">Listo para programar internamente</strong>}
                <ul aria-label={'Bloqueos de ' + asset.name}>
                  {asset.blocking_reasons.map((reason) =>
                    <li key={reason}>{blockerLabels[reason] ?? reason}</li>
                  )}
                </ul>
              </li>
            )}
          </ul>}

        {!loading && preview &&
          <ScheduleDraftPanel slots={preview.slots ?? []} assets={preview.assets}
            canEdit={canEdit} csrf={scheduleCsrf}
            canManualHandoff={canManualHandoff} manualHandoffCsrf={manualHandoffCsrf} />}

        <p className="weekly-safety">
          <strong>Publicación bloqueada.</strong> “Listo para programar” solo confirma los contratos internos S3.
          El bloque S4 puede persistir un borrador de agenda, pero ninguno de estos estados llama proveedores,
          distribuye contenido ni certifica derechos, consentimiento o aceptación de una plataforma.
        </p>
      </div>
    </section>
  );
}
