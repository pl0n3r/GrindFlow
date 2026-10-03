# GrindFlow · Requisitos de producto unificados

> **Fecha de consolidación:** 2026-10-03  
> **Fuente:** decisiones expresas del propietario durante la toma de requerimientos + contraste con `main` de GrindFlow.  
> **Issue de consolidación:** #294.  
> **Alcance:** producto y negocio. No sustituye la evidencia de implementación, seguridad, migración, CI o producción de `docs/REQUIREMENTS.md`, `docs/GRINDFLOW-SPEC.md`, `docs/STACK-TRANSITION-SYMFONY.md`, `AGENTS.md` ni roadmap #2.

## 1. Cómo leer este documento

Este documento reúne en un solo lugar la visión funcional confirmada por el propietario para evitar que quede repartida entre conversaciones, documentos históricos y módulos técnicos.

Estados usados:

- **CONFIRMADO:** comportamiento o dirección de producto aprobado expresamente por el propietario.
- **HIPÓTESIS COMERCIAL:** idea aprobada para estudiar, pero cuyos números/precios/límites finales requieren costos y datos reales.
- **PENDIENTE:** propuesta planteada pero todavía no aprobada.
- **BASE EXISTENTE:** GrindFlow ya tiene un contrato técnico o capacidad relacionada; el requisito nuevo debe extenderla, no duplicarla.

La palabra **automático** nunca autoriza a eludir políticas de plataformas, permisos, derechos sobre el contenido, controles de seguridad, revisión humana obligatoria ni límites de APIs oficiales. Si una plataforma no permite una acción por API, GrindFlow debe fallar cerrado, preparar el trabajo para finalización humana o usar una alternativa oficialmente permitida.

## 2. Visión de producto confirmada

GrindFlow es un **SaaS para creadores de contenido en general**. No se diseña únicamente para modelos webcam. Debe servir, entre otros, a streamers, influencers, creadores de gaming, lifestyle, tecnología, educación, música, fitness, moda, negocios/productos y creadores de sectores con contenido restringido.

La propuesta principal es:

> **El creador produce y sube contenido; GrindFlow lo organiza, adapta, programa, distribuye y mide según reglas configuradas por el propio creador.**

El objetivo es que el creador pueda configurar su plan una vez, continuar alimentando la biblioteca y dejar que GrindFlow ejecute el trabajo repetitivo. El usuario conserva siempre el control para pausar, revisar, modificar o desactivar automatizaciones.

Redes objetivo iniciales de producto: **X, Instagram, Facebook, TikTok y YouTube**, sujetas a disponibilidad y capacidades reales de sus APIs oficiales. Para eventos de directo, se contemplan inicialmente **Twitch y Kick** y, después, otros proveedores compatibles y permitidos.

Para creadores de sectores con contenido adulto o restringido, GrindFlow solo debe automatizar contenido promocional permitido en cada red general, aplicar revisión reforzada cuando corresponda y respetar las políticas de la plataforma de destino. La existencia de un enlace externo no autoriza contenido prohibido en la publicación de origen.

## 3. Comparación con el repositorio actual

La visión nueva **no exige empezar GrindFlow desde cero**. `main` ya contiene bases técnicas que deben conservarse y evolucionar:

