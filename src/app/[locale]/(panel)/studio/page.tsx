import { getTranslations, setRequestLocale } from 'next-intl/server';
import { Metric } from '@/components/ui/card';
import { createClient } from '@/lib/supabase/server';
import { formatNumber } from '@/lib/utils';

/**
 * Panel del estudio.
 *
 * Ninguna consulta filtra por organizacion: el RLS ya lo hace. Si una agencia
 * consultara `media_assets` sin condiciones y viera material de otra, no seria
 * un fallo de esta pagina sino de las politicas, y las pruebas de
 * `supabase/tests` lo detendrian antes de llegar aqui.
 */
export default async function StudioDashboard({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);

  const t = await getTranslations('dashboard');
  const tm = await getTranslations('metrics');
  const supabase = await createClient();

  const count = { count: 'exact', head: true } as const;

  const [assets, pendingSanitize, scheduled, models, deadJobs] = await Promise.all([
    supabase.from('media_assets').select('id', count),
    supabase.from('media_assets').select('id', count).eq('sanitized', false),
    supabase.from('schedules').select('id', count).eq('status', 'queued'),
    supabase.from('profiles').select('id', count),
    supabase.from('jobs').select('id', count).eq('status', 'dead'),
  ]);

  const n = (value: number | null) => formatNumber(value ?? 0, locale);

  return (
    <>
      <h1 className="mb-6 text-2xl font-semibold">{t('studioTitle')}</h1>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Metric label={tm('assets')} value={n(assets.count)} />
        <Metric
          label={tm('pendingSanitize')}
          value={n(pendingSanitize.count)}
          tone={(pendingSanitize.count ?? 0) > 0 ? 'warn' : 'ok'}
          hint={tm('pendingSanitizeHint')}
        />
        <Metric label={tm('scheduled')} value={n(scheduled.count)} />
        <Metric label={tm('models')} value={n(models.count)} />
        <Metric
          label={tm('deadJobs')}
          value={n(deadJobs.count)}
          tone={(deadJobs.count ?? 0) > 0 ? 'danger' : 'ok'}
        />
      </div>
    </>
  );
}
