<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Carga móvil · GrindFlow</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}">
</head>
<body class="gf-guest-upload">
    <div class="gf-grid" aria-hidden="true"></div>
    <div class="gf-glow gf-glow--cyan" aria-hidden="true"></div>
    <div class="gf-glow gf-glow--violet" aria-hidden="true"></div>

    <main class="gf-guest-upload__shell">
        <header class="gf-guest-upload__header">
            <a class="gf-brand" href="{{ route('home') }}" aria-label="GrindFlow, inicio">
                <span class="gf-brand__mark" aria-hidden="true">GF</span>
                <span class="gf-brand__name">GrindFlow</span>
            </a>
            <span class="gf-kicker">
                <span class="gf-kicker__dot" aria-hidden="true"></span>
                Carga móvil
            </span>
        </header>

        <section class="gf-guest-upload__card" aria-labelledby="guest-upload-title">
            <div>
                <p class="gf-guest-upload__eyebrow">Envío invitado protegido</p>
                <h1 id="guest-upload-title">Selecciona, revisa y envía.</h1>
                <p class="gf-guest-upload__lead">
                    Puedes revisar cada archivo antes de enviarlo y retirarlo si cambias de idea.
                    El enlace decide de forma segura dónde se recibe la carga.
                </p>
            </div>

            @if ($publicError)
                <div class="gf-upload-alert gf-upload-alert--danger" role="alert">
                    {{ $publicError }}
                </div>
            @endif

            @if ($submittedCount > 0)
                <div class="gf-upload-alert gf-upload-alert--success" role="status">
                    Recibimos {{ $submittedCount }} {{ $submittedCount === 1 ? 'archivo' : 'archivos' }}.
                    Este enlace ya fue consumido para este envío.
                </div>
            @elseif (! $available)
                <div class="gf-upload-alert gf-upload-alert--danger" role="alert">
                    Este enlace de carga ya no está disponible.
                </div>
            @else
                <form
                    class="gf-guest-upload__form"
                    method="post"
                    action="{{ route('guest.upload.store') }}"
                    enctype="multipart/form-data"
                    data-guest-upload
                    data-max-files="{{ $maxFiles }}"
                    data-max-bytes="{{ $maxBytes }}"
                >
                    @csrf
                    <input type="hidden" name="grant" value="{{ $token }}">

                    <div class="gf-upload-picker">
                        <input
                            class="gf-upload-picker__input"
                            id="guest-media"
                            type="file"
                            name="media[]"
                            accept="{{ implode(',', $allowedMimeTypes) }}"
                            multiple
                            required
                            aria-describedby="guest-upload-help guest-upload-rejections"
                        >
                        <label class="gf-upload-picker__label" for="guest-media">
                            <span class="gf-upload-picker__icon" aria-hidden="true">＋</span>
                            <span>
                                <strong>Agregar fotos o videos</strong>
                                <small>
                                    Máximo {{ $maxFiles }} archivos ·
                                    {{ number_format($maxBytes / 1048576, 1) }} MB en total
                                </small>
                            </span>
                        </label>
                    </div>

                    <p class="gf-guest-upload__help" id="guest-upload-help">
                        Revisa los previews. Los archivos rechazados no se incluirán en el envío.
                    </p>

                    <ul
                        class="gf-upload-rejections"
                        id="guest-upload-rejections"
                        aria-live="polite"
                        aria-label="Archivos rechazados"
                    ></ul>

                    <div
                        class="gf-upload-previews"
                        data-upload-previews
                        role="list"
                        aria-label="Archivos listos para enviar"
                    ></div>

                    <div class="gf-guest-upload__summary" aria-live="polite">
                        <span data-upload-count>0 archivos listos</span>
                        <span data-upload-bytes>0 MB</span>
                    </div>

                    <button class="gf-button gf-button--primary gf-button--full" type="submit" data-upload-submit disabled>
                        Enviar archivos
                    </button>
                </form>
            @endif
        </section>

        <p class="gf-guest-upload__privacy">
            No necesitas iniciar sesión. El servidor vuelve a validar límites, tipo de archivo y alcance del enlace antes de guardar cualquier medio.
        </p>
    </main>

    @if ($available)
        <script>
            (() => {
                const form = document.querySelector('[data-guest-upload]');
                if (!form) return;

                const input = document.getElementById('guest-media');
                const previews = form.querySelector('[data-upload-previews]');
                const rejections = document.getElementById('guest-upload-rejections');
                const count = form.querySelector('[data-upload-count]');
                const bytes = form.querySelector('[data-upload-bytes]');
                const submit = form.querySelector('[data-upload-submit]');
                const maxFiles = Number(form.dataset.maxFiles);
                const maxBytes = Number(form.dataset.maxBytes);
                const allowedMimeTypes = new Set(@json(array_values($allowedMimeTypes)));
                let selected = [];
                let objectUrls = [];

                const fileKey = (file) => [file.name, file.size, file.lastModified, file.type].join(':');

                const revokeUrls = () => {
                    objectUrls.forEach((url) => URL.revokeObjectURL(url));
                    objectUrls = [];
                };

                const addRejection = (file, reason) => {
                    const item = document.createElement('li');
                    const name = document.createElement('strong');
                    name.textContent = file.name || 'Archivo';
                    const detail = document.createElement('span');
                    detail.textContent = reason;
                    item.append(name, document.createTextNode(' — '), detail);
                    rejections.appendChild(item);
                };

                const syncInput = () => {
                    const transfer = new DataTransfer();
                    selected.forEach((file) => transfer.items.add(file));
                    input.files = transfer.files;
                };

                const render = () => {
                    revokeUrls();
                    previews.replaceChildren();

                    selected.forEach((file, index) => {
                        const figure = document.createElement('figure');
                        figure.className = 'gf-upload-preview';
                        figure.setAttribute('role', 'listitem');

                        const url = URL.createObjectURL(file);
                        objectUrls.push(url);

                        const media = file.type.startsWith('video/')
                            ? document.createElement('video')
                            : document.createElement('img');

                        media.src = url;
                        media.className = 'gf-upload-preview__media';
                        if (media instanceof HTMLVideoElement) {
                            media.muted = true;
                            media.preload = 'metadata';
                            media.playsInline = true;
                            media.controls = true;
                        } else {
                            media.alt = '';
                        }

                        const caption = document.createElement('figcaption');
                        caption.className = 'gf-upload-preview__caption';

                        const filename = document.createElement('span');
                        filename.className = 'gf-upload-preview__name';
                        filename.textContent = file.name;

                        const remove = document.createElement('button');
                        remove.className = 'gf-upload-preview__remove';
                        remove.type = 'button';
                        remove.textContent = 'Retirar';
                        remove.setAttribute('aria-label', 'Retirar ' + file.name);
                        remove.addEventListener('click', () => {
                            selected.splice(index, 1);
                            syncInput();
                            render();
                        });

                        caption.append(filename, remove);
                        figure.append(media, caption);
                        previews.appendChild(figure);
                    });

                    const totalBytes = selected.reduce((total, file) => total + file.size, 0);
                    count.textContent = selected.length === 1
                        ? '1 archivo listo'
                        : selected.length + ' archivos listos';
                    bytes.textContent = (totalBytes / 1048576).toFixed(1) + ' MB';
                    submit.disabled = selected.length === 0;
                };

                input.addEventListener('change', () => {
                    rejections.replaceChildren();

                    for (const file of Array.from(input.files || [])) {
                        if (selected.some((current) => fileKey(current) === fileKey(file))) {
                            addRejection(file, 'ya está en la selección');
                            continue;
                        }

                        if (!allowedMimeTypes.has(file.type)) {
                            addRejection(file, 'tipo de archivo no permitido');
                            continue;
                        }

                        if (selected.length >= maxFiles) {
                            addRejection(file, 'se alcanzó el máximo de archivos');
                            continue;
                        }

                        const currentBytes = selected.reduce((total, current) => total + current.size, 0);
                        if (file.size < 1 || currentBytes + file.size > maxBytes) {
                            addRejection(file, 'supera el límite disponible del enlace');
                            continue;
                        }

                        selected.push(file);
                    }

                    syncInput();
                    render();
                });

                form.addEventListener('submit', (event) => {
                    if (selected.length === 0) {
                        event.preventDefault();
                        const empty = new File([''], 'selección');
                        addRejection(empty, 'selecciona al menos un archivo válido');
                        input.focus();
                    }
                });

                window.addEventListener('pagehide', revokeUrls, { once: true });
            })();
        </script>
    @endif
</body>
</html>
