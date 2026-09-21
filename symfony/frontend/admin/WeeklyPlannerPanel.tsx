import { useEffect, useState, type FormEvent } from 'react';

type WeeklyRule = {
  timezone: string;
  weekdays: string[];
  local_time: string;
  max_per_day: number;
  mode: 'review_only';
  updated_at: string;
};

type PreviewAsset = {
  id: string;
  name: string;
  mime_type: string;
  usage_scope: string;
  eligible: false;
  blocking_reasons: string[];
};

type Preview = {
  rule: WeeklyRule | null;
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

export function WeeklyPlannerPanel({ canEdit, csrf }: Props) {
  const [rule, setRule] = useState<WeeklyRule | null>(null);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [timezone, setTimezone] = useState(browserTimezone());
  const [weekdays, setWeekdays] = useState<string[]>(['mon', 'tue', 'wed', 'thu', 'fri']);
  const [localTime, setLocalTime] = useState('10:00');
  const [maxPerDay, setMaxPerDay] = useState(1);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
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

        {!loading && preview && preview.assets.length === 0 &&
          <p>No hay recursos activos en el Vault para previsualizar.</p>}

        {!loading && preview && preview.assets.length > 0 &&
          <ul className="weekly-assets">
            {preview.assets.map((asset) =>
              <li key={asset.id}>
                <div>
                  <strong>{asset.name}</strong>
                  <small>{asset.mime_type} · {asset.usage_scope}</small>
                </div>
                <ul aria-label={'Bloqueos de ' + asset.name}>
                  {asset.blocking_reasons.map((reason) =>
                    <li key={reason}>{blockerLabels[reason] ?? reason}</li>
                  )}
                </ul>
              </li>
            )}
          </ul>}

        <p className="weekly-safety">
          <strong>Publicación bloqueada.</strong> La clasificación del Vault no equivale a autorización de distribución.
          El siguiente paso de S3 añadirá esa aprobación como contrato separado.
        </p>
      </div>
    </section>
  );
}
