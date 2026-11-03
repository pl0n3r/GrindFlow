<!doctype html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacidad y mis derechos · GrindFlow</title>
</head>
<body>
    <a href="#contenido">Saltar al contenido</a>
    <main id="contenido" aria-labelledby="titulo-privacidad">
        <h1 id="titulo-privacidad">Privacidad y mis derechos</h1>
        <p><strong>Estado:</strong> documento informativo provisional. Texto jurídico pendiente de aprobación del abogado.</p>
        <h2>¿Quién es el responsable?</h2>
        <p>[COMPLETAR POR EL DUEÑO]</p>
        <h2>¿Cómo solicito acceso, corrección o borrado?</h2>
        <p>El procedimiento, la comprobación de identidad y el canal aprobado están pendientes de validación profesional.</p>
        @php
            $configured = config('app.privacy_contact_email', '');
            $contactEmail = is_string($configured) ? trim($configured) : '';
            if (! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                $contactEmail = '';
            }
        @endphp
        <p>Contacto del responsable:
            @if ($contactEmail !== '')
                <a href="mailto:{{ rawurlencode($contactEmail) }}">{{ $contactEmail }}</a>
            @else
                <span>[COMPLETAR POR EL DUEÑO]</span>
            @endif
        </p>
        <h2>¿En cuánto tiempo se responde?</h2>
        <p>[COMPLETAR POR EL DUEÑO] — pendiente de confirmación jurídica, sin prometer un plazo no aprobado.</p>
        <p>La publicación de este borrador no autoriza solicitudes con datos reales ni activa servicios nuevos.</p>
    </main>
    <x-release-footer context="public" />
</body>
</html>
