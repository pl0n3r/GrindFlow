# Diagnóstico seguro de fallos de jobs (GF343 / #668)

`GrindFlow\Infrastructure\Diagnostics\JobFailureDiagnostics::summarize($failures, $now)` es un contrato **puro y offline** para formar una vista agrupada estable consumible por el panel o logs. Este slice no instala endpoints, instrumenta colas, lee bases de datos, envía eventos ni activa publicación. Tampoco declara saludable ningún servicio.

## Entrada de confianza

`$failures` es una lista de máximo 1000 registros **ya clasificados por una frontera confiable**. Cada registro contiene exactamente las claves, en este orden:

- `type`: `distribution`, `media`, `publication`, `scheduler` u `other`.
- `attempts`: entero de 1 a 1 000 000, número de intentos del fallo reportado.
- `cause`: código de clase `dependency_unavailable`, `retry_exhausted`, `timeout`, `validation_failed` o `unknown`.
- `failed_at`: segundo Unix UTC (entero no negativo y no futuro respecto de `$now`).

El productor debe clasificar tipos y causas **antes** de llamar a la clase y pasar `other` / `unknown` si no se conoce una categoría permitida. Nunca se permiten `payload`, excepciones, trace, mensajes crudos, cabeceras, emails, usuario/tenant, identificadores, tokens, rutas ni datos externos. Entradas arbitrarias y formas incorrectas fallan con `INVALID_JOB_FAILURE_METADATA`, sin reflejar los valores de origen. No ejecutar este parser directamente sobre un mensaje o respuesta de un proveedor.

## Salida JSON v1

```json
{
  "schema_version": 1,
  "as_of": "2026-05-28T20:26:40Z",
  "total_failures": 2,
  "jobs": [
    {
      "type": "scheduler",
      "failures": 2,
      "attempts": 3,
      "last_cause": "timeout",
      "age_seconds": 20
    }
  ]
}
```

`jobs` está ordenado lexicográficamente por tipo. `failures` cuenta los fallos de la categoría, `attempts` es el máximo de intentos observado, `last_cause` pertenece al fallo más reciente y `age_seconds` mide su antigüedad frente a `$now`. A igual timestamp, gana lexicográficamente el código de causa para evitar depender del orden de llegada. Sin entradas, el total es cero y `jobs=[]`. El resumen no contiene IDs de jobs ni evidencia suficiente para operar una cola, y nunca implica `operational_ready`.

## Validación y límites

El test PHPUnit `symfony/tests/php/JobFailureDiagnosticsTest.php` usa fixtures sintéticos sin red: agrupa, verifica antigüedad, máximo de intentos, orden, empates, lista vacía, límite de volumen y rechazo de secretos/PII. Ejecutar desde `symfony/`:

```sh
php bin/phpunit tests/php/JobFailureDiagnosticsTest.php
```

CI debe completar `symfony-preview` y el `validate` agregado sobre HEAD exacto. Cualquier conexión a colas reales, telemetría, hosting o interfaces queda fuera de #668 y requiere autorización/alcance separado.
