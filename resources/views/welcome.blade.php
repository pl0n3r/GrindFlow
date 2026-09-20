<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101019">
    <meta name="description" content="GrindFlow ayuda a creadores y estudios a organizar, reutilizar y programar contenido, con control sobre sus destinos y resultados.">
    <title>GrindFlow · Tu contenido, en movimiento</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}?v={{ config('version.number') }}">
</head>
<body class="gf-landing">
    <a class="gf-skip-link" href="#contenido">Saltar al contenido</a>
    <div class="gf-landing__halo" aria-hidden="true"></div>

    <div class="gf-landing__wrap">
        <header class="gf-landing__header">
            <x-brand />
            <nav class="gf-landing__nav" aria-label="Navegación principal">
                <a class="gf-landing__nav-link" href="#como-funciona">Cómo funciona</a>
                <a class="gf-landing__nav-link" href="#para-quien">Para quién</a>
                <a class="gf-landing__nav-login" href="{{ route('login') }}">Entrar al workspace <span aria-hidden="true">↗</span></a>
            </nav>
        </header>

        <main id="contenido">
            <section class="gf-landing__hero" aria-labelledby="gf-hero-title">
                <div class="gf-landing__hero-copy">
                    <span class="gf-landing__eyebrow"><span class="gf-landing__pulse" aria-hidden="true"></span> CONTENT OPERATIONS, SIMPLIFIED</span>
                    <h1 id="gf-hero-title">Tu contenido.<br><span>En movimiento.</span></h1>
                    <p>Creas una vez. Organizas, programas y aprovechas mejor cada recurso. GrindFlow reúne tu operación de contenido en un solo lugar para que dediques menos tiempo a lo repetitivo.</p>
                    <div class="gf-landing__actions">
                        <a class="gf-landing__button gf-landing__button--primary" href="{{ route('login') }}">Abrir mi workspace <span aria-hidden="true">↗</span></a>
                        <a class="gf-landing__button gf-landing__button--outline" href="#como-funciona">Descubrir el flujo <span aria-hidden="true">↓</span></a>
                    </div>
                    <p class="gf-landing__hero-note">Plataforma en desarrollo · Piloto inicial en preparación</p>
                </div>

                <div class="gf-landing__visual" aria-label="Vista conceptual del flujo de contenido de GrindFlow">
                    <div class="gf-landing__visual-top"><span><span class="gf-landing__pulse" aria-hidden="true"></span> EL FLUJO DE TU CONTENIDO</span><span>GF / 001</span></div>
                    <div class="gf-landing__media">
                        <div class="gf-landing__media-art" aria-hidden="true"><span></span><span></span><span></span></div>
                        <div><small>01 / BIBLIOTECA</small><strong>Todo empieza aquí.</strong><span>Tu contenido, organizado.</span></div>
                        <span class="gf-landing__media-arrow" aria-hidden="true">↗</span>
                    </div>
                    <div class="gf-landing__visual-path" aria-hidden="true"><span></span><span></span> UN SOLO FLUJO <span></span><span></span></div>
                    <div class="gf-landing__visual-bottom">
                        <div class="gf-landing__mini-card"><small>02 / PROGRAMACIÓN</small><strong>A tu ritmo.</strong><span>Decide cuándo y dónde.</span><div class="gf-landing__mini-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div></div>
                        <div class="gf-landing__mini-card gf-landing__mini-card--green"><small>03 / TRÁFICO</small><strong>Con perspectiva.</strong><span>Resultados medibles.</span><div class="gf-landing__mini-line" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div></div>
                    </div>
                    <p class="gf-landing__visual-note">Representación del recorrido del producto, no datos de una cuenta real.</p>
                </div>
            </section>

            <section class="gf-landing__section" id="como-funciona" aria-labelledby="gf-flow-heading">
                <div class="gf-landing__section-heading">
                    <div><span class="gf-landing__eyebrow">01 / CÓMO FUNCIONA</span><h2 id="gf-flow-heading">Menos tareas repetidas.<br><span>Más espacio para crear.</span></h2></div>
                    <p>No se trata solo de publicar en varias redes. Se trata de acompañar el ciclo de vida de tu contenido con control y trazabilidad.</p>
                </div>
                <div class="gf-landing__steps">
                    <article><span>01</span><h3>Carga y organiza</h3><p>Reúne tus fotos y videos en una biblioteca pensada para encontrar y reutilizar cada recurso.</p></article>
                    <article><span>02</span><h3>Define tus reglas</h3><p>Decide qué contenido utilizar, dónde y con qué frecuencia. Tú conservas el control.</p></article>
                    <article><span>03</span><h3>Prepara y distribuye</h3><p>Programa entregas y revisa su estado en los destinos compatibles y autorizados.</p></article>
                    <article><span>04</span><h3>Observa resultados</h3><p>Relaciona publicaciones con enlaces medibles para aprender del tráfico generado.</p></article>
                </div>
            </section>

            <section class="gf-landing__audience" id="para-quien" aria-labelledby="gf-audience-heading">
                <div><span class="gf-landing__eyebrow">02 / PARA QUIÉN</span><h2 id="gf-audience-heading">Tu operación crece.<br><span>El control se queda contigo.</span></h2><p>Una misma plataforma, distintas formas de crear y trabajar con contenido.</p></div>
                <div class="gf-landing__audience-cards">
                    <article><span>INDIVIDUAL</span><h3>Creadores</h3><p>Una biblioteca para tus recursos y un flujo que libera tiempo para crear.</p></article>
                    <article><span>COLABORATIVO</span><h3>Estudios y agencias</h3><p>Organiza trabajo, perfiles y entregas con responsabilidades claras.</p></article>
                </div>
            </section>

            <section class="gf-landing__last" aria-labelledby="gf-last-heading">
                <span class="gf-landing__eyebrow">GRINDFLOW / EN CONSTRUCCIÓN</span>
                <h2 id="gf-last-heading">Tu próxima publicación empieza<br><span>mucho antes de publicar.</span></h2>
                <p>Estamos construyendo el recorrido completo y preparando el piloto. Puedes acceder al workspace actual para explorar lo que ya está disponible.</p>
                <a class="gf-landing__button gf-landing__button--primary" href="{{ route('login') }}">Ir al workspace <span aria-hidden="true">↗</span></a>
            </section>
        </main>
        <x-release-footer context="public" />
    </div>
</body>
</html>