| Área de producto | Base actual relacionada | Resultado de la comparación |
| --- | --- | --- |
| Organizaciones, cuentas y permisos | `GF-FR-001`, identidad/tenant Symfony, membresías y autorización | **BASE EXISTENTE / ampliar UX y roles comerciales** |
| Biblioteca y carga | `GF-FR-002`, Vault Symfony, quick/direct upload, staging, clasificación, papelera, SHA-256 | **BASE EXISTENTE / ampliar organización, búsqueda, cuotas y automatización** |
| Procesamiento multimedia | `GF-FR-003`, derivados y jobs deterministas | **BASE EXISTENTE / ampliar clips, subtítulos, adaptación y plantillas** |
| Programación | `GF-FR-004`, reglas, agenda, borradores y calendario | **BASE EXISTENTE / ampliar recurrencia autónoma y modos de control** |
| Distribución | `GF-FR-005`, contratos de dispatch, reintentos, idempotencia e historial | **BASE EXISTENTE / conectar proveedores reales de forma permitida** |
| Tráfico y enlaces | `GF-FR-006*`, tracked links y atribución agregada | **BASE EXISTENTE / ampliar a Smart Page y funnel** |
| Finanzas internas | `GF-FR-007` | **EXISTE, pero no define los planes comerciales nuevos** |
| Revisión humana / readiness | contratos Symfony S2/S3/S4 | **BASE EXISTENTE / encaja con Automático-Supervisado-Manual** |
| Ingesta externa | conectores e ingestas actuales, incluyendo Drive/Dropbox en el dominio existente | **PARCIAL / ampliar proveedores y sync orientado a producto** |
| Smart Page | no existe como producto unificado | **NUEVO** |
| Onboarding y Home inteligente | existen superficies de identidad/admin, pero no esta experiencia completa | **PARCIAL / rediseño de producto** |
| IA creativa, Brand Kit, tendencias, música, clips y subtítulos | existen piezas históricas/técnicas aisladas, no el producto confirmado | **NUEVO/PARCIAL** |
| Planes SaaS por almacenamiento/cuentas/equipo | suscripción SaaS está definida como modelo de negocio, no esta matriz comercial | **NUEVO / HIPÓTESIS COMERCIAL** |

**Regla de implementación:** cuando un requisito `GF-PROD-*` se convierta en código, debe mapearse al requisito técnico `GF-*` existente que corresponda o crear uno nuevo sin duplicar contratos equivalentes.

---

# 4. Requisitos confirmados del propietario

## 4.1 Biblioteca, selección y ciclo de vida del contenido

### GF-PROD-001 · Biblioteca central en la nube
**Estado:** CONFIRMADO · BASE EXISTENTE

El creador sube fotos y videos ya preparados a una biblioteca privada de GrindFlow. Esa biblioteca es la fuente de las automatizaciones y debe admitir organización por colecciones/carpetas sin obligar a duplicar el mismo archivo para usarlo en varias redes.

Un recurso puede pertenecer a varias colecciones lógicas y estar autorizado para varias redes o usos.

### GF-PROD-002 · Colecciones, etiquetas y estados comprensibles
**Estado:** CONFIRMADO · PARCIAL

La biblioteca debe combinar:

- colecciones/carpetas;
- etiquetas manuales y sugeridas;
- búsqueda y filtros;
- estados comprensibles para el usuario: nuevo, en uso, publicado, disponible para reutilizar, pausado y no reutilizar;
- reglas de elegibilidad por red/uso.

La clasificación automática de IA es una sugerencia y no reemplaza permisos, derechos ni reglas de publicación.

### GF-PROD-003 · Rotación sin repetición prematura
**Estado:** CONFIRMADO · ampliar Scheduler

Cuando una colección alimenta una automatización, GrindFlow debe priorizar contenido elegible todavía no utilizado antes de repetir. Debe conservar historial por recurso, cuenta/red, uso y fecha.

Cuando se agote el contenido nuevo, la reutilización debe respetar el intervalo mínimo configurado por el creador. Debe poder marcarse un recurso como **no reutilizable**.

### GF-PROD-004 · Agotamiento y cobertura futura
**Estado:** CONFIRMADO

GrindFlow calcula cuánto contenido disponible queda según la frecuencia configurada y muestra una estimación tipo **“contenido para N días”**.

Al agotarse una colección, el creador puede elegir por automatización:

1. detener;
2. reutilizar respetando el intervalo mínimo;
3. pedir aprobación antes de reutilizar;
4. mezclar contenido nuevo y reutilizable.

Debe avisar antes del agotamiento.

### GF-PROD-005 · Contenido evergreen
**Estado:** CONFIRMADO

Un recurso puede marcarse como **evergreen**, es decir, apto para reutilización de largo plazo. GrindFlow puede sugerir volver a activarlo o usarlo para cubrir huecos del calendario, respetando intervalos de repetición y reglas por plataforma.

