# Sistema visual de GrindFlow: la parrilla de programación

Decisión del dueño (@pl0n3r, 2026-10-29): GrindFlow tiene identidad propia, distinta de la de Condor. El mundo visual es la parrilla de programación de la televisión y la radio: franjas, bloques de color por etapa y una grilla donde se decide qué sale y cuándo. Este documento es la fuente de los tokens que el home Twig de Symfony y el panel React deben heredar al migrar; hoy se aplican al home Blade (`resources/views/welcome.blade.php`) con `public/css/grindflow-parrilla.css`.

## Qué demuestra el home

GrindFlow convierte un recurso cargado una vez en contenido programado, distribuido y medido, con control humano. La parrilla de ejemplo muestra las cuatro etapas del flujo en filas (Biblioteca, Reglas, Distribución, Tráfico) y la semana en columnas. Es ilustrativa: la leyenda dice que no son datos de una cuenta real.

## Reglas que no se rompen

- **Estado real, con texto.** Cada bloque dice su estado con palabras («Cargado», «Regla activa», «Programado», «En revisión», «Midiendo»); el color no es el único canal. Nada se presenta como disponible antes de estarlo.
- **Sin promesas externas.** El sitio no promete participantes ni lanzamiento: la cohorte real (#271) y la publicación externa (#453) siguen bloqueadas.
- **Contenido visible por defecto.** Toda animación es CSS, va dentro de `prefers-reduced-motion: no-preference` y parte de un estado ya visible (`animation-fill-mode: backwards`; la entrada de las franjas parte de opacidad .35). Una prueba comprueba que ninguna `animation` queda fuera de ese bloque.
- **Sin recursos de terceros.** La tipografía de títulos es local y de licencia OFL.
- **Sin sombras duras, sin etiqueta sobre el título, sin tarjetas iguales como estructura.** La estructura es la grilla y las franjas.

## Tokens

| Token | Valor | Uso |
| --- | --- | --- |
| `--gp-ground` | `#eef1f6` | Terreno claro y frío |
| `--gp-paper` | `#ffffff` | Superficie de la parrilla |
| `--gp-ink` | `#10131a` | Texto y banda de cierre |
| `--gp-ink-2` | `#4b5565` | Texto secundario |
| `--gp-line` | `#d5dae3` | Separadores |
| `--gp-lib` | `#c8105a` | Biblioteca; también la banda de portada |
| `--gp-rules` | `#00766a` | Reglas |
| `--gp-dist` | `#f2a900` | Distribución (texto en tinta) |
| `--gp-traf` | `#5b3cc4` | Tráfico |

Todos los pares texto sobre fondo usados cumplen 4,5:1; `tests/test_home_parrilla_design.py` lo comprueba.

## Tipografía

Archivo variable (ejes de ancho y peso), subconjunto latino, servido desde `public/fonts/archivo-latin-standard-normal.woff2` con su licencia en `public/fonts/LICENSE-OFL-archivo.txt`. Los títulos usan el ancho condensado (68 %), peso 800 y mayúsculas; el texto corrido usa el ancho normal. Procedencia: paquete npm `@fontsource-variable/archivo` 5.3.0.

## Impacto y espacios para imagen

- **Home:** titular a gran escala (hasta 118 px en escritorio), cinta de etapas bajo el hero con el punto de color de cada etapa, números de franja de hasta 96 px, entrada de las franjas al hacer scroll (`animation-timeline: view()`, mejora progresiva) y deslizamiento al pasar el cursor.
- **Espacios para imagen:** tres marcos de proporción fija en el home (hero 4:5 y dos tarjetas 4:3) con rayado de la paleta y el rótulo «Espacio para imagen · …». Son decorativos para lectores de pantalla (`aria-hidden`, incluido el pie) y no llevan `<img>` hasta que haya material aprobado; la proporción fija evita saltos de maquetación cuando lleguen las imágenes.
- **Workspace:** `public/css/grindflow-workspace.css` remapea los tokens `--gf-*` al sistema claro y, en el resumen, da a cada métrica el color de su etapa (organizaciones `gp-traf`, contenido listo `gp-lib`, publicaciones programadas `gp-dist`) con números a gran escala y la etiqueta bajo el título.

## Cómo lo hereda Symfony/React

El home Twig y el panel React importan los tokens `--gp-*` y los colores por etapa, reutilizan la gramática de la parrilla (filas por etapa, bloques con estado en texto) y no vuelven a decidir color, tipografía ni reglas de estado. El login (`resources/views/auth/login.blade.php`) ya usa el sistema: banda de portada en el color de Biblioteca con las cuatro etapas y el formulario sobre papel. El shell autenticado del workspace (`public/css/grindflow-workspace.css`, cargado tras `grindflow.css`) remapea los tokens `--gf-*` al sistema claro de la parrilla: papel con borde fino, navegación activa en tinta y estados con lavado, borde y palabra.

## Pendiente

- Revisión final de Impeccable y su PRODUCT.md/DESIGN.md.
- Fotografía real en los tres espacios de imagen. Al sustituir los marcos decorativos por imágenes aprobadas, conservar sus relaciones 4:5 y 4:3; si la imagen comunica información, retirar `aria-hidden` de ese contenido y proporcionar texto alternativo útil. No presentar material provisional como fotografía real.
- Limpiar del CSS heredado `public/css/grindflow.css` las reglas de la portada anterior (`gf-landing*`, `gf-auth*`, `gf-gradient-text`), que ninguna vista usa.
- Revisión con lector de pantalla.
