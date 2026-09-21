import { useEffect, useRef, useState, type FormEvent } from 'react';

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
type UploadResult = { name: string; success: boolean; message: string };
type IntegrityResult = { message: string; warning: boolean };
const integrityLabels: Record<string, string> = {
  verified: 'Original íntegro: tamaño y SHA-256 coinciden.',
  missing: 'El original privado no está disponible: archivo ausente.',
  mismatch: 'Alerta: el tamaño o la huella SHA-256 no coinciden.',
  unavailable: 'No se puede verificar el almacenamiento privado en este momento.',
};

// A rejection with no usable corrective action must not be retried blindly.
// Network and server failures can be retried; a second upload of an already
// saved resource is prevented by the backend's SHA-256 tenant-scoped guard.
function isRetryableUploadFailure(status: number, code: string | undefined): boolean {
  if (code?.startsWith('vault_duplicate_') || code === 'vault_quota_exceeded') return false;
  return status === 408 || status === 429 || status >= 500;
}

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
  const [uploadProgress, setUploadProgress] = useState<{ done: number; total: number; name: string } | null>(null);
  const [uploadResults, setUploadResults] = useState<UploadResult[]>([]);
  const [retryPending, setRetryPending] = useState(false);
  const [feedback, setFeedback] = useState('');
  const [hasTrashDuplicate, setHasTrashDuplicate] = useState(false);
  const [detail, setDetail] = useState<Asset | null>(null);
  const [detailError, setDetailError] = useState('');
  const [detailLoading, setDetailLoading] = useState(false);
  const [previewFailed, setPreviewFailed] = useState(false);
  const [integrity, setIntegrity] = useState<Record<string, IntegrityResult>>({});
  const [checkingId, setCheckingId] = useState<string | null>(null);
  const [bulkChecking, setBulkChecking] = useState(false);
  const [bulkProgress, setBulkProgress] = useState<{ done: number; total: number } | null>(null);
  const [bulkStatus, setBulkStatus] = useState('');
  const integrityRequest = useRef(0);

  useEffect(() => () => { integrityRequest.current += 1; }, []);

  useEffect(() => {
    const controller = new AbortController();
    integrityRequest.current += 1;
    setCheckingId(null);
    setBulkChecking(false);
    setBulkProgress(null);
    setBulkStatus('');
    setIntegrity({});
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
      setIntegrity({});
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

  async function verifyOriginal(asset: Asset) {
    if (checkingId || bulkChecking || busyId) return;
    const request = ++integrityRequest.current;
    setCheckingId(asset.id);
    setIntegrity((previous) => {
      const next = { ...previous };
      delete next[asset.id];
      return next;
    });
    try {
      const response = await fetch('/api/admin/vault/' + asset.id + '/integrity', {
        credentials: 'same-origin', headers: { Accept: 'application/json' },
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'No se pudo comprobar el original.');
      const status = body?.data?.status;
      if (typeof status !== 'string' || !Object.hasOwn(integrityLabels, status)) {
        throw new Error('El resultado de verificación no es válido.');
      }
      if (request === integrityRequest.current) setIntegrity((previous) => ({
        ...previous, [asset.id]: { message: integrityLabels[status], warning: status !== 'verified' },
      }));
    } catch (cause) {
      if (request === integrityRequest.current) setIntegrity((previous) => ({
        ...previous, [asset.id]: {
          message: cause instanceof Error ? cause.message : 'No se pudo comprobar el original.',
          warning: true,
        },
      }));
    } finally {
      if (request === integrityRequest.current) setCheckingId(null);
    }
  }

  async function verifyVisible() {
    if (checkingId || bulkChecking || busyId || loading || assets.length === 0) return;
    const request = ++integrityRequest.current;
    const snapshot = [...assets].slice(0, 30);
    setIntegrity({});
    setBulkChecking(true);
    setBulkProgress({ done: 0, total: snapshot.length });
    setBulkStatus('');
    let warnings = 0;
    try {
      for (const [index, asset] of snapshot.entries()) {
        let result: IntegrityResult;
        try {
          const response = await fetch('/api/admin/vault/' + asset.id + '/integrity', {
            credentials: 'same-origin', headers: { Accept: 'application/json' },
          });
          const body = await response.json();
          if (request !== integrityRequest.current) return;
          // A revoked session cannot be represented as a corrupt individual image.
          if (response.status === 401 || response.status === 403 || response.status === 409) {
            throw new Error(body?.error?.message ?? 'Tu sesión o acceso cambió. Actualiza el panel.');
          }
          if (!response.ok) throw new Error(body?.error?.message ?? 'No se pudo comprobar el original.');
          const status = body?.data?.status;
          if (typeof status !== 'string' || !Object.hasOwn(integrityLabels, status)) {
            throw new Error('El resultado de verificación no es válido.');
          }
          result = { message: integrityLabels[status], warning: status !== 'verified' };
        } catch (cause) {
          if (request !== integrityRequest.current) return;
          result = { message: cause instanceof Error ? cause.message : 'No se pudo verificar.', warning: true };
        }
        if (result.warning) warnings += 1;
        if (request !== integrityRequest.current) return;
        setIntegrity((previous) => ({ ...previous, [asset.id]: result }));
        setBulkProgress({ done: index + 1, total: snapshot.length });
      }
      if (request === integrityRequest.current) {
        setBulkStatus(warnings
          ? warnings + ' de ' + snapshot.length + ' imágenes necesitan revisión. Esta comprobación no es una copia de seguridad.'
          : snapshot.length + ' originales comprobados correctamente. Esta comprobación no es una copia de seguridad.');
      }
    } finally {
      if (request === integrityRequest.current) setBulkChecking(false);
    }
  }

  async function inspect(id: string) {
    setPreviewFailed(false);
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
    integrityRequest.current += 1;
    setCheckingId(null);
    setBulkChecking(false);
    setBulkProgress(null);
    setBulkStatus('');
    setIntegrity({});
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
    setUploadResults([]);
    setUploadProgress({ done: 0, total: selected.length, name: selected[0].name });
    let completed = 0;
    let trashDuplicate = false;
    const failures: string[] = [];
    const failedFiles: File[] = [];
    const results: UploadResult[] = [];
    let rejected = 0;

    // One image per request, so a failure leaves earlier successes visible.
    for (const file of selected) {
      let retryable = true;
      try {
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) ||
          file.size < 1 || file.size > 8 * 1024 * 1024) {
          retryable = false;
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
        // An HTTP success might already have persisted the original. Even when
        // JSON decoding fails, never re-upload an ambiguous successful response.
        // Definitive 4xx errors must not enter the temporary retry queue.
        retryable = !response.ok && isRetryableUploadFailure(response.status, undefined);
        const body = await response.json();
        if (!response.ok) {
          if (body?.error?.code === 'vault_duplicate_trash') trashDuplicate = true;
          retryable = isRetryableUploadFailure(response.status, body?.error?.code);
          throw new Error(body?.error?.message ?? 'No se pudo guardar esta imagen.');
        }
        // Server is the source of truth for ordering and total after this batch.
        if (!body?.data?.asset) {
          // The server replied success: reuploading may duplicate a saved file.
          retryable = false;
          throw new Error('La respuesta de guardado es incompleta; revisa la biblioteca antes de reintentar.');
        }
        completed += 1;
        results.push({ name: file.name, success: true, message: 'Guardada.' });
      } catch (cause) {
        const message = cause instanceof Error ? cause.message : 'No se pudo guardar.';
        if (retryable) failedFiles.push(file);
        else rejected += 1;
        failures.push(file.name + ': ' + message);
        results.push({ name: file.name, success: false, message });
      }
      setUploadResults([...results]);
      setUploadProgress({ done: results.length, total: selected.length, name: file.name });
    }

    if (completed > 0) {
      setPage(1);
      setRefresh((previous) => previous + 1);
    }
    // Keep only failed File objects in memory for a deliberate retry. Already-saved
    // images must never be resubmitted just because another file failed.
    setSelected(failedFiles);
    setRetryPending(failedFiles.length > 0);
    form.reset();
    setFeedback(completed + ' de ' + selected.length + ' imágenes guardadas.' +
      (failures.length ? ' ' + failures.join(' ') : '') +
      (rejected ? ' ' + rejected + (rejected === 1
        ? ' archivo requiere revisión antes de volver a enviarse.'
        : ' archivos requieren revisión antes de volver a enviarse.') : ''));
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
        disabled={uploading || loading} onChange={(event) => {
          setSelected(Array.from(event.currentTarget.files ?? []));
          setRetryPending(false);
          setUploadProgress(null);
          setUploadResults([]);
          setFeedback('');
          setHasTrashDuplicate(false);
        }} />
      <small>JPEG, PNG o WebP · máximo 8 MiB por archivo. La subida es individual y no crea copias de imágenes idénticas.</small>
      {retryPending && selected.length > 0 && <small role="status">{selected.length} {selected.length === 1 ? 'archivo pendiente' : 'archivos pendientes'}. Solo se reenviarán los que fallaron; seleccionar nuevos archivos reemplaza esta lista.</small>}
      {retryPending && <button type="button" disabled={uploading} onClick={() => {
        setSelected([]);
        setRetryPending(false);
        setUploadProgress(null);
        setUploadResults([]);
        setFeedback('');
        setHasTrashDuplicate(false);
      }}>Descartar pendientes</button>}
      <button type="submit" disabled={uploading || loading || selected.length === 0}>
        {uploading ? 'Guardando imágenes…' : retryPending
          ? 'Reintentar ' + selected.length + ' ' + (selected.length === 1 ? 'imagen' : 'imágenes')
          : 'Guardar ' + (selected.length || '') + ' ' + (selected.length === 1 ? 'imagen' : 'imágenes')}
      </button>
      {uploadProgress && <div className="vault-upload-progress" role="status" aria-live="polite">
        <span>Procesadas {uploadProgress.done} de {uploadProgress.total} imágenes.</span>
        <progress aria-label="Progreso de la carga por archivo" max={uploadProgress.total} value={uploadProgress.done} />
      </div>}
      {uploadResults.length > 0 && <ul className="vault-upload-results" aria-label="Resultado por archivo">
        {uploadResults.map((result, index) => <li key={index}>
          <span aria-hidden="true">{result.success ? '✓' : '!'}</span>
          <span>{result.name}: {result.message}</span>
        </li>)}
      </ul>}
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
      <div className="vault-audit">
        <button type="button" disabled={bulkChecking || !!checkingId || !!busyId || loading}
          onClick={() => void verifyVisible()}>
          {bulkChecking ? 'Verificando originales…' : 'Verificar originales visibles (' + assets.length + ')'}
        </button>
        <small>Solo esta página, incluida la papelera cuando está seleccionada. No restaura ni borra archivos.</small>
        {bulkProgress && <p role="status" aria-live="polite">Comprobados {bulkProgress.done} de {bulkProgress.total} originales.</p>}
        {bulkStatus && <p role="status" className="vault-feedback">{bulkStatus}</p>}
      </div>
      <ul className="vault-list">
        {assets.map((asset) => <li key={asset.id}>
          <span className="vault-image-mark" aria-hidden="true">▧</span>
          <span className="vault-details">
            <strong>{asset.name}</strong>
            <small>{(asset.size_bytes / (1024 * 1024)).toFixed(2)} MiB · {asset.mime_type}</small>
          </span>
          <button type="button" disabled={!!checkingId || bulkChecking || !!busyId || loading}
            onClick={() => void verifyOriginal(asset)}
            aria-label={'Verificar integridad de ' + asset.name}>
            {checkingId === asset.id ? 'Comprobando…' : 'Verificar integridad'}
          </button>
          {integrity[asset.id] && <p role={integrity[asset.id].warning ? 'alert' : 'status'}
            className={integrity[asset.id].warning ? 'vault-integrity-warning' : 'vault-integrity-ok'}>
            {integrity[asset.id].message}
          </p>}
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
          {detail?.id === asset.id && <>
            <dl className="vault-metadata">
              <dt>Nombre</dt><dd>{detail.name}</dd>
              <dt>Tipo</dt><dd>{detail.mime_type}</dd>
              <dt>Tamaño</dt><dd>{detail.size_bytes} bytes</dd>
              <dt>Guardada</dt><dd>{detail.created_at}</dd>
            </dl>
            <figure className="vault-preview">
              {previewFailed
                ? <p role="status">La vista previa no está disponible. Comprueba la integridad del original antes de descargarlo.</p>
                : <img src={'/api/admin/vault/' + detail.id + '/preview'}
                    alt={'Vista previa privada de ' + detail.name} loading="lazy" decoding="async"
                    referrerPolicy="no-referrer" onError={() => setPreviewFailed(true)} />}
              <figcaption>Vista previa privada, visible solo con acceso a esta organización.</figcaption>
            </figure>
          </>}
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