### GF-PROD-006 · Duplicados exactos y similitud inteligente
**Estado:** CONFIRMADO + HIPÓTESIS COMERCIAL

Todos los planes deben detectar **duplicados exactos** y ofrecer reutilizar el recurso existente en vez de gastar cuota innecesaria. El borrado nunca es automático.

La detección con IA de fotos/videos **muy parecidos**, limpieza sugerida, ahorro estimado y análisis masivo se reserva provisionalmente para Creator Pro o superior.

### GF-PROD-007 · Búsqueda normal y búsqueda con lenguaje natural
**Estado:** CONFIRMADO

La galería debe permitir filtros normales por fecha, colección, red permitida, estado, etiquetas, uso previo, tipo de archivo y creador. También debe admitir consultas sencillas como **“videos gaming que todavía no he usado en TikTok”**.

### GF-PROD-008 · Carga móvil prioritaria
**Estado:** CONFIRMADO · BASE EXISTENTE/PARCIAL

La experiencia móvil debe admitir selección múltiple, progreso, colección, redes permitidas, etiquetas y sugerencias de IA, con un camino rápido tipo **Subir → elegir colección → listo**. El usuario debe poder seguir navegando mientras las cargas continúan cuando la plataforma lo permita.

### GF-PROD-009 · Importación y sincronización con nubes externas
**Estado:** CONFIRMADO · PARCIAL

Se desea importar desde Google Drive, Dropbox, OneDrive y Google Photos cuando sus APIs y permisos lo permitan.

Modos:

- importación única;
- sincronización de carpeta.

GrindFlow no debe borrar ni modificar el origen externo por defecto. Debe mostrar importados, duplicados, omitidos y fallos.

---

## 4.2 Automatización, programación y control

### GF-PROD-010 · Plan recurrente de publicaciones
**Estado:** CONFIRMADO · BASE EXISTENTE/PARCIAL

El producto no se limita a programar publicaciones una por una. El creador define un **plan recurrente** por cuenta/red: frecuencia, ventanas u horarios, colecciones autorizadas, reglas de reutilización y modo de control.

Ejemplo: X = 3 publicaciones diarias usando una colección concreta. Cambiar de 3 a 1 debe recalcular solamente el futuro y conservar el historial.

### GF-PROD-011 · Tres modos de control
**Estado:** CONFIRMADO · BASE EXISTENTE/PARCIAL

Cada cuenta/red puede usar un modo distinto:

- **Automático:** GrindFlow selecciona contenido elegible, prepara el post y ejecuta cuando todas las compuertas lo permiten.
- **Supervisado:** GrindFlow prepara y deja en **Pendientes de aprobación**.
- **Manual:** el usuario define contenido, texto, red y momento; GrindFlow ejecuta la instrucción.

Una revisión obligatoria por seguridad/compliance prevalece sobre el modo Automático.

### GF-PROD-012 · Horario manual e inteligente
**Estado:** CONFIRMADO

El creador puede fijar horas exactas o activar un modo inteligente basado en resultados reales de su propia cuenta. GrindFlow puede recomendar y, si está autorizado, ajustar horas futuras. Los cambios automáticos deben ser visibles y reversibles.

No se pueden presentar como óptimos horarios sin evidencia suficiente.

### GF-PROD-013 · Calendario visual
**Estado:** CONFIRMADO · PARCIAL

Vista día/semana/mes de publicaciones y automatizaciones futuras, con red, contenido y hora. Una publicación futura puede abrirse, editarse, cancelarse o moverse de forma sencilla, incluyendo drag-and-drop cuando sea usable y accesible.

También deben representarse automatizaciones por evento, por ejemplo: **“si comienza un directo, publicar aviso”**.

### GF-PROD-014 · Pausa global y pausas parciales
**Estado:** CONFIRMADO

El usuario puede pausar todo GrindFlow o solo una red, cuenta, campaña o automatización de directo. Pausar no borra configuración ni historial.

Al reanudar se muestran los pendientes y se ofrecen opciones seguras: continuar, reprogramar o descartar.

### GF-PROD-015 · Campañas temporales
**Estado:** CONFIRMADO

