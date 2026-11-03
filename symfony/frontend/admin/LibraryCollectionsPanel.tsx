import { useEffect, useRef, useState } from 'react';

type Collection = { id: string; count: number | null };
type CollectionState =
  | { kind: 'loading' }
  | { kind: 'error'; message: string }
  | { kind: 'ready'; collections: Collection[]; incomplete: boolean };

const validUuid = (value: unknown): value is string =>
  typeof value === 'string' &&
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);

async function read(url: string, signal: AbortSignal) {
  let response: Response;
  try {
    response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal,
    });
  } catch {
    throw new Error('No se pudo consultar la biblioteca (error de red).');
  }
  if (response.status === 401 || response.status === 403 || response.status === 409) {
    throw new Error('Acceso denegado o sesión cambiada (403/401/409). Vuelve a seleccionar tu organización.');
  }
  if (!response.ok) throw new Error('No se pudo consultar la biblioteca.');
  return (await response.json())?.data;
}

// The API provides IDs and cursor pages, not names or aggregate counts.
// Never turn an incomplete count, failed page, or revoked access into zero.
async function countAssets(id: string, signal: AbortSignal): Promise<number | null> {
  try {
    let after: string | null = null;
    const seen = new Set<string>();
    const cursors = new Set<string>();
    for (let page = 0; page < 25; page += 1) {
      const path = '/api/admin/library/collections/' + encodeURIComponent(id) + '/assets';
      const data = await read(path + (after ? '?after=' + encodeURIComponent(after) : ''), signal);
      if (data?.status !== 'ok' || !Array.isArray(data.assets)
        || typeof data.has_more !== 'boolean'
        || !data.assets.every(validUuid)) return null;
      for (const asset of data.assets) seen.add(asset);
      if (!data.has_more) return seen.size;
      if (!validUuid(data.next_cursor) || cursors.has(data.next_cursor)
        || data.assets.length === 0) return null;
      const nextCursor: string = data.next_cursor;
      after = nextCursor;
      cursors.add(nextCursor);
    }
  } catch {
    // Missing permissions, transient errors, and incomplete pages remain UNKNOWN.
  }
  return null;
}

export function LibraryCollectionsPanel() {
  const [state, setState] = useState<CollectionState>({ kind: 'loading' });
  const scrollRef = useRef<HTMLDivElement>(null);
  const [hasHorizontalOverflow, setHasHorizontalOverflow] = useState(false);
  const [scrollFocused, setScrollFocused] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    const { signal } = controller;
    async function load() {
      try {
        const ids: string[] = [];
        const seen = new Set<string>();
        const cursors = new Set<string>();
        let after: string | null = null;
        let incomplete = false;
        for (let page = 0; page < 10; page += 1) {
          const path = '/api/admin/library/collections';
          const data = await read(path + (after ? '?after=' + encodeURIComponent(after) : ''), signal);
          if (!Array.isArray(data?.collections) || typeof data.has_more !== 'boolean'
            || !data.collections.every(validUuid)) {
            throw new Error('UNKNOWN: la respuesta de colecciones no es válida.');
          }
          for (const id of data.collections as string[]) {
            if (!seen.has(id)) { seen.add(id); ids.push(id); }
          }
          if (!data.has_more) { incomplete = false; break; }
          incomplete = true;
          if (!validUuid(data.next_cursor) || cursors.has(data.next_cursor)
            || data.collections.length === 0) break;
          const nextCursor: string = data.next_cursor;
          after = nextCursor;
          cursors.add(nextCursor);
        }

        const collections: Collection[] = ids.map((id) => ({ id, count: null }));
        // Restrict concurrency so a large library does not flood tenant endpoints.
        let index = 0;
        await Promise.all(Array.from({ length: Math.min(4, collections.length) }, async () => {
          while (index < collections.length && !signal.aborted) {
            const current = index++;
            collections[current].count = await countAssets(collections[current].id, signal);
          }
        }));
        if (!signal.aborted) setState({ kind: 'ready', collections, incomplete });
      } catch (error) {
        if (signal.aborted) return;
        setState({ kind: 'error', message: error instanceof Error
          ? error.message : 'UNKNOWN: no se pudo consultar la biblioteca.' });
      }
    }
    void load();
    return () => controller.abort();
  }, []);

  useEffect(() => {
    if (state.kind !== 'ready' || state.collections.length === 0) return;
    const container = scrollRef.current;
    if (!container) return;
    const updateOverflow = () => {
      setHasHorizontalOverflow(container.scrollWidth > container.clientWidth + 1);
    };
    updateOverflow();
    // Table or viewport resizing can change whether an extra keyboard stop is useful.
    const observer = new ResizeObserver(updateOverflow);
    observer.observe(container);
    const table = container.querySelector('table');
    if (table) observer.observe(table);
    return () => observer.disconnect();
  }, [state]);

  return (
    <section className="admin-settings" id="colecciones" aria-labelledby="library-collections-title">
      <span className="admin-kicker">S2 · BIBLIOTECA · SOLO LECTURA</span>
      <h2 id="library-collections-title">Colecciones de la biblioteca</h2>
      <p>La API actual identifica colecciones por UUID; no ofrece nombres. Los activos
        contabilizados son los vínculos vigentes que devuelve la API, no publicaciones.</p>
      {state.kind === 'loading' && <p role="status">Cargando colecciones y contando activos…</p>}
      {state.kind === 'error' &&
        <p role="alert">No se pudieron cargar las colecciones. {state.message} Conteos: UNKNOWN.</p>}
      {state.kind === 'ready' && <>
        {state.collections.length === 0 && !state.incomplete &&
          <p role="status">No hay colecciones en esta organización.</p>}
        {state.incomplete &&
          <p role="status">UNKNOWN: hay más colecciones que no se pudieron verificar.</p>}
        {state.collections.length > 0 && <div
          ref={scrollRef}
          data-testid="collections-horizontal-scroll"
          role={hasHorizontalOverflow ? 'region' : undefined}
          aria-label={hasHorizontalOverflow ? 'Desplazamiento horizontal de colecciones' : undefined}
          tabIndex={hasHorizontalOverflow ? 0 : undefined}
          onFocus={() => setScrollFocused(true)}
          onBlur={() => setScrollFocused(false)}
          style={{ maxWidth: '100%', overflowX: 'auto',
            outline: scrollFocused ? '2px solid currentColor' : undefined, outlineOffset: 3 }}>
          <table style={{ width: '100%', minWidth: 440 }}>
            <caption>Colecciones de la organización seleccionada</caption>
            <thead><tr><th scope="col">Identificador de colección</th><th scope="col">Activos vinculados</th></tr></thead>
            <tbody>{state.collections.map((collection) => <tr key={collection.id}>
              <td>{collection.id}</td>
              <td>{collection.count === null ? 'UNKNOWN' : collection.count}</td>
            </tr>)}</tbody>
          </table>
        </div>}
        {state.collections.some((collection) => collection.count === null) &&
          <p role="status">UNKNOWN indica un conteo no verificable; no equivale a cero.</p>}
      </>}
    </section>
  );
}
