# GrindFlow 1.0.0 — verificador offline de evidencias (GF356)

Este verificador comprueba **recibos declarados** para los seis hitos de salida:
MVP, piloto, recuperación, readiness, seguridad y observabilidad. No consulta
GitHub, servicios, hosting, secretos ni datos reales. Un resultado **GO** solo
significa que el inventario suministrado pasó sus validaciones sintácticas y
temporales, **no** que la evidencia sea auténtica, que haya ocurrido un piloto
o que exista permiso para publicar. El campo publication_allowed es siempre false.

## Entrada JSON por stdin

Ejemplo sintético de entrada intencionalmente incompleta (NO_GO):

    {
      "contract": "gf356-release-100-evidence-v1",
      "as_of": "2026-10-27T12:00:00Z",
      "candidate_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "milestones": {
        "mvp": {
          "status": "PASS",
          "ref": "ci/gf356/mvp#12345",
          "head_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
          "observed_at": "2026-10-27T10:00:00Z",
          "expires_at": "2026-11-01T12:00:00Z"
        }
      }
    }

Para recibir un diagnóstico GO hacen falta los otros cinco hitos: pilot,
recovery, readiness, security y observability, cada uno con referencia distinta
a una evidencia externamente contrastable.

## Contrato fail-closed

- Son obligatorios contract, as_of (UTC) y SHA completo de candidato.
  JSON ambiguo (claves duplicadas), campos desconocidos y tiempo sin zona
  fallan cerrado. El operador proporciona as_of y debe contrastar el reloj
  por fuera, pues la herramienta no verifica la hora contra una fuente externa.
- Cada hito declara status (PASS, FAIL o UNKNOWN), ref, head_sha igual al SHA
  candidato, observed_at y expires_at explícitos UTC; el vencimiento debe ser
  posterior a la observación.
- Una fuente ausente, fecha futura, referencia reutilizada entre hitos,
  SHA ajeno, vencimiento alcanzado o antigüedad mayor a 30 días es NO_GO.
  Una evidencia inválida se informa UNKNOWN; una vencida, STALE.
- Solamente seis hitos PASS/CURRENT producen go_no_go=GO y el diagnóstico
  READY_FOR_OWNER_REVIEW; ninguno de esos estados es autorización humana,
  validación en producción, puerta D-043, ni aprobación de lanzamiento 1.0.0.
- Un manifiesto malformado devuelve código de salida 2, un error genérico y
  ninguna salida de datos no confiables. Un manifiesto válido pero incompleto
  produce código 0 y un reporte NO_GO para el seguimiento de pendientes.
- Las referencias y sus afirmaciones no se descargan ni autentican;
  production_verified=false y owner_decision_required=true siempre.

## Pruebas y uso sin red

    python3 -m unittest tests/test_release_100_evidence.py -v
    python3 scripts/check_release_100_evidence.py < evidencia-sintetica.json

El script solo lee stdin; nunca ejecuta deploy, publicación ni acciones de red.
La evidencia real de MVP, piloto, recuperación, readiness, seguridad y
observabilidad debe revisarse de forma independiente junto a CI exacto,
revisión de seguridad y las puertas humanas vigentes. Un JSON fabricado con
PASS no otorga permiso de operación ni acredita que GrindFlow 1.0.0 esté live.

**Reversión:** revertir el commit squash que añade los tres archivos de esta
hoja. No hay esquema, datos persistidos, acceso productivo ni infraestructura
que restaurar.