Una campaña define objetivo, fecha/ventana, redes, contenido permitido, frecuencia, copy, prioridad en Smart Page y métricas. Durante la campaña puede sobreponer temporalmente parte de la configuración normal; al terminar, GrindFlow retorna a la configuración base sin perder historial.

### GF-PROD-016 · Manejo de fallos y reintentos
**Estado:** CONFIRMADO · BASE EXISTENTE

Ante un fallo de publicación GrindFlow debe:

- preservar el trabajo;
- evitar duplicados mediante idempotencia;
- reintentar solo cuando corresponda y con límite;
- explicar el problema en lenguaje sencillo;
- pedir reconexión cuando la autorización expire;
- mostrar una bandeja **Problemas por resolver**;
- permitir reintento manual;
- continuar pendientes de forma segura cuando el bloqueo se resuelva.

### GF-PROD-017 · Historial y deshacer seguro
**Estado:** CONFIRMADO · BASE EXISTENTE/PARCIAL

Debe existir un historial entendible de publicaciones, fallos, reintentos, cambios de configuración, reutilizaciones y acciones de miembros del equipo. Las configuraciones reversibles importantes pueden ofrecer **Deshacer** o restauración de versión cuando sea seguro.

### GF-PROD-018 · Papelera y versiones de configuración
**Estado:** CONFIRMADO · BASE EXISTENTE/PARCIAL

Fotos, videos, colecciones y otros elementos recuperables deben pasar por papelera antes de borrado definitivo. Planes, campañas y automatizaciones importantes deben poder conservar versiones anteriores restaurables.

Principio UX: **fácil recuperarse de un error; difícil destruir algo importante por accidente**.

---

## 4.3 Redes, adaptación y distribución

### GF-PROD-019 · Redes generales objetivo
**Estado:** CONFIRMADO

Primer conjunto funcional objetivo: X, Instagram, Facebook, TikTok y YouTube. Cada integración debe declarar capacidades reales: tipos de post, historias/reels/shorts si la API los permite, métricas disponibles, límites, revisión necesaria y operaciones no automatizables.

No se debe simular una integración real mediante sandbox ni automatización no autorizada.

### GF-PROD-020 · Adaptación por red sin tocar el original
**Estado:** CONFIRMADO · ampliar procesamiento

Un mismo recurso puede reutilizarse en varias redes. GrindFlow conserva el original y crea derivados cuando sea necesario para formato, relación de aspecto, duración, caption, título o descripción.

Modos por cuenta/red:

1. adaptación automática cuando sea segura;
2. preparar y pedir aprobación;
3. no adaptar y publicar solo si el original cumple.

### GF-PROD-021 · Textos, captions y hashtags con IA
**Estado:** CONFIRMADO

GrindFlow puede generar copy diferente según red, contenido, idioma y voz del creador. El creador puede definir tono, palabras/temas preferidos y palabras/temas a evitar.

El contenido generado debe poder revisarse y editarse. Las recomendaciones de hashtags/tendencias deben usar información vigente cuando exista una fuente permitida y verificable; nunca inventar que algo “está en tendencia”.

### GF-PROD-022 · Reglas independientes por plataforma
**Estado:** CONFIRMADO

Cada cuenta/red almacena reglas sobre colecciones permitidas, formatos, enlaces, categorías que exigen aprobación y nivel de automatización. Esas reglas del creador se combinan con las restricciones reales de la plataforma.

### GF-PROD-023 · Revisión humana obligatoria ante riesgo o incertidumbre
**Estado:** CONFIRMADO · BASE EXISTENTE

Aunque una cuenta esté en Automático, GrindFlow debe detener y enviar a revisión obligatoria contenido, texto, enlace o combinación con riesgo/restricción que no pueda resolver de forma segura. La UI explica el motivo sin afirmar conclusiones que el sistema no pueda verificar.

### GF-PROD-024 · Avisos automáticos de directo
**Estado:** CONFIRMADO

Al detectar el inicio de un stream en un proveedor conectado y permitido, GrindFlow puede publicar avisos en las redes elegidas usando contenido/plantillas autorizados y el enlace correspondiente.

