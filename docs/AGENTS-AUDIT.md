# Auditoría operativa de AGENTS.md · 20/09/2026

**Ámbito:** instrucciones para agentes, flujo de GitHub y transición tecnológica;
se cotejaron `AGENTS.md`, `config/version.php`, `scripts/release-version.py`,
`scripts/validate-governance.py`, `scripts/ci-scope.sh`, README, el plan
`STACK-TRANSITION-SYMFONY.md` y las comprobaciones del PR #47. Este
documento registra hallazgos y cambios, no afirma haber validado un despliegue.

| Hallazgo | Impacto observado | Corrección aplicada |
| --- | --- | --- |
| 345 líneas de notas antiguas presentaban Laravel como destino futuro y varias rutas Next.js/Supabase como trabajo vigente | Un agente nuevo podía desarrollar módulos en el stack equivocado | Archivo histórico separado, preservado íntegramente, con aviso claro y enlace desde `AGENTS.md` |
| Arranque «leer todo», sin guía por componente ni prioridad de lectura | Lectura costosa, repetición y mayor riesgo de adoptar una nota antigua | Inicio rápido y mapa de lectura por Symfony, Laravel, legado, CI y Hostinger |
| Instrucción de reconciliar PR #7/#9 como si aún estuvieran abiertos | Arranque con tareas obsoletas | Consultar lista real de PRs; los números históricos no reemplazan su estado |
| PR #47 tenía título inicial sin `(V X.Y.Z)` | `preflight` rechazó el título | Convención explícita en el inicio; título del PR corregido |
| PR documental sin bump de versión | `fast` rechazó `0.1.52 → 0.1.52` con el contrato CI real | Bump deliberado `0.1.53` + README de diff exacto en el mismo PR; no reducir cobertura del validador |
| Mapa de CI omitía `symfony-preview` y el índice README no enumeraba sus secciones actuales | Handoffs imprecisos y riesgo de confundir gates/contratos | Actualizados ambos inventarios contra la implementación |
| «Adelante» podía terminar tras una corrección aislada y volver a pedir permiso | Entregas de poco alcance, trabajo redundante | Ciclo de varios avances seguros, PRs enfocados, merges secuenciales y reporte sin promesas de trabajo en segundo plano |
| Estado Symfony, producción y release podían mezclarse en una misma frase | Falsas atribuciones de despliegue o validación productiva | Cinco estados explícitos y obligación de cotejar el SHA exacto antes de atribuir resultados |

## Controles que no se modificaron

Se conservan `GrindFlow CI / validate`, Sonar, revisiones externas, gates
seleccionados y pruebas negativas, así como la separación entre código,
migraciones, despliegue y producción. No se tocaron `public_html`,
bases reales, secretos, proveedores externos ni políticas destructivas.

## Riesgos residuales y revisiones futuras

- `AGENTS.md` aún contiene reglas detalladas de Laravel activo. Una segunda
  extracción por dominio podría reducir más el tamaño, pero requiere revisar
  referencias cruzadas y no debe borrar invariantes durante coexistencia.
- `README.md` almacena el snapshot del PR, no una prueba del SHA de Hostinger.
  El responsable de cada release verifica observer y smoke por separado.
- Los datos históricos archivados pueden contener hipótesis ya obsoletas.
  Solo reactivarlas con evidencia de código, contratos, tests y una decisión
  explícita de arquitectura cuando sea necesaria.
