# GF-MIG-004 — elegibilidad de retiro de legado por ruta (#913)

**Estado: NO-GO.** Auditoría de fuentes Git, NO evidencia de producción.
Versión documental de contexto: 0.1.225; la versión NO certifica SHA desplegado.
Inventario de origen #682: `docs/LEGACY-DEPENDENCY-INVENTORY.md`;
paridad #681: `docs/PARITY-MATRIX-EXECUTABLE.md`;
gate sintético #641: `tests/test_migration_parity_exit_gate.py`;
runbook #801: `docs/launch/cutover-symfony-runbook.md`;
deuda del modelo #882: independiente de esta hoja.

## Contrato de elegibilidad: ocho pruebas por ruta

Por **cada ruta legacy exacta**, el operador debe demostrar por separado:
comportamiento observable, aislamiento **tenant**, autorización de **roles**,
integridad de **datos**, transiciones de **estados**, compatibilidad de
**identificadores**, conservación de **histórico** y propiedad de
**escritura** (8 dimensiones). El nombre de una clase Symfony o la
existencia de un test en el repositorio NO demuestra equivalencia entre runtimes.

Además son obligatorios: paridad positiva autenticada por ruta, evidencia de
backup cifrado y restauración aislada, rollback **observado** (no solo plan),
cutover **real autorizado** por el propietario, smoke autenticado exact-SHA,
ausencia verificada de doble escritor, recibos independientes del operador
y del propietario, y prueba de que la ruta legacy ya no ejecuta tráfico ni
trabajos. Los recibos identifican ruta, entorno, SHA y resultado terminal:
referencias de texto, banderas booleanas, un SHA escrito en CI o
autodeclaraciones en este documento NO son autoridad verificable.

La columna *Candidato Symfony* identifica exclusivamente hipótesis de
equivalencia técnica del runbook #801, nunca pruebas. En este corte no hay
recibos verificables, ninguna ruta tiene condición de retiro satisfecha
y se preserva el árbol completo con `UNKNOWN | NO | -` en el inventario.
No hay autorización de borrado, migración SQL, switch de tráfico ni go-live.

## Registro por ruta

| Ruta legacy | Capa | Función protegida | Candidato Symfony no acreditado | Paridad | Dimensiones | Prueba por ruta | Rollback observado | Backup restaurado | Cutover real | Smoke exact-SHA | Recibo owner | Doble escritor descartado | Código legacy en Git | Decisión |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `routes/web.php` | laravel | Rutas web, sesión y aislamiento por organización | LoginController /login; HomeController / | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `routes/console.php` | laravel | Tareas CLI, planificador y ownership de ejecución | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | laravel | Autenticación, cookies, CSRF, sesión y roles | LoginController /login | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `workers/main.py` | worker | Orquestación principal de worker y colas | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `workers/db.py` | worker | Acceso a persistencia usado por workers | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `workers/config.py` | worker | Configuración de worker y ámbito de ejecución | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `workers/storage.py` | worker | Operaciones de almacenamiento del worker | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `workers/sanitize.py` | worker | Saneamiento de contenido del worker | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `workers/transcode.py` | worker | Transcodificación y estados de procesamiento | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `workers/watermark.py` | worker | Marca de agua y tratamiento de multimedia | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `workers/test_image_pipeline.py` | worker | Regresión histórica de procesamiento de imagen | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `src/middleware.ts` | nextjs | Middleware histórico de acceso y tenant | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `src/lib/auth.ts` | nextjs | Autenticación del frontend heredado | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `src/lib/credentials.ts` | nextjs | Manejo de credenciales del frontend heredado | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `src/lib/env.ts` | nextjs | Configuración ambiental del frontend heredado | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `src/lib/rate-limit.ts` | nextjs | Limitación de frecuencia del frontend heredado | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `src/lib/r2.ts` | nextjs | Adaptador histórico de almacenamiento R2 | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `src/lib/database.types.ts` | nextjs | Contratos de datos históricos del frontend | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `src/app/globals.css` | nextjs | Presentación global del frontend heredado | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `supabase/migrations/20260101000100_core_tenancy.sql` | supabase | Migración de tablas tenant históricas | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `supabase/migrations/20260101000200_compliance.sql` | supabase | Migración de control de cumplimiento | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `supabase/migrations/20260101000300_media_and_uploads.sql` | supabase | Migración de multimedia e ingesta | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `supabase/migrations/20260101000400_scheduling.sql` | supabase | Migración de programación de contenido | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `supabase/migrations/20260101000500_distribution_and_jobs.sql` | supabase | Migración de distribución y jobs | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `supabase/migrations/20260101000900_rls_policies.sql` | supabase | Políticas RLS heredadas y acceso a datos | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |
| `supabase/migrations/20260101001600_publishing.sql` | supabase | Migración de publicación histórica | NO_IDENTIFICADO | UNKNOWN | PENDIENTE_8_8 | - | NO_OBSERVADO | NO_OBSERVADO | NO_EJECUTADO | NO_OBSERVADO | - | NO_DESCARTADO | PRESENTE | NO-GO |

## Resultado y condiciones para un PR posterior

- **26/26 rutas NO-GO**, ninguna `RETIRABLE`; los archivos legados
  permanecen presentes en el árbol Git. El test exacto
  `tests/test_gf350_retirement_eligibility.py` comprueba la identidad
  y tipos contra #682 y rechaza promociones, rutas ocultas, evidencia
  falsificada y alegaciones de rollback, backup, cutover y escritor único.
- El gate offline siempre carece de autoridad para emitir un GO productivo.
  Cambiar el texto a PARITY_OK o anexar un supuesto recibo no autentica su
  procedencia ni elimina la restricción de doble escritor.
- Para un retiro **posterior**, el propietario debe aprobar por ruta un
  contrato separado con nueva reserva V3 disjunta, evidencias externas
  autenticadas y acotadas a SHA/tenant/rol, snapshot recuperable,
  ensayo de restauración, corte real verificable, smoke exact-SHA,
  rollback observado y confirmación de un único escritor.
- Una aprobación para cutover NO es autorización genérica de borrar un
  worker, URL, tabla o migración; cada ruta tiene identidad propia y
  necesita su propia decisión, según GF-MIG-004 del épico #350.
- Si existe UNKNOWN o evidencia faltante, la respuesta es **NO-GO**;
  no se modifica Laravel, Next.js, workers, Supabase, Hostinger ni datos.
  Un PR de esta hoja solo registra controles offline. Reversión:
  revert del squash documental; no restauración de bases reales.

## Comprobación local

`python3 -m unittest -v tests.test_gf350_retirement_eligibility`
No realiza conexión de red, consultas de datos de usuarios ni escritura de producción.