Configuración: redes de aviso, colección o plantilla, copy automático/manual, enlace, modo automático/supervisado y cooldown. Debe evitar duplicar avisos ante desconexiones/reconexiones breves.

### GF-PROD-025 · Música, sonidos y tendencias
**Estado:** CONFIRMADO

Para formatos de video compatibles se ofrecen tres modos:

- **Automático:** escoger audio solo cuando exista una vía oficialmente autorizada/licenciada para esa cuenta y plataforma;
- **Sugerido:** proponer opciones;
- **Manual:** decisión completa del creador.

Si la plataforma exige seleccionar el audio dentro de su propia aplicación, GrindFlow prepara el resto y deja ese paso al usuario; nunca elude la restricción. El creador puede guardar géneros preferidos y excluidos.

---

## 4.4 Inteligencia, creación derivada y reutilización

### GF-PROD-026 · Kit de marca por creador
**Estado:** CONFIRMADO

Cada creador puede guardar logo, colores, tipografías permitidas, nombre/marca, estilo visual, tono, marcas de agua y preferencias/prohibiciones. El Kit de marca alimenta Smart Page, Stories, avisos, portadas, captions y demás derivados.

### GF-PROD-027 · Plantillas creativas para posts, Stories y avisos
**Estado:** CONFIRMADO

A partir del contenido de la biblioteca GrindFlow puede crear Stories, avisos de directo, promociones, portadas, texto sobrepuesto y formatos derivados, conservando siempre el original.

Modos: automático, sugerido y manual.

### GF-PROD-028 · Generación continua de plantillas
**Estado:** CONFIRMADO

El catálogo de plantillas para Smart Page y piezas creativas no debe ser estático. GrindFlow puede generar continuamente nuevas plantillas, clasificarlas por estilo/categoría y proponer variantes estacionales o basadas en tendencias verificables.

Toda plantilla generada debe superar controles de legibilidad, responsive/móvil, accesibilidad, estructura y seguridad antes de ofrecerse. Cambiar de plantilla no debe perder datos, enlaces ni contenido configurado.

### GF-PROD-029 · Videos largos a clips cortos
**Estado:** CONFIRMADO + HIPÓTESIS COMERCIAL

GrindFlow puede analizar videos largos y preparar clips para formatos cortos, adaptar encuadre/orientación, aplicar subtítulos y Kit de marca.

Modos: automático, supervisado y manual. El original permanece intacto.

Por costo de procesamiento, se propone provisionalmente como capacidad **Creator Pro o superior**, pendiente de unit economics.

### GF-PROD-030 · Subtítulos automáticos y multidioma
**Estado:** CONFIRMADO

GrindFlow puede transcribir, generar subtítulos, permitir corrección, incrustarlos opcionalmente, traducirlos y aplicar estilo del Kit de marca. Puede crear versiones por red/mercado.

Modos: automático, supervisado y manual.

### GF-PROD-031 · Reutilización inteligente de contenido de alto rendimiento
**Estado:** CONFIRMADO

Cuando una publicación rinde claramente por encima de la línea base del propio creador, GrindFlow puede sugerir cross-post, adaptación a otro formato, republicación futura, Story/Short, nueva variante de texto o campaña.

La reutilización automática requiere opt-in y debe respetar intervalos, reglas por red y protección contra saturación. No se inventan métricas ni causalidad.

---

## 4.5 Inicio, onboarding, ayuda y experiencia

### GF-PROD-032 · UX extremadamente intuitiva
**Estado:** CONFIRMADO

La interfaz debe estar diseñada para personas sin conocimientos técnicos: lenguaje normal, acciones visibles, estados comprensibles y baja carga cognitiva. Los conceptos internos de programación, colas, APIs o infraestructura no deben filtrarse innecesariamente a la experiencia del creador.

### GF-PROD-033 · Tutorial y ayuda guiada
**Estado:** CONFIRMADO

