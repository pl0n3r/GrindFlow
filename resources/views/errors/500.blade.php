<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Error · GrindFlow</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}">
</head>
<body>
    <div class="gf-grid" aria-hidden="true"></div>

    <main class="gf-error-page">
        <section class="gf-panel gf-error-card">
            <div class="gf-panel__body">
                <span class="gf-kicker">Server error</span>
                <h1>Algo fallo en GrindFlow.</h1>
                <p>
                    El error ya fue registrado para diagnostico. Puedes volver a intentar
                    o compartir el incident ID con soporte.
                </p>

                @if (request()->attributes->get('incident_id'))
                    <div class="gf-incident">
                        <span>Incident ID</span>
                        <code>{{ request()->attributes->get('incident_id') }}</code>
                    </div>
                @endif

                <div class="gf-error-actions">
                    <a class="gf-button gf-button--primary" href="{{ url()->previous() }}">Volver</a>
                    <a class="gf-button gf-button--ghost" href="{{ route('home') }}">Inicio</a>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
