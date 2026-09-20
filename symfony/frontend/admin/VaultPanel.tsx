import { useEffect, useState, type FormEvent } from 'react';

type Asset = {
  id: string;
  name: string;
  mime_type: string;
  size_bytes: number;
  created_at: string;
  download_url: string;
};

type Quota = { used_bytes: number; max_bytes: number; used_assets: number; max_assets: number };

type Props = { canUpload: boolean; csrf: string | null };

export function VaultPanel({ canUpload, csrf }: Props) {
  const [assets, setAssets] = useState<Asset[]>([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(0);
  const [total, setTotal] = useState(0);
  const [quota, setQuota] = useState<Quota | null>(null);
  const [refresh, setRefresh] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState<File[]>([]);
  const [uploading, setUploading] = useState(false);
  const [feedback, setFeedback] = useState('');
  const [detail, setDetail] = useState<Asset | null>(null);
  const [detailError, setDetailError] = useState('');
  const [detailLoading, setDetailLoading] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    fetch('/api/admin/vault?page=' + page, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    }).then(async (response) => {
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'No se pudo abrir la biblioteca.');
      return body.data as { assets: Asset[]; total: number; pages: number; quota?: Quota };
    }).then((data) => {
      if (controller.signal.aborted) return;
      setAssets(data.assets);
      setDetail(null);
      setDetailError('');
      setTotal(data.total);
      setQuota(data.quota ?? null);
      setPages(data.pages);
      setError('');
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted) {
        setQuota(null);
        setError(cause instanceof Error ? cause.message : 'No se pudo cargar la biblioteca.');
      }
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false);
    });
    return () => controller.abort();
  }, [page, refresh]);

  async function inspect(id: string) {
    if (detail?.id === id) {
      setDetail(null);
      return;
    }
    setDetail(null);
    setDetailError('');
    setDetailLoading(true);
    try {
      const response = await fetch('/api/admin/vault/' + id, {
        credentials: 'same-origin', headers: { Accept: 'application/json' },
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'No se pudo consultar la imagen.');
      setDetail(body.data.asset as Asset);
    } catch (cause) {
      setDetailError(cause instanceof Error ? cause.message : 'No se pudo consultar la imagen.');
    } finally {
      setDetailLoading(false);
    }
  }

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!canUpload || !csrf || uploading || !selected.length) return;
    setUploading(true);
    setFeedback('');
    let completed = 0;
    const failures: string[] = [];

    // One image per request, so a failure leaves earlier successes visible.
    for (const file of selected) {
      try {
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) ||
          file.size < 1 || file.size > 8 * 1024 * 1024) {
          throw new Error('Se aceptan imágenes JPEG, PNG o WebP de hasta 8 MiB.');
        }
        const data = new FormData();
        data.append('file', file);
        const response = await fetch('/api/admin/vault', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrf },
          body: data,
        });
        const body = await response.json();
        if (!response.ok) {
          throw new Error(body?.error?.message ?? 'No se pudo guardar esta imagen.');
        }
        // Server is the source of truth for ordering and total after this batch.
        if (!body.data.asset) throw new Error('Respuesta incompleta del servidor.');
        completed += 1;
      } catch (cause) {
        failures.push(file.name + ': ' +
          (cause instanceof Error ? cause.message : 'No se pudo guardar.'));
      }
    }

    if (completed > 0) {
      setPage(1);
      setRefresh((previous) => previous + 1);
    }
    setSelected([]);
    form.reset();
    setFeedback(completed + ' de ' + selected.length + ' imágenes guardadas.' +
      (failures.length ? ' ' + failures.join(' ') : ''));
    setUploading(false);
  }

  return <section className="admin-settings vault-section" id="biblioteca" aria-labelledby="vault-title">
    <span className="admin-kicker">S2 · BIBLIOTECA PRIVADA</span>
    <h2 id="vault-title">Biblioteca de imágenes</h2>
    <p>Imágenes privadas de la organización seleccionada. Nada se publica externamente.</p>
    {quota && <div className="vault-quota" aria-label="Cuota de almacenamiento">
      <strong>Espacio utilizado: {(quota.used_bytes / (1024 * 1024)).toFixed(2)} de {(quota.max_bytes / (1024 * 1024)).toFixed(0)} MiB</strong>
      <meter aria-label="Uso del almacenamiento" min={0} max={quota.max_bytes}
        value={Math.min(quota.used_bytes, quota.max_bytes)} />
      <small>{quota.used_assets} de {quota.max_assets} imágenes. El límite se comprueba al guardar.</small>
    </div>}
    {canUpload && csrf && <form onSubmit={upload} className="vault-upload">
      <label htmlFor="vault-files">Añadir imágenes desde tu dispositivo</label>
      <input id="vault-files" type="file" multiple accept="image/jpeg,image/png,image/webp"
        disabled={uploading || loading} onChange={(event) =>
          setSelected(Array.from(event.currentTarget.files ?? []))} />
      <small>JPEG, PNG o WebP · máximo 8 MiB por archivo. La subida es individual.</small>
      <button type="submit" disabled={uploading || loading || selected.length === 0}>
        {uploading ? 'Guardando imágenes…' : 'Guardar ' + (selected.length || '') + ' ' + (selected.length === 1 ? 'imagen' : 'imágenes')}
      </button>
    </form>}
    {!canUpload && <p>Tu rol permite consultar los archivos, pero no añadir nuevos.</p>}
    {feedback && <p role="status" className="vault-feedback">{feedback}</p>}
    {detailLoading && <p role="status">Cargando detalles…</p>}
    {detailError && <p role="alert">{detailError}</p>}
    {loading && <p role="status">Cargando biblioteca…</p>}
    {error && <p role="alert">{error}</p>}
    {!loading && !error && assets.length === 0 &&
      <p role="status">Todavía no hay imágenes en esta organización.</p>}
    {!loading && !error && assets.length > 0 && <>
      <p className="vault-count" role="status">{total} imágenes en esta organización · página {page} de {pages}.</p>
      <ul className="vault-list">
        {assets.map((asset) => <li key={asset.id}>
          <span className="vault-image-mark" aria-hidden="true">▧</span>
          <span className="vault-details">
            <strong>{asset.name}</strong>
            <small>{(asset.size_bytes / (1024 * 1024)).toFixed(2)} MiB · {asset.mime_type}</small>
          </span>
          <button type="button" disabled={detailLoading} aria-expanded={detail?.id === asset.id}
            onClick={() => void inspect(asset.id)}>Detalles</button>
          <a href={asset.download_url} download>Descargar</a>
          {detail?.id === asset.id && <dl className="vault-metadata">
            <dt>Nombre</dt><dd>{detail.name}</dd>
            <dt>Tipo</dt><dd>{detail.mime_type}</dd>
            <dt>Tamaño</dt><dd>{detail.size_bytes} bytes</dd>
            <dt>Guardada</dt><dd>{detail.created_at}</dd>
          </dl>}
        </li>)}
      </ul>
      {pages > 1 && <nav className="vault-pages" aria-label="Páginas de la biblioteca">
        <button type="button" disabled={loading || page === 1}
          onClick={() => { setLoading(true); setPage((current) => current - 1); }}>Anterior</button>
        <span>Página {page} de {pages}</span>
        <button type="button" disabled={loading || page >= pages}
          onClick={() => { setLoading(true); setPage((current) => current + 1); }}>Siguiente</button>
      </nav>}
    </>}
  </section>;
}