En el primer uso se ofrece un recorrido por Inicio, Galería, conexión de redes, colecciones, programación, modos de publicación y estadísticas. Se puede saltar un paso o todo el tutorial. Una vez completado/omitido no debe reaparecer de forma molesta; debe poder abrirse después desde Ayuda.

La UI puede usar ayuda contextual en el punto donde el usuario la necesita.

### GF-PROD-034 · Onboarding orientado a objetivos
**Estado:** CONFIRMADO

El onboarding pregunta:

1. tipos de contenido/creador, con selección múltiple;
2. objetivos principales;
3. redes a conectar;
4. primer contenido a cargar;
5. propuesta de un plan inicial editable.

Categorías iniciales pueden incluir gaming, lifestyle, tecnología, educación, música, fitness, belleza/moda, productos/negocio, streaming, sectores de contenido restringido y otras. El sistema no debe inferir automáticamente la identidad o negocio del creador únicamente a partir de una imagen.

### GF-PROD-035 · Home inteligente después del login
**Estado:** CONFIRMADO

La pantalla inicial funciona como centro de operaciones personal: próximas publicaciones, contenido disponible, alertas útiles, directos, rendimiento reciente y recomendaciones accionables.

Debe traducir datos a lenguaje sencillo y ofrecer sugerencias basadas en categoría declarada, comportamiento real, resultados propios y tendencias verificables.

### GF-PROD-036 · Notificaciones sin fatiga
**Estado:** CONFIRMADO

Notificaciones relevantes: éxito resumido, publicación fallida, reconexión, contenido bajo, aprobación pendiente, rendimiento anómalo, aviso de directo y agotamiento de colección.

El usuario elige canales: in-app, correo y posteriormente push móvil. Los eventos rutinarios se agrupan; las alertas urgentes pueden ser inmediatas.

---

## 4.6 Analítica, tráfico y Smart Page

### GF-PROD-037 · Estadísticas simples con explicación
**Estado:** CONFIRMADO · BASE EXISTENTE/PARCIAL

Por red y cuenta se muestran únicamente métricas realmente disponibles: publicaciones realizadas, alcance/vistas, interacciones, comentarios, clics y otras soportadas por el proveedor.

GrindFlow debe explicar patrones en lenguaje normal: formatos que rinden mejor, horarios, colecciones, redes que aportan alcance o tráfico, y cambios sugeridos. Son recomendaciones, no certezas.

### GF-PROD-038 · Smart Page nativa de GrindFlow
**Estado:** CONFIRMADO · NUEVO

Cada creador puede usar enlaces externos o una **Smart Page de GrindFlow**. No debe ser únicamente una lista de botones: se integra con Scheduler, campañas, live, Traffic y analítica.

Capacidades objetivo:

- bloques/enlaces configurables;
- estado **en vivo** dinámico;
- campañas y prioridad temporal;
- atribución desde publicación/red de origen hasta clic saliente cuando sea medible;
- orden/contexto adaptado al origen cuando esté autorizado;
- enlaces estables que puedan resolver al destino vigente;
- A/B tests de orden/copy/diseño con métricas reales;
- sugerencias de optimización;
- destacar contenido relacionado con la publicación de origen;
- funnel sencillo de exposición → visita → clic cuando las fuentes permitan medirlo.

La IA puede recomendar cambios; no debe alterar silenciosamente enlaces sensibles, campañas o destinos que requieran aprobación.

### GF-PROD-039 · Editor y plantillas de Smart Page
**Estado:** CONFIRMADO

El creador puede cambiar avatar/foto, colores, fondo, tipografías controladas, orden de bloques, botones y secciones visibles. El editor debe ofrecer libertad suficiente sin permitir romper fácilmente la legibilidad, el responsive o la accesibilidad.

GrindFlow puede recomendar plantillas según perfil y objetivo. El requisito `GF-PROD-028` aplica a la renovación continua del catálogo.

---

## 4.7 Equipos, idiomas y cuentas

### GF-PROD-040 · Equipos y roles de producto
**Estado:** CONFIRMADO · BASE EXISTENTE/PARCIAL

Una organización puede tener varias personas con permisos comprensibles. Roles de producto objetivo:

