<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c8105a">
    <meta name="description" content="GrindFlow ayuda a creadores y estudios a organizar, reutilizar y programar contenido, con control sobre sus destinos y resultados.">
    <title>GrindFlow · Tu contenido, en movimiento</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}?v={{ config('version.number') }}">
    <link rel="stylesheet" href="{{ asset('css/grindflow-parrilla.css') }}?v={{ config('version.number') }}">
</head>
<body class="gp-page">
    <a class="gf-skip-link" href="#contenido">Saltar al contenido</a>

    <main id="contenido">
        <section class="gp-hero" aria-labelledby="gf-hero-title">
        <div class="gp-wrap">
            <header class="gp-header">
                <x-brand />
                <nav class="gp-nav" aria-label="Navegación principal">
                    <a href="#como-funciona">Cómo funciona</a>
                    <a href="#para-quien">Para quién</a>
                    <a class="gp-nav__login" href="{{ route('login') }}">
                        Entrar al workspace <span aria-hidden="true">↗</span>
                    </a>
                </nav>
            </header>
            <h1 id="gf-hero-title">Tu contenido.<span>En movimiento.</span></h1>
            <p class="gp-lead">
                Creas una vez. Organizas, programas y aprovechas mejor cada recurso. GrindFlow reúne tu operación de
                contenido en un solo lugar para que dediques menos tiempo a lo repetitivo.
            </p>
            <div class="gp-actions">
                <a class="gp-btn gp-btn--ink" href="{{ route('login') }}">
                    Abrir mi workspace <span class="gp-arrow" aria-hidden="true">↗</span>
                </a>
                <a class="gp-btn gp-btn--line" href="#como-funciona">
                    Descubrir el flujo <span class="gp-arrow" aria-hidden="true">↓</span>
                </a>
            </div>
            <p class="gp-note">Plataforma en desarrollo · Piloto inicial en preparación</p>
            <figure class="gp-img gp-img--hero">
                <div class="gp-img__frame" aria-hidden="true"><span>Imagen</span></div>
                <figcaption>Espacio para imagen · 4:5 · Captura del workspace o foto de un creador</figcaption>
            </figure>
        </div>
    </section>

    <div class="gp-ticker" aria-hidden="true">
        <div class="gp-ticker__track">
            @for ($pass = 0; $pass < 2; $pass++)
                <span class="gp-row--lib">Biblioteca</span><span class="gp-row--rules">Reglas</span><span class="gp-row--dist">Distribución</span><span class="gp-row--traf">Tráfico</span>
            @endfor
        </div>
    </div>

        <section class="gp-board-section" aria-label="Vista conceptual del flujo de contenido de GrindFlow">
            <div class="gp-wrap">
                <div class="gp-board">
                    <p class="gp-board__note" id="gp-board-note">
                        Parrilla de ejemplo: así avanza un recurso por GrindFlow durante una semana.
                        Son datos ilustrativos, no de una cuenta real.
                    </p>
                    {{-- Región desplazable en celular: debe poder alcanzarse con teclado (WCAG 2.1.1). --}}
                    <section class="gp-scroll" tabindex="0" {{-- NOSONAR --}}
                        aria-label="Parrilla de ejemplo, desplazable">
                        <table class="gp-grid" aria-describedby="gp-board-note">
                            <caption class="gp-vh">Parrilla de ejemplo de una semana de contenido</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Etapa</th>
                                    <th scope="col">Lun</th>
                                    <th scope="col">Mar</th>
                                    <th scope="col" class="gp-today">Mié · hoy</th>
                                    <th scope="col">Jue</th>
                                    <th scope="col">Vie</th>
                                    <th scope="col">Sáb</th>
                                    <th scope="col">Dom</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="gp-row--lib">
                                    <th scope="row">Biblioteca</th>
                                    <td colspan="2">
                                        <div class="gp-block">
                                        <strong>Fotos del estudio</strong>
                                        <small>Cargado</small>
                                        </div>
                                        </td>
                                    <td>
                                        <div class="gp-block">
                                        <strong>Video corto</strong>
                                        <small>Cargado</small>
                                        </div>
                                        </td>
                                    <td colspan="2">
                                        <div class="gp-block is-review">
                                        <strong>Pack de producto</strong>
                                        <small>En revisión</small>
                                        </div>
                                        </td>
                                    <td colspan="2"></td>
                                </tr>
                                <tr class="gp-row--rules">
                                    <th scope="row">Reglas</th>
                                    <td></td>
                                    <td colspan="3">
                                        <div class="gp-block">
                                        <strong>Un recurso por destino</strong>
                                        <small>Regla activa</small>
                                        </div>
                                        </td>
                                    <td colspan="2">
                                        <div class="gp-block">
                                        <strong>Horario de publicación</strong>
                                        <small>Regla activa</small>
                                        </div>
                                        </td>
                                    <td></td>
                                </tr>
                                <tr class="gp-row--dist">
                                    <th scope="row">Distribución</th>
                                    <td colspan="2"></td>
                                    <td>
                                        <div class="gp-block">
                                        <strong>Foto + texto</strong>
                                        <small>Programado 09:00</small>
                                        </div>
                                        </td>
                                    <td>
                                        <div class="gp-block">
                                        <strong>Video corto</strong>
                                        <small>Programado 18:00</small>
                                        </div>
                                        </td>
                                    <td>
                                        <div class="gp-block is-review">
                                        <strong>Pack de producto</strong>
                                        <small>En revisión</small>
                                        </div>
                                        </td>
                                    <td>
                                        <div class="gp-block">
                                        <strong>Foto + texto</strong>
                                        <small>Programado 12:00</small>
                                        </div>
                                        </td>
                                    <td></td>
                                </tr>
                                <tr class="gp-row--traf">
                                    <th scope="row">Tráfico</th>
                                    <td colspan="4"></td>
                                    <td colspan="3">
                                        <div class="gp-block">
                                        <strong>Enlaces medibles</strong>
                                        <small>Midiendo</small>
                                        </div>
                                        </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>
        </section>

        <section class="gp-section" id="como-funciona" aria-labelledby="gf-flow-heading">
            <div class="gp-wrap">
                <div class="gp-head">
                    <h2 id="gf-flow-heading">Menos tareas repetidas.<br>Más espacio para crear.</h2>
                    <p>
                        No se trata solo de publicar en varias redes. Se trata de acompañar el ciclo de vida de tu
                        contenido con control y trazabilidad.
                    </p>
                </div>
                <ol class="gp-bands">
                        <li class="gp-band gp-row--lib"><span class="gp-band__n">01</span>
                        <h3>Carga y organiza</h3>
                        <p>
                            Reúne tus fotos y videos en una biblioteca pensada para encontrar y reutilizar cada recurso.
                        </p>
                        </li>
                        <li class="gp-band gp-row--rules"><span class="gp-band__n">02</span>
                        <h3>Define tus reglas</h3>
                        <p>Decide qué contenido utilizar, dónde y con qué frecuencia. Tú conservas el control.</p>
                        </li>
                        <li class="gp-band gp-row--dist"><span class="gp-band__n">03</span>
                        <h3>Prepara y distribuye</h3>
                        <p>Programa entregas y revisa su estado en los destinos compatibles y autorizados.</p>
                        </li>
                        <li class="gp-band gp-row--traf"><span class="gp-band__n">04</span>
                        <h3>Observa resultados</h3>
                        <p>Relaciona publicaciones con enlaces medibles para aprender del tráfico generado.</p>
                        </li>
                </ol>
            </div>
        </section>

        <section class="gp-section" id="para-quien" aria-labelledby="gf-audience-heading">
            <div class="gp-wrap">
                <div class="gp-head">
                    <h2 id="gf-audience-heading">Tu operación crece.<br>El control se queda contigo.</h2>
                    <p>Una misma plataforma, distintas formas de crear y trabajar con contenido.</p>
                </div>
                <div class="gp-aud">
                        <article>
                            <figure class="gp-img gp-img--card">
                <div class="gp-img__frame" aria-hidden="true"><span>Imagen</span></div>
                <figcaption>Espacio para imagen · 4:3 · Creador trabajando con su biblioteca</figcaption>
            </figure>
                            <span>INDIVIDUAL</span>
                        <h3>Creadores</h3>
                        <p>Una biblioteca para tus recursos y un flujo que libera tiempo para crear.</p></article>
                        <article>
                            <figure class="gp-img gp-img--card">
                <div class="gp-img__frame" aria-hidden="true"><span>Imagen</span></div>
                <figcaption>Espacio para imagen · 4:3 · Equipo revisando la programación</figcaption>
            </figure>
                            <span>COLABORATIVO</span>
                        <h3>Estudios y agencias</h3>
                        <p>Organiza trabajo, perfiles y entregas con responsabilidades claras.</p></article>
                </div>
            </div>
        </section>

        <section class="gp-last" aria-labelledby="gf-last-heading">
            <div class="gp-wrap">
                <h2 id="gf-last-heading">Tu próxima publicación empieza mucho antes de publicar.</h2>
                <p>
                    Estamos construyendo el recorrido completo y preparando el piloto. Puedes acceder al workspace
                    actual para explorar lo que ya está disponible.
                </p>
                <a class="gp-btn gp-btn--amber" href="{{ route('login') }}">
                    Ir al workspace <span class="gp-arrow" aria-hidden="true">↗</span>
                </a>
            </div>
        </section>
    </main>

    <div class="gp-wrap">
        <x-release-footer context="public" />
    </div>
</body>
</html>
