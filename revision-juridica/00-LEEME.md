Última actualización automática de las fuentes: 2026-10-04 · SHA de origen: `6ad61871b9640cf005497f02ad8975356f3e59ba`

# Documentos para revisión jurídica · GrindFlow

**Estado: `documented_not_legally_approved`.** Este paquete NO constituye aprobación legal ni autoriza datos reales, publicación o paso a live.

## Procedencia

- Proyecto: `pl0n3r/GrindFlow` · versión declarada: `0.1.219`.
- Fuente: `docs/privacidad/` y `datos.yml`; último commit que modificó esas fuentes: `6ad61871b9640cf005497f02ad8975356f3e59ba` (2026-10-04).
- Las copias de los siete documentos son byte-exactas; no se editan en esta carpeta. Las correcciones del abogado deben aplicarse primero a las fuentes.

## Documentos para el abogado

| Archivo | Qué contiene / qué debe confirmar o corregir el abogado |
| --- | --- |
| `aviso-privacidad.md` | Aviso de privacidad y autorización; validar suficiencia jurídica, exactitud y redacción de `docs/privacidad/aviso-privacidad.md`. |
| `canal-derechos.md` | Canal de solicitudes de derechos; validar suficiencia jurídica, exactitud y redacción de `docs/privacidad/canal-derechos.md`. |
| `operacion-derechos-incidentes.md` | Runbook de derechos e incidentes; validar suficiencia jurídica, exactitud y redacción de `docs/privacidad/operacion-derechos-incidentes.md`. |
| `politica-tratamiento.md` | Política de tratamiento de datos; validar suficiencia jurídica, exactitud y redacción de `docs/privacidad/politica-tratamiento.md`. |
| `registro-tratamientos.md` | Registro técnico de tratamientos; validar suficiencia jurídica, exactitud y redacción de `docs/privacidad/registro-tratamientos.md`. |
| `retencion.md` | Tabla de plazos de conservación; validar suficiencia jurídica, exactitud y redacción de `docs/privacidad/retencion.md`. |
| `terminos-condiciones.md` | Términos y condiciones del servicio; validar suficiencia jurídica, exactitud y redacción de `docs/privacidad/terminos-condiciones.md`. |
| `paquete-completo.md` | Concatenación ordenada de todos los textos anteriores, para entregar un solo archivo. |

## Hechos del responsable que siguen pendientes

- [ ] Nombre o razón social del responsable
- [ ] Identificación o NIT
- [ ] Dirección física y domicilio
- [ ] Canal/correo de ejercicio de derechos
- [ ] Finalidades, fundamentos jurídicos y consentimientos
- [ ] Retención, transferencias y contratos con encargados
- [ ] Condiciones comerciales, jurisdicción y mecanismos de reclamación

## Proveedores o subprocesadores declarados

Estos identificadores figuran en `datos.yml`, sin afirmar que estén operativos. El abogado debe comprobar uso efectivo, contratos y transferencias:

- `cloudflare_r2`: declarado; uso efectivo y base contractual pendientes de confirmar.
- `dropbox`: declarado; uso efectivo y base contractual pendientes de confirmar.
- `google_drive`: declarado; uso efectivo y base contractual pendientes de confirmar.
- `supabase`: declarado; uso efectivo y base contractual pendientes de confirmar.

## Mantenimiento

Ejecutar `python3 scripts/generar_revision_juridica.py` para generar desde las fuentes; usar `--check` para validar sin escrituras. El workflow de `main` detecta desfases y abre un Issue deduplicado para que los agentes los corrijan por PR; nunca escribe directamente en `main`.