- Propietario;
- Administrador;
- Editor;
- Aprobador;
- Analista;
- Solo lectura.

Los nombres comerciales/UX pueden mapearse a permisos técnicos más granulares; nunca deben sustituir la autorización server-side ni el aislamiento por organización.

### GF-PROD-041 · Varias cuentas por red
**Estado:** CONFIRMADO + HIPÓTESIS COMERCIAL

La arquitectura debe soportar múltiples cuentas de una misma plataforma por organización/creador. Comercialmente se propone:

- plan inicial: máximo **1 cuenta por cada red**;
- planes superiores: múltiples cuentas por la misma red.

El número exacto por nivel sigue pendiente de costos y validación comercial.

### GF-PROD-042 · Contenido multidioma
**Estado:** CONFIRMADO + HIPÓTESIS COMERCIAL

El creador define idioma principal y puede asignar idiomas por cuenta/red/mercado. GrindFlow puede adaptar copy, captions, hashtags y subtítulos a cada idioma, evitando traducción literal cuando el contexto requiere adaptación.

Propuesta comercial provisional:

- Creator: un idioma principal;
- Creator Pro: varios idiomas;
- Studio: idiomas por creador/cuenta/campaña;
- Enterprise: alcance personalizado.

---

# 5. Modelo comercial unificado, todavía provisional en cifras

## 5.1 Principio confirmado

El modelo principal es **SaaS por suscripción**. La diferenciación de planes puede usar volumen/capacidad sin inutilizar el plan de entrada.

Ejes aprobados para diferenciar planes:

- almacenamiento permanente;
- número de cuentas por red;
- miembros del equipo;
- volumen/capacidad de IA y procesamiento;
- nivel de analítica;
- campañas y automatizaciones avanzadas;
- cantidad/gestión de Smart Pages;
- funciones profesionales/multi-creador.

## 5.2 Propuesta de planes a validar

> Las cifras siguientes fueron aceptadas como **propuesta de trabajo**, no como tarifas/límites comerciales finales. Deben validarse contra costos reales de almacenamiento, egreso, procesamiento multimedia, IA, APIs y soporte.

| Capacidad | Creator | Creator Pro | Studio | Enterprise |
| --- | --- | --- | --- | --- |
| Almacenamiento orientativo | 50 GB | 200 GB | 1 TB | Personalizado |
| Usuarios orientativos | 1 | hasta 3 | hasta 10 | Personalizado |
| Cuentas por cada red | 1 | múltiples | múltiples/multi-creador | Personalizado |
| Galería + automatización base | Sí | Sí | Sí | Sí |
| Smart Page | Sí | avanzada | múltiples/gestión | personalizada |
| Analítica | base | avanzada | avanzada/equipo | personalizada |
| IA/creación | base | mayor capacidad | alta/multi-creador | personalizada |
| Similitud inteligente | no | sí | sí, masiva | sí |
| Clips largos→cortos | a validar | propuesto | sí | sí |
| Equipos/roles | personal | equipo pequeño | equipo amplio | personalizado |

Se deben permitir **add-ons de almacenamiento** sin obligar a cambiar de plan solo por espacio.

## 5.3 Cuota y almacenamiento

**CONFIRMADO:**

- GrindFlow muestra uso y capacidad de forma entendible.
- Avisos orientativos al 80 %, 90 % y 100 %.
- Al llegar al 100 %, **no se borra nada automáticamente**.
- Las automatizaciones pueden seguir utilizando contenido ya guardado.
- Se bloquean nuevas cargas hasta liberar espacio, vaciar papelera cuando corresponda, comprar capacidad adicional o cambiar de plan.
- Los derivados temporales/internos necesarios para procesamiento no deben hacer que la cuota visible parezca duplicarse sin explicación. La cuota comercial debe centrarse principalmente en la biblioteca permanente; la política exacta de derivados se define con costos reales.

---

# 6. Reglas transversales obligatorias

