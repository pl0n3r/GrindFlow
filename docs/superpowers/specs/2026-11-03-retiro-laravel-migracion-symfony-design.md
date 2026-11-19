# Migración definitiva de Laravel a Symfony — Diseño

## Intención

GrindFlow pasa a operar únicamente sobre Symfony 7.4, Doctrine/MariaDB y el
admin React/Vite. Laravel y su aplicación Blade/Livewire dejan de atender
tráfico, ejecutar trabajos y escribir datos; al final del programa se retiran
del artefacto desplegable. La finalidad es tener un único backend modular,
auditable y escalable para el piloto y la comercialización, sin dos fuentes de
verdad.

## Objetivos

- Llevar a Symfony los contratos funcionales actuales de Traffic, Finance,
  procesamiento de media y Distribution.
- Conservar aislamiento por organización, IDs, UTC/IANA, estados, auditoría,
  idempotencia, privacidad y reglas de autorización existentes.
- Migrar datos de forma expandir → copiar → verificar → conmutar, con un solo
  escritor por dominio en cada fase.
- Probar cada dominio en MariaDB descartable y el sistema completo en staging
  antes de cambiar el runtime de producción.
- Retirar Laravel solo tras evidencia de paridad, corte y rollback.

## No objetivos

- No activar proveedores sociales, cobros, credenciales ni migraciones de
  producción sin la autorización operativa específica correspondiente.
- No alterar el producto ni importar lógica de Condor ajena a GrindFlow.
- No presentar CI o un despliegue de código como validación productiva.

## Restricciones e invariantes

- Symfony es el propietario final de cada tabla, endpoint y trabajo.
- Ninguna fase permite escritores Laravel y Symfony sobre el mismo agregado.
- Toda mutación exige sesión, CSRF cuando corresponda, membresía vigente y
  autorización por organización; IDs de request no conceden alcance.
- El almacenamiento de media sigue siendo privado, con SHA-256, deduplicación
  y rutas opacas. Traffic mantiene sus límites de minimización de datos.
- Finance conserva ledger append-only, importes enteros/multimoneda y reversas
  inmutables. Distribution conserva idempotencia y auditoría de intentos.
- El corte dispone de backup restaurable de DB y blobs, ventana definida,
  observación y procedimiento de vuelta probado; no se ejecuta por deploy.

## Decisiones del propietario

- Symfony recibe la MariaDB actual de Laravel y todos los blobs existentes;
  se preservan IDs y relaciones, no se parte de una base vacía.
- Hostinger continúa como destino de staging y producción. PHP, extensiones,
  Composer, cron/cola, almacenamiento privado y binarios de media se observan
  allí antes de convertirse en una dependencia del corte.
- La ventana de corte puede congelar escrituras el tiempo necesario para migrar
  el delta y verificarlo correctamente.
- Laravel se elimina tan pronto corte, smoke y verificación post-corte sean
  satisfactorios. Antes de borrarlo queda un backup recuperable.

## Enfoques evaluados

1. **Migración por dominios con corte único final — elegido.** Symfony gana
   paridad y se verifica en staging por dominio; la producción cambia una vez
   cuando no queda ningún escritor Laravel. Reduce el riesgo de doble escritura
   y cumple la retirada total solicitada.
2. **Puente permanente Laravel/Symfony.** Acelera algunas pantallas, pero deja
   dos contratos y propietarios de datos. Se descarta.
3. **Corte inmediato incompleto.** Elimina Laravel rápido, pero hoy regresaría
   Traffic, Finance y parte de Distribution. Se descarta.

## Arquitectura de destino

Symfony se organiza en módulos con controladores HTTP delgados, servicios de
aplicación, puertos de infraestructura y migraciones Doctrine. Cada módulo
mantiene su propio repositorio/consulta tenant-aware y comparte únicamente los
contratos de identidad, autorización, auditoría, media y trabajos.

| Módulo | Entrega Symfony | Fuente de paridad Laravel | Cierre mínimo |
| --- | --- | --- | --- |
| Traffic | enlaces, redirect, agregados diarios, export CSV y gestión | `TrafficController`, `TrafficAttributionRecorder` | tenant, no PII, dedupe y CSV comprobados |
| Finance | ledger, asignaciones, reversas, filtros y CSV | `FinanceController`, servicios Finance | append-only, moneda e inmutabilidad comprobadas |
| Media processing | contratos idempotentes, estados y derivados acotados | `ProcessMediaAsset`, coordinador/procesador | retry, storage y fallos seguros comprobados |
| Distribution | intentos, outbox/cola, reintentos y auditoría | `DispatchScheduledPublication` | idempotencia, 429/auth y trazabilidad comprobadas |
| Cutover | importador verificable, escritor único y retirada Laravel | tablas/migraciones/jobs Laravel | conteos, hashes, rollback y smoke separados |

## Fases y criterio de avance

1. **Base reproducible.** Instalar dependencias bloqueadas y levantar Symfony
   con MariaDB descartable; bloquear cambios nuevos en Laravel salvo defectos
   de seguridad del runtime hasta el corte.
2. **Paridad de Traffic.** Implementar primero un recorrido completo y pruebas
   negativas; no cambiar producción.
3. **Paridad de Finance.** Llevar ledger y reversas a Doctrine con pruebas de
   integridad y compatibilidad de export.
4. **Procesamiento y Distribution.** Introducir contratos/cola Symfony sin
   proveedores reales y con estados idempotentes recuperables.
5. **Inventario y migrador.** Especificar mapeo tabla/columna/ID, checksums,
   conteos y dueños de escritura. El migrador debe ser reanudable, observable y
   reversible antes de la conmutación.
6. **Staging y corte.** Ejecutar migración sobre copia restaurable, pruebas E2E
   y smoke autenticado read-only. En la ventana aprobada se congela Laravel,
   se migra el delta, se verifica, se conmuta tráfico a Symfony y se observa.
7. **Retirada.** Solo con evidencia del corte y ventana de observación: remover
   rutas, jobs, dependencias, artefacto y automatizaciones Laravel. El rollback
   queda disponible hasta terminar la retención acordada.

## Riesgos y mitigación

- **Pérdida o divergencia de datos:** conteos por tenant, hashes de blobs,
  reconciliación de ledger, pruebas de importación reanudable y backup/restore.
- **Doble publicación o doble agregado:** un propietario de escritura por
  módulo, freeze explícito y claves idempotentes preservadas.
- **Capacidades de hosting insuficientes:** validar PHP, extensiones, cola,
  storage y binarios en staging antes de aprobar el corte.
- **Regresión de acceso o aislamiento:** negativas cross-tenant/IDOR, CSRF,
  roles revocados y navegación autenticada en cada dominio.

## Evidencia de cierre

La migración solo se declara terminada si: todos los dominios tienen rutas y
pruebas Symfony; el entorno staging demuestra la operación integrada; la
migración de datos y rollback se ensayaron; producción tiene identidad de
release observable, smoke autenticado de lectura y observación posterior; y
Laravel ya no existe en el artefacto ni en los procesos de ejecución.

