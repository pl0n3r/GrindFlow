@props(['context' => 'workspace'])

<footer class="gf-release-footer gf-release-footer--{{ $context }}"
    data-grindflow-version="{{ config('version.number', 'unknown') }}">
    <span>GrindFlow · <strong>v{{ config('version.number', 'unknown') }}</strong></span>
    <span>{{ $context === 'public' ? 'Tu contenido, en movimiento.' : 'Workspace · GrindFlow' }}</span>
    <a href="#gf-cookie-consent" data-gf-cookie-open>Cambiar preferencias de cookies</a>
</footer>

@once
    <link rel="stylesheet" href="{{ asset('css/cookie-consent.css') }}">
    <section id="gf-cookie-consent" class="gf-cookie-consent" data-gf-cookie-banner
             role="region" aria-labelledby="gf-cookie-consent-title">
        <h2 class="gf-cookie-consent__title" id="gf-cookie-consent-title">Preferencias de cookies</h2>
        <p class="gf-cookie-consent__text">Las cookies opcionales permanecen desactivadas hasta que elijas Aceptar.</p>
        <small class="gf-cookie-consent__legal" data-gf-cookie-legal-pending>Texto legal pendiente de aprobación profesional.</small>
        <div class="gf-cookie-consent__actions">
            <button type="button" class="gf-cookie-consent__action" data-gf-cookie-action="accept">Aceptar</button>
            <button type="button" class="gf-cookie-consent__action" data-gf-cookie-action="reject">Rechazar</button>
        </div>
        <button type="button" class="gf-cookie-consent__close" data-gf-cookie-action="close"
                aria-label="Cerrar aviso y rechazar">Cerrar</button>
    </section>
    <script src="{{ asset('js/cookie-consent.js') }}" defer></script>
@endonce
