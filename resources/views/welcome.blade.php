<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <meta
        name="description"
        content="GrindFlow organiza operaciones de contenido, automatizacion y trabajo multi-tenant desde un solo lugar."
    >
    <title>GrindFlow · Content Operations</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}">
</head>
<body>
    <div class="gf-grid" aria-hidden="true"></div>
    <div class="gf-glow gf-glow--cyan" aria-hidden="true"></div>
    <div class="gf-glow gf-glow--violet" aria-hidden="true"></div>

    <div class="gf-container">
        <header class="gf-topbar">
            <x-brand />

            <nav class="gf-nav" aria-label="Navegacion principal">
                <a class="gf-button gf-button--ghost" href="#foundation">Estado</a>
                <a class="gf-button gf-button--primary" href="{{ route('login') }}">Entrar</a>
            </nav>
        </header>

        <main>
            <section class="gf-hero">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Laravel core online
                    </span>

                    <h1 class="gf-hero__title">
                        Control operativo
                        <span class="gf-gradient-text">sin ruido.</span>
                    </h1>

                    <p class="gf-hero__copy">
                        GrindFlow concentra identidad, espacios de trabajo y automatizacion
                        en una base multi-tenant preparada para crecer modulo por modulo.
                        Menos piezas sueltas. Mas trazabilidad.
                    </p>

                    <div class="gf-hero__actions">
                        <a class="gf-button gf-button--primary" href="{{ route('login') }}">
                            Abrir GrindFlow
                            <span aria-hidden="true">↗</span>
                        </a>
                        <a class="gf-button gf-button--ghost" href="#foundation">
                            Ver foundation
                        </a>
                    </div>
                </div>

                <aside class="gf-terminal" aria-label="Estado tecnico actual">
                    <div class="gf-terminal__bar">
                        <span>GF / SYSTEM STATUS</span>
                        <span class="gf-terminal__lights" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </span>
                    </div>

                    <div class="gf-terminal__body">
                        <div class="gf-status-line">
                            <span class="gf-status-line__icon">✓</span>
                            <span class="gf-status-line__label">Laravel 13 foundation</span>
                            <span class="gf-status-line__meta">online</span>
                        </div>

                        <div class="gf-status-line">
                            <span class="gf-status-line__icon">✓</span>
                            <span class="gf-status-line__label">Identity + tenancy</span>
                            <span class="gf-status-line__meta">validated</span>
                        </div>

                        <div class="gf-status-line">
                            <span class="gf-status-line__icon">✓</span>
                            <span class="gf-status-line__label">PostgreSQL RLS</span>
                            <span class="gf-status-line__meta">CI verified</span>
                        </div>

                        <div class="gf-status-line">
                            <span class="gf-status-line__icon">✓</span>
                            <span class="gf-status-line__label">Hostinger runtime</span>
                            <span class="gf-status-line__meta">PHP 8.5</span>
                        </div>
                    </div>
                </aside>
            </section>

            <section class="gf-section" id="foundation">
                <div class="gf-section__header">
                    <div>
                        <span class="gf-kicker">Foundation / 01</span>
                        <h2 class="gf-section__title">La base ya no es una maqueta.</h2>
                        <p class="gf-section__copy">
                            El frontend ahora se apoya en una arquitectura Laravel validada
                            y deja preparado el terreno para los modulos operativos siguientes.
                        </p>
                    </div>
                </div>

                <div class="gf-cards">
                    <article class="gf-card">
                        <span class="gf-card__eyebrow">Access</span>
                        <h3 class="gf-card__title">Identidad aislada</h3>
                        <p class="gf-card__copy">
                            Usuarios, organizaciones y memberships con autorizacion de
                            aplicacion y aislamiento en PostgreSQL.
                        </p>
                    </article>

                    <article class="gf-card">
                        <span class="gf-card__eyebrow">Delivery</span>
                        <h3 class="gf-card__title">Deploy reproducible</h3>
                        <p class="gf-card__copy">
                            PHP 8.5, Composer bloqueado y un flujo de Hostinger que mantiene
                            los cambios de codigo separados de las migraciones.
                        </p>
                    </article>

                    <article class="gf-card">
                        <span class="gf-card__eyebrow">Next</span>
                        <h3 class="gf-card__title">Shell modular</h3>
                        <p class="gf-card__copy">
                            Esta capa visual sera la superficie estable donde ir aterrizando
                            Vault, scheduling, distribucion y analitica.
                        </p>
                    </article>
                </div>
            </section>
        </main>

        <footer class="gf-footer">
            <span>GrindFlow · Modular operations engine</span>
            <span>Laravel 13 / PHP 8.5</span>
        </footer>
    </div>
</body>
</html>
