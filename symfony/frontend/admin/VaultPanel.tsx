import { useEffect, useState, type FormEvent } from 'react';

type Asset = {
  id: string;
  name: string;
  mime_type: string;
  size_bytes: number;
  created_at: string;
  deleted_at?: string | null;
  download_url: string;
};

type Quota = { used_bytes: number; max_bytes: number; used_assets: number; max_assets: number };

type Props = { canUpload: boolean; csrf: string | null; manageCsrf?: string | null };

export function VaultPanel({ canUpload, csrf, manageCsrf }: Props) {
  const [assets, setAssets] = useState<Asset[]>([]);
  const [page, setPage] = useState(1);
  const [view, setView] = useState<'active' | 'trash'>('active');
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [format, setFormat] = useState<'all' | 'jpeg' | 'png' | 'webp'>('all');
  const [sort, setSort] = useState<'recent' | 'oldest' | 'name_asc' | 'name_desc' | 'size_asc' | 'size_desc'>('recent');
  const [confirmId, setConfirmId] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [renameId, setRenameId] = useState<string | null>(null);
  const [renameName, setRenameName] = useState('');
  const [actionFeedback, setActionFeedback] = useState('');
  const [actionError, setActionError] = useState('');
  const [pages, setPages] = useState(0);
  const [total, setTotal] = useState(0);
  const [quota, setQuota] = useState<Quota | null>(null);
  const [refresh, setRefresh] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState<File[]>([]);
  const [uploading, setUploading] = useState(false);
  const [feedback, setFeedback] = useState('');
  const [hasTrashDuplicate, setHasTrashDuplicate] = useState(false);
  const [detail, setDetail] = useState<Asset | null>(null);
  const [detailError, setDetailError] = useState('');
  const [detailLoading, setDetailLoading] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    fetch('/api/admin/vault?page=' + page + '&view=' + view +
      (search ? '&q=' + encodeURIComponent(search) : '') +
      '&format=' + format + '&sort=' + sort, {
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
      setConfirmId(null);
      setRenameId(null);
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
  }, [page, refresh, view, search, format, sort]);

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

  function searchAssets(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const query = searchDraft.trim();
    if (query === search && page === 1) return;
    setLoading(true);
    setPage(1);
    setSearch(query);
  }

  function clearSearch() {
    if (!search && !searchDraft) return;
    setSearchDraft('');
    if (search || page !== 1) {
      setLoading(true);
      setPage(1);
      setSearch('');
    }
  }

  function switchView(next: 'active' | 'trash') {
    if (view === next) return;
    setLoading(true);
    setPage(1);
    setView(next);
    setConfirmId(null);
    setRenameId(null);
    setDetail(null);
    setActionFeedback('');
    setActionError('');
    setHasTrashDuplicate(false);
  }

  async function changeState(id: string, action: 'trash' | 'restore') {
    if (!canUpload || !manageCsrf || busyId) return;
    setBusyId(id);
    setActionError('');
    setActionFeedback('');
    try {
      const response = await fetch('/api/admin/vault/' + id + '/' + action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': manageCsrf, Accept: 'application/json' },
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'No se pudo actualizar el archivo.');
      setActionFeedback(action === 'trash'
        ? 'Imagen movida a la papelera. Puedes restaurarla.'
        : 'Imagen restaurada en la biblioteca.');
      setPage((current) => current > 1 && assets.length === 1 ? current - 1 : current);
      setRefresh((previous) => previous + 1);
      setConfirmId(null);
      setDetail(null);
    } catch (cause) {
      setActionError(cause instanceof Error ? cause.message : 'No se pudo actualizar el archivo.');
    } finally {
      setBusyId(null);
    }
  }

  async function rename(event: FormEvent<HTMLFormElement>, id: string) {
    event.preventDefault();
    if (!canUpload || !manageCsrf || busyId || !renameName.trim()) return;
    setBusyId(id);
    setActionError('');
    setActionFeedback('');
    try {
      const response = await fetch('/api/admin/vault/' + id + '/name', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': manageCsrf,
          Accept: 'application/json',
        },
        body: JSON.stringify({ name: renameName }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'No se pudo renombrar la imagen.');
      const updated = body.data.name as string;
      setAssets((previous) => previous.map((asset) =>
        asset.id === id ? { ...asset, name: updated } : asset));
      setDetail((previous) => previous?.id === id ? { ...previous, name: updated } : previous);
      setActionFeedback('Nombre de imagen actualizado.');
      setRenameId(null);
    } catch (cause) {
      setActionError(cause instanceof Error ? cause.message : 'No se pudo renombrar la imagen.');
    } finally {
      setBusyId(null);
    }
  }

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!canUpload || !csrf || uploading || !selected.length) return;
    setUploading(true);
    setFeedback('');
    setHasTrashDuplicate(false);
    let completed = 0;
    let trashDuplicate = false;
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
          if (body?.error?.code === 'vault_duplicate_trash') trashDuplicate = true;
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
    setHasTrashDuplicate(trashDuplicate);
    setUploading(false);
  }

  return <section className="admin-settings vault-section" id="biblioteca" aria-labelledby="vault-title">
    <span className="admin-kicker">S2 · BIBLIOTECA PRIVADA</span>
    <h2 id="vault-title">Biblioteca de imágenes</h2>
    <p>Imágenes privadas de la organización seleccionada. Nada se publica externamente.</p>
    <nav className="vault-tabs" aria-label="Vistas de biblioteca">
      <button type="button" aria-pressed={view === 'active'} disabled={loading || !!busyId}
        onClick={() => switchView('active')}>Biblioteca</button>
      <button type="button" aria-pressed={view === 'trash'} disabled={loading || !!busyId}
        onClick={() => switchView('trash')}>Papelera</button>
    </nav>
    {view === 'trash' && <p>Las imágenes en papelera no se pueden descargar y conservan su original privado. No se borran definitivamente en esta versión.</p>}
    <form className="vault-search" role="search" onSubmit={searchAssets}>
      <label htmlFor="vault-search-name">Buscar imágenes por nombre</label>
      <input id="vault-search-name" type="search" value={searchDraft} maxLength={80}
        onChange={(event) => setSearchDraft(event.currentTarget.value)}
        placeholder="Nombre de imagen" />
      <button type="submit" disabled={loading}>Buscar</button>
      {(search || searchDraft) && <button type="button" disabled={loading}
        onClick={clearSearch}>Limpiar búsqueda</button>}
    </form>
    {search && <p className="vault-search-status" role="status">Resultados para «{search}» en {view === 'trash' ? 'papelera' : 'biblioteca'}.</p>}
    <div className="vault-filters" role="group" aria-label="Filtrar y ordenar imágenes">
      <label htmlFor="vault-format">Formato</label>
      <select id="vault-format" value={format} disabled={loading}
        onChange={(event) => {
          setLoading(true);
          setPage(1);
          setFormat(event.currentTarget.value as typeof format);
        }}>
        <option value="all">Todos los formatos</option>
        <option value="jpeg">JPEG</option>
        <option value="png">PNG</option>
        <option value="webp">WebP</option>
      </select>
      <label htmlFor="vault-sort">Ordenar por</label>
      <select id="vault-sort" value={sort} disabled={loading}
        onChange={(event) => {
          setLoading(true);
          setPage(1);
          setSort(event.currentTarget.value as typeof sort);
        }}>
        <option value="recent">Más recientes</option>
        <option value="oldest">Más antiguos</option>
        <option value="name_asc">Nombre A-Z</option>
        <option value="name_desc">Nombre Z-A</option>
        <option value="size_asc">Menor tamaño</option>
        <option value="size_desc">Mayor tamaño</option>
      </select>
    </div>
    {quota && <div className="vault-quota" aria-label="Cuota de almacenamiento">
      <strong>Espacio utilizado: {(quota.used_bytes / (1024 * 1024)).toFixed(2)} de {(quota.max_bytes / (1024 * 1024)).toFixed(0)} MiB</strong>
      <meter aria-label="Uso del almacenamiento" min={0} max={quota.max_bytes}
        value={Math.min(quota.used_bytes, quota.max_bytes)} />
      <small>{quota.used_assets} de {quota.max_assets} imágenes, incluida la papelera. Los originales retenidos siguen ocupando espacio.</small>
    </div>}
    {view === 'active' && canUpload && csrf && <form onSubmit={upload} className="vault-upload">
      <label htmlFor="vault-files">Añadir imágenes desde tu dispositivo</label>
      <input id="vault-files" type="file" multiple accept="image/jpeg,image/png,image/webp"
        disabled={uploading || loading} onChange={(event) =>
          setSelected(Array.from(event.currentTarget.files ?? []))} />
      <small>JPEG, PNG o WebP · máximo 8 MiB por archivo. La subida es individual y no crea copias de imágenes idénticas.</small>
      <button type="submit" disabled={uploading || loading || selected.length === 0}>
        {uploading ? 'Guardando imágenes…' : 'Guardar ' + (selected.length || '') + ' ' + (selected.length === 1 ? 'imagen' : 'imágenes')}
      </button>
    </form>}
    {view === 'active' && !canUpload && <p>Tu rol permite consultar los archivos, pero no añadir nuevos.</p>}
    {feedback && <p role="status" className="vault-feedback">{feedback}</p>}
    {hasTrashDuplicate && view === 'active' && <button type="button" disabled={loading}
      onClick={() => switchView('trash')}>Ver papelera para restaurar</button>}
    {actionFeedback && <p role="status" className="vault-feedback">{actionFeedback}</p>}
    {actionError && <p role="alert">{actionError}</p>}
    {detailLoading && <p role="status">Cargando detalles…</p>}
    {detailError && <p role="alert">{detailError}</p>}
    {loading && <p role="status">Cargando biblioteca…</p>}
    {error && <p role="alert">{error}</p>}
    {!loading && !error && assets.length === 0 &&
      <p role="status">{search || format !== 'all' ? 'No hay imágenes que coincidan con los filtros.' :
        view === 'trash' ? 'La papelera está vacía.' : 'Todavía no hay imágenes en esta organización.'}</p>}
    {!loading && !error && assets.length > 0 && <>
      <p className="vault-count" role="status">{total} imágenes {view === 'trash' ? 'en papelera' : 'en esta organización'} · página {page} de {pages}.</p>
      <ul className="vault-list">
        {assets.map((asset) => <li key={asset.id}>
          <span className="vault-image-mark" aria-hidden="true">▧</span>
          <span className="vault-details">
            <strong>{asset.name}</strong>
            <small>{(asset.size_bytes / (1024 * 1024)).toFixed(2)} MiB · {asset.mime_type}</small>
          </span>
          {view === 'active' && <>
            <button type="button" disabled={detailLoading || !!busyId} aria-expanded={detail?.id === asset.id}
              onClick={() => void inspect(asset.id)}>Detalles</button>
            <a href={asset.download_url} download>Descargar</a>
          </>}
          {view === 'active' && canUpload && manageCsrf &&
            <button type="button" disabled={!!busyId} aria-expanded={renameId === asset.id}
              onClick={() => {
                setRenameId((current) => current === asset.id ? null : asset.id);
                setRenameName(asset.name);
                setConfirmId(null);
              }}>Renombrar</button>}
          {view === 'active' && renameId === asset.id && canUpload && manageCsrf &&
            <form className="vault-rename" onSubmit={(event) => void rename(event, asset.id)}>
              <label htmlFor={'vault-rename-' + asset.id}>Nombre de la imagen</label>
              <input id={'vault-rename-' + asset.id} value={renameName} minLength={2} maxLength={180}
                required disabled={!!busyId} onChange={(event) => setRenameName(event.currentTarget.value)} />
              <button type="submit" disabled={!!busyId || renameName.trim().length < 2}>
                {busyId === asset.id ? 'Guardando…' : 'Guardar nombre'}
              </button>
              <button type="button" disabled={!!busyId} onClick={() => setRenameId(null)}>Cancelar nombre</button>
            </form>}
          {view === 'trash' && <small className="vault-removed-date">En papelera: {asset.deleted_at}</small>}
          {canUpload && manageCsrf && (view === 'trash'
            ? <button type="button" disabled={!!busyId} onClick={() => void changeState(asset.id, 'restore')}>
                {busyId === asset.id ? 'Restaurando…' : 'Restaurar'}
              </button>
            : <button type="button" disabled={!!busyId} aria-expanded={confirmId === asset.id}
                onClick={() => setConfirmId((current) => current === asset.id ? null : asset.id)}>Mover a papelera</button>)}
          {view === 'active' && confirmId === asset.id && canUpload && manageCsrf && <div className="vault-confirm">
            <p>¿Mover «{asset.name}» a la papelera? Podrás restaurarla después.</p>
            <button type="button" disabled={!!busyId} onClick={() => void changeState(asset.id, 'trash')}>
              {busyId === asset.id ? 'Moviendo…' : 'Confirmar movimiento'}
            </button>
            <button type="button" disabled={!!busyId} onClick={() => setConfirmId(null)}>Cancelar</button>
          </div>}
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