1. **El creador mantiene el control.** Toda automatización importante puede pausarse o configurarse; las acciones sensibles pueden requerir aprobación.
2. **No modificar originales.** Adaptaciones, recortes, subtítulos y plantillas producen derivados.
3. **No inventar capacidades externas.** Una plataforma figura como conectada/automatizable solo cuando su API, permisos y cuenta real lo permiten.
4. **Cumplimiento por destino.** Las reglas de la plataforma y los derechos/permisos del contenido prevalecen sobre una preferencia de automatización.
5. **Tenant y permisos primero.** Todos los datos, medios, métricas y acciones continúan sujetos a organización/actor.
6. **Historial antes que magia.** Los cambios automáticos relevantes deben poder explicarse y auditarse.
7. **IA como asistencia gobernada.** Puede generar, clasificar y recomendar, pero no convierte una predicción en permiso, derecho, identidad o hecho.
8. **Métricas reales.** No fabricar alcance, tendencias, conversiones o atribución que la fuente no entregue.
9. **Mobile-first.** Carga, revisión, calendario y acciones frecuentes deben funcionar bien desde móvil.
10. **Intuitivo por diseño.** Si una acción común exige comprender infraestructura interna, la UX debe rediseñarse.

---

# 7. Decisiones que NO quedan cerradas por esta consolidación

Estas materias siguen abiertas y no deben implementarse como decisión final sin aprobación/evidencia adicional:

1. **Precios mensuales/anuales exactos** de Creator, Creator Pro, Studio y Enterprise.
2. **50 GB / 200 GB / 1 TB** son capacidades orientativas para modelar costos, no límites finales de lanzamiento.
3. Número final de usuarios, cuentas por red, campañas, Smart Pages, créditos de IA y add-ons por plan.
4. Qué proveedores/redes permiten cada operación exacta mediante API oficial en el momento de implementación.
5. Costos/retención de derivados multimedia y política final de contabilización de cuota.
6. Nivel exacto de automatización musical por plataforma según derechos y APIs.
7. Condiciones finales de prueba gratuita comercial después del piloto.
8. **Vigencia/fecha de expiración de contenido:** fue propuesta durante la toma de requerimientos, pero el propietario todavía no respondió esa pregunta. Estado: **PENDIENTE**, no confirmado.

---

# 8. Orden recomendado de producto después de unificar

Esta consolidación no cambia por sí sola el roadmap técnico ni declara funcionalidades implementadas. Para convertir la visión en entregas, se recomienda mantener los cimientos actuales y agrupar el producto en estas capas:

1. **Core Creator:** onboarding + identidad + biblioteca + colecciones + carga móvil + plan recurrente.
2. **Automation Core:** modos de control + calendario + horarios + agotamiento + pausa + reintentos + historial.
3. **Channels:** cuentas/redes + reglas + adaptaciones + proveedores oficialmente soportados.
4. **Creator Intelligence:** captions + recomendaciones + horarios inteligentes + analítica explicada.
5. **Growth:** Traffic + Smart Page + campañas + live.
6. **Creative AI:** Brand Kit + plantillas + subtítulos + clips + música + multidioma.
7. **Commercial:** cuotas, planes, equipos, add-ons y límites medidos.

Cada capa debe aterrizar en requisitos técnicos GF-* con criterios de aceptación, pruebas y estado separado: **definido → implementado → validado en código → fusionado → desplegado → validado en producción**.

---

# 9. Resultado de la unificación

Esta especificación resuelve la contradicción principal de la historia del proyecto:

- GrindFlow **ya no es un producto diseñado únicamente para modelos webcam**.
- Ese segmento puede seguir siendo un caso de uso, con cumplimiento reforzado, pero el producto se diseña para **creadores de contenido en general**.
- Vault, Scheduler, Distribution y Traffic existentes dejan de verse como módulos aislados y pasan a formar un flujo único:

> **Subir/organizar → autorizar → planificar → adaptar → aprobar cuando corresponda → publicar/entregar → atribuir → medir → aprender → reutilizar.**

Las nuevas superficies Smart Page, IA creativa, Brand Kit, plantillas vivas, clips, subtítulos, multidioma y planes cloud extienden ese flujo sin invalidar la seguridad, tenant, auditoría y evidencia ya construidos en el repositorio.
