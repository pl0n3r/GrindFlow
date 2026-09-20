import { useEffect, useState, type FormEvent } from 'react';

type Asset = {
  id: string;
  name: string;
  mime_type: string;
  size_bytes: number;
  created_at: string;
  download_url: string;
};

type Props = { canUpload: boolean; csrf: string | null };

export function VaultPanel({ canUpload, csrf }: Props) {
  const [assets, setAssets] = useState<Asset[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState<File[]>([]);
  const [uploading, setUploading] = useState(false);
  const [feedback, setFeedback] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    fetch('/api/admin/vault', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    }).then(async (response) => {
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'No se pudo abrir la biblioteca.');
      return body.data.assets as Asset[];
    }).then(setAssets).catch((cause: unknown) => {
      if (!controller.signal.aborted) {
        setError(cause instanceof Error ? cause.message : 'No se pudo cargar la biblioteca.');
      }
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false);
    });
    return () => controller.abort();
  }, []);

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
        const asset = body.data.asset as Asset;
        setAssets((previous) => [asset, ...previous].slice(0, 30));
        completed += 1;
      } catch (cause) {
        failures.push(file.name + ': ' +
          (cause instanceof Error ? cause.message : 'No se pudo guardar.'));
      }
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
    {loading && <p role="status">Cargando biblioteca…</p>}
    {error && <p role="alert">{error}</p>}
    {!loading && !error && assets.length === 0 &&
      <p role="status">Todavía no hay imágenes en esta organización.</p>}
    {!loading && !error && assets.length > 0 && <>
      <p className="vault-count" role="status">Mostrando {assets.length} imágenes recientes.</p>
      <ul className="vault-list">
        {assets.map((asset) => <li key={asset.id}>
          <span className="vault-image-mark" aria-hidden="true">▧</span>
          <span className="vault-details">
            <strong>{asset.name}</strong>
            <small>{(asset.size_bytes / (1024 * 1024)).toFixed(2)} MiB · {asset.mime_type}</small>
          </span>
          <a href={asset.download_url} download>Descargar</a>
        </li>)}
      </ul>
    </>}
  </section>;
}
