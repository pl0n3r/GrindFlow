Última actualización automática de las fuentes: 2026-10-29 · SHA-256 de origen: `dbb7b4ce1408f9e62a384a48c0c2bbc8f945409056d9263475e5ac5466fe8c23`

# Documentos para revisión jurídica · GrindFlow

**Estado: `documented_not_legally_approved`.** Este paquete NO constituye aprobación legal ni autoriza datos reales, publicación o paso a live.

## Procedencia

- Proyecto: `pl0n3r/GrindFlow` · versión declarada: `0.1.221`.
- Fuentes: `docs/privacidad/`, `datos.yml` y `config/version.php`; huella SHA-256 verificable: `dbb7b4ce1408f9e62a384a48c0c2bbc8f945409056d9263475e5ac5466fe8c23`. Fecha de generación del contenido: 2026-10-29. La huella se calcula sobre bytes y rutas de origen, no sobre el commit del paquete; permanece válida después de squash.
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
- `meta_instagram`: declarado; uso efectivo y base contractual pendientes de confirmar.
- `supabase`: declarado; uso efectivo y base contractual pendientes de confirmar.

## Mantenimiento

Ejecutar `python3 scripts/generar_revision_juridica.py` para generar desde las fuentes; usar `--check` para validar sin escrituras. El workflow de `main` detecta desfases y abre un Issue deduplicado para que los agentes los corrijan por PR; nunca escribe directamente en `main`.
