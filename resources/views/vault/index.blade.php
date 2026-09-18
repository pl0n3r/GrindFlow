<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Vault · {{ $organization->name }} · GrindFlow</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}">
</head>
<body>
    <div class="gf-grid" aria-hidden="true"></div>

    <div class="gf-app">
        <aside class="gf-sidebar">
            <x-brand :href="route('dashboard')" />

            <nav class="gf-sidebar__nav" aria-label="Navegacion del workspace">
                <span class="gf-sidebar__label">Workspace</span>

                <a class="gf-navitem" href="{{ route('dashboard') }}">
                    <span class="gf-navitem__icon" aria-hidden="true">◫</span>
                    <span class="gf-navitem__text">Overview</span>
                </a>

                <a
                    class="gf-navitem gf-navitem--active"
                    href="{{ route('organizations.vault.index', ['organizationId' => $organization->id]) }}"
                    aria-current="page"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">◇</span>
                    <span class="gf-navitem__text">Vault</span>
                </a>

                <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                    <span class="gf-navitem__icon" aria-hidden="true">⌁</span>
                    <span class="gf-navitem__text">Scheduler</span>
                </span>

                <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                    <span class="gf-navitem__icon" aria-hidden="true">↗</span>
                    <span class="gf-navitem__text">Distribution</span>
                </span>

                @if (auth()->user()?->isPlatformAdmin())
                    <span class="gf-sidebar__label">Admin</span>

                    <a class="gf-navitem" href="{{ route('admin.system') }}">
                        <span class="gf-navitem__icon" aria-hidden="true">⌘</span>
                        <span class="gf-navitem__text">System</span>
                    </a>

                    <a class="gf-navitem" href="{{ route('admin.diagnostics') }}">
                        <span class="gf-navitem__icon" aria-hidden="true">!</span>
                        <span class="gf-navitem__text">Diagnostics</span>
                    </a>
                @endif
            </nav>

            <div class="gf-sidebar__bottom">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="gf-button gf-button--ghost gf-button--full" type="submit">
                        Cerrar sesion
                    </button>
                </form>
            </div>
        </aside>

        <main class="gf-main">
            <header class="gf-appbar">
                <div class="gf-appbar__meta">
                    GF / {{ strtoupper($organization->slug) }} / VAULT
                </div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Organization scoped
                    </span>
                    <h1>Vault</h1>
                    <p>
                        {{ $organization->name }} · medios privados, metadata trazable
                        y deduplicacion por SHA-256.
                    </p>
                </div>

                @if ($canUpload)
                    <a class="gf-button gf-button--primary" href="#vault-upload">
                        + Add media
                    </a>
                @endif
            </section>

            @if (session('status'))
                <div class="gf-notice gf-notice--success" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="gf-alert" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <section class="gf-metrics" aria-label="Metricas del Vault">
                <article class="gf-metric">
                    <div class="gf-metric__label">Assets</div>
                    <div class="gf-metric__value">{{ $assetCount }}</div>
                    <div class="gf-metric__meta">Registros de ingesta</div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Stored blobs</div>
                    <div class="gf-metric__value">{{ $blobCount }}</div>
                    <div class="gf-metric__meta">
                        {{ $storageBytes >= 1073741824
                            ? number_format($storageBytes / 1073741824, 2).' GB'
                            : number_format($storageBytes / 1048576, 2).' MB' }}
                    </div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Duplicates</div>
                    <div class="gf-metric__value">{{ $duplicateCount }}</div>
                    <div class="gf-metric__meta">Sin segunda copia de bytes</div>
                </article>
            </section>

            @if ($canUpload)
                <section
                    id="vault-upload"
                    class="gf-panel gf-panel--spaced"
                    data-direct-upload-capability="{{ $directUploadAvailable ? 'ready' : 'unavailable' }}"
                >
                    <header class="gf-panel__head">
                        <h2>Direct upload</h2>
                        <span class="gf-appbar__meta">
                            {{ $directUploadAvailable ? 'direct to object storage' : 'storage setup required' }}
                        </span>
                    </header>

                    <div class="gf-panel__body">
                        @if ($directUploadAvailable)
                            <form
                                class="gf-upload"
                                data-direct-upload-form
                                data-intent-url="{{ route('organizations.vault.direct.create', ['organizationId' => $organization->id]) }}"
                                data-complete-url="{{ route('organizations.vault.direct.complete', ['organizationId' => $organization->id]) }}"
                                data-max-bytes="{{ $directUploadMaxBytes }}"
                            >
                                <label class="gf-upload__drop">
                                    <span class="gf-upload__icon" aria-hidden="true">⇧</span>
                                    <span>
                                        <strong>Sube archivos grandes directo al storage</strong>
                                        <small>
                                            JPEG, PNG, WebP, GIF, MP4, MOV o WebM ·
                                            max {{ number_format($directUploadMaxBytes / 1073741824, 1) }} GB
                                        </small>
                                    </span>
                                    <input
                                        type="file"
                                        name="media"
                                        accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm"
                                        required
                                        data-direct-upload-file
                                    >
                                </label>

                                <div class="gf-upload__progress" data-direct-upload-progress-wrap hidden>
                                    <div class="gf-upload__progress-head">
                                        <span data-direct-upload-status>Preparando upload...</span>
                                        <span data-direct-upload-percent>0%</span>
                                    </div>
                                    <progress max="100" value="0" data-direct-upload-progress></progress>
                                </div>

                                <div class="gf-upload__footer">
                                    <p>
                                        El navegador envia los bytes directo al storage.
                                        GrindFlow verifica tamaño y SHA-256 antes de registrar el asset.
                                    </p>
                                    <button class="gf-button gf-button--primary" type="submit">
                                        Direct upload
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="gf-empty gf-empty--compact">
                                <div>
                                    <div class="gf-empty__icon" aria-hidden="true">⇧</div>
                                    <h3>Direct upload aun no esta configurado.</h3>
                                    <p>
                                        El Vault sigue disponible con quick upload. Cuando el storage
                                        S3-compatible tenga credenciales, esta misma pantalla habilita
                                        uploads grandes sin pasar los bytes por PHP.
                                    </p>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="gf-panel gf-panel--spaced">
                    <header class="gf-panel__head">
                        <h2>Quick upload</h2>
                        <span class="gf-appbar__meta">through application · max 8 MB</span>
                    </header>

                    <div class="gf-panel__body">
                        <form
                            class="gf-upload"
                            method="POST"
                            action="{{ route('organizations.vault.store', ['organizationId' => $organization->id]) }}"
                            enctype="multipart/form-data"
                        >
                            @csrf

                            <label class="gf-upload__drop">
                                <span class="gf-upload__icon" aria-hidden="true">＋</span>
                                <span>
                                    <strong>Selecciona una imagen o video</strong>
                                    <small>
                                        JPEG, PNG, WebP, GIF, MP4, MOV o WebM ·
                                        max 8 MB
                                    </small>
                                </span>
                                <input
                                    type="file"
                                    name="media"
                                    accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm"
                                    required
                                >
                            </label>

                            <div class="gf-upload__footer">
                                <p>
                                    Ideal para archivos pequeños. Los bytes iguales comparten un unico
                                    blob y cada ingesta conserva su propio registro.
                                </p>
                                <button class="gf-button gf-button--primary" type="submit">
                                    Quick upload
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            @endif

            <section class="gf-panel gf-panel--spaced">
                <header class="gf-panel__head">
                    <h2>Recent media</h2>
                    <span class="gf-appbar__meta">{{ $assets->count() }} loaded</span>
                </header>

                <div class="gf-panel__body gf-panel__body--flush-mobile">
                    @if ($assets->isEmpty())
                        <div class="gf-empty">
                            <div>
                                <div class="gf-empty__icon" aria-hidden="true">◇</div>
                                <h3>El Vault esta vacio.</h3>
                                <p>
                                    Cuando se ingrese el primer archivo aparecera aqui con
                                    su hash, estado, origen y metadata de almacenamiento.
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="gf-table-wrap">
                            <table class="gf-table">
                                <thead>
                                    <tr>
                                        <th>Media</th>
                                        <th>Status</th>
                                        <th>Size</th>
                                        <th>SHA-256</th>
                                        <th>Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($assets as $asset)
                                        <tr>
                                            <td>
                                                <div class="gf-media-name">{{ $asset->original_filename }}</div>
                                                <div class="gf-media-meta">
                                                    {{ $asset->blob?->mime_type ?? 'unknown' }}
                                                </div>
                                            </td>
                                            <td>
                                                <span class="gf-state {{ $asset->status === 'duplicate' ? 'gf-state--neutral' : 'gf-state--ok' }}">
                                                    {{ $asset->status }}
                                                </span>
                                            </td>
                                            <td>
                                                {{ number_format(($asset->blob?->byte_size ?? 0) / 1048576, 2) }} MB
                                            </td>
                                            <td>
                                                <code class="gf-hash">
                                                    {{ substr((string) $asset->blob?->sha256, 0, 16) }}…
                                                </code>
                                            </td>
                                            <td>{{ $asset->source_type }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        </main>
    </div>

    @if ($canUpload && $directUploadAvailable)
        <script>
            (() => {
                const form = document.querySelector('[data-direct-upload-form]');

                if (!form) {
                    return;
                }

                const fileInput = form.querySelector('[data-direct-upload-file]');
                const button = form.querySelector('button[type="submit"]');
                const progressWrap = form.querySelector('[data-direct-upload-progress-wrap]');
                const progress = form.querySelector('[data-direct-upload-progress]');
                const progressPercent = form.querySelector('[data-direct-upload-percent]');
                const status = form.querySelector('[data-direct-upload-status]');
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const maxBytes = Number(form.dataset.maxBytes ?? 0);

                const setStatus = (message) => {
                    status.textContent = message;
                };

                const setProgress = (value) => {
                    const percent = Math.max(0, Math.min(100, Math.round(value)));
                    progress.value = percent;
                    progressPercent.textContent = percent + '%';
                };

                const responseMessage = async (response) => {
                    try {
                        const payload = await response.json();

                        if (payload?.message) {
                            return payload.message;
                        }

                        const firstError = Object.values(payload?.errors ?? {})[0];

                        if (Array.isArray(firstError) && firstError[0]) {
                            return firstError[0];
                        }
                    } catch (error) {
                        // Keep the generic message below.
                    }

                    return 'No fue posible completar el upload.';
                };

                const putDirect = (intent, file) => new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('PUT', intent.url, true);

                    let hasContentType = false;

                    Object.entries(intent.headers ?? {}).forEach(([name, value]) => {
                        if (name.toLowerCase() === 'content-type') {
                            hasContentType = true;
                        }

                        xhr.setRequestHeader(name, String(value));
                    });

                    if (!hasContentType && file.type) {
                        xhr.setRequestHeader('Content-Type', file.type);
                    }

                    xhr.upload.addEventListener('progress', (event) => {
                        if (event.lengthComputable && event.total > 0) {
                            setProgress((event.loaded / event.total) * 100);
                        }
                    });

                    xhr.addEventListener('load', () => {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            setProgress(100);
                            resolve();
                            return;
                        }

                        reject(new Error('Object storage returned HTTP ' + xhr.status + '.'));
                    });

                    xhr.addEventListener('error', () => {
                        reject(new Error('No fue posible conectar con object storage.'));
                    });

                    xhr.send(file);
                });

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const file = fileInput.files?.[0];

                    if (!file) {
                        return;
                    }

                    if (maxBytes > 0 && file.size > maxBytes) {
                        progressWrap.hidden = false;
                        setStatus('El archivo supera el limite de direct upload.');
                        setProgress(0);
                        return;
                    }

                    button.disabled = true;
                    fileInput.disabled = true;
                    progressWrap.hidden = false;
                    setProgress(0);
                    setStatus('Solicitando URL segura...');

                    try {
                        const intentResponse = await fetch(form.dataset.intentUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify({
                                filename: file.name,
                                mime_type: file.type,
                                byte_size: file.size,
                            }),
                        });

                        if (!intentResponse.ok) {
                            throw new Error(await responseMessage(intentResponse));
                        }

                        const intent = await intentResponse.json();

                        setStatus('Subiendo directo al storage...');
                        await putDirect(intent, file);

                        setStatus('Verificando integridad SHA-256...');

                        const completeResponse = await fetch(form.dataset.completeUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify({
                                upload_token: intent.upload_token,
                            }),
                        });

                        if (!completeResponse.ok) {
                            throw new Error(await responseMessage(completeResponse));
                        }

                        const completed = await completeResponse.json();

                        setStatus(completed.message ?? 'Upload completado.');
                        setProgress(100);

                        window.setTimeout(() => {
                            window.location.reload();
                        }, 650);
                    } catch (error) {
                        setStatus(error instanceof Error ? error.message : 'No fue posible completar el upload.');
                    } finally {
                        button.disabled = false;
                        fileInput.disabled = false;
                    }
                });
            })();
        </script>
    @endif

</body>
</html>
