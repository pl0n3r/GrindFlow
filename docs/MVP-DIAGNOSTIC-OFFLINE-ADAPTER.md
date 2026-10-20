# GF #524 · Adaptador diagnóstico sintético offline V1

## Alcance y frontera de confianza

La función GrindFlow\Observability\DiagnosticOfflineAdapter::fromSyntheticFixture()
es un adaptador **puro, offline y solo de fixtures sintéticos**. Conecta
metadatos acotados al contrato ya fusionado de DiagnosticReceipt::fromTrustedMetadata().
No abre sockets, no consulta HTTP, base de datos, procesos, archivos operativos,
proveedores ni secretos. No conecta un collector real ni habilita producción.

El caller aporta provenance="synthetic", event_id, kind, state y la correlación
sintética correspondiente. La allowlist tiene exactamente siete claves:
provenance, event_id, kind, state, request_id, job_id, publication_id.
Los identificadores admiten solo los prefijos opacos y hexadecimales ya validados
por DiagnosticReceipt. Un campo extra (message, stack, token, headers, payload,
password, PII o contexto arbitrario) se rechaza con el error constante
INVALID_DIAGNOSTIC_METADATA, sin reflejar información privada.

**La etiqueta synthetic no autentica una fuente.** Esta API solo puede invocarse
con fixtures establecidos en un contexto de pruebas confiable; no debe cablearse
a parámetros HTTP, payloads externos, jobs reales ni datos de terceros. La
futura integración observada necesita un leaf distinto, autorización de origen,
aislamiento tenant, controles de privacidad, señal de frescura y V3 propios.

## Estados y correlación

Se conserva event_id como receipt_id, y las referencias opcionales solo si
fueron aportadas y validadas. Replay idéntico produce recibo idéntico; esta
función no acuña IDs. FAILED se proyecta como INVESTIGATE; UNKNOWN y STALE
se proyectan como BLOCKED, siempre operational_ready=false. HEALTHY, READY y
cualquier otro estado ambiguo se rechazan: no inventar señales de salud.
Los valores null explícitos no se interpretan como omisión de referencias.

## Aceptación reproducible

- AC-01: tests/test_diagnostic_offline_adapter.py::DiagnosticOfflineAdapterTests::test_only_trusted_metadata_reaches_receipt
- AC-02: tests/test_diagnostic_offline_adapter.py::DiagnosticOfflineAdapterTests::test_unknown_stale_and_secret_fields_fail_closed
- AC-03: check validate terminal-success sobre el HEAD exacto de GitHub.

Ejecutar localmente:

    python -m unittest discover -s tests -p test_diagnostic_offline_adapter.py -v

Se necesita PHP CLI real y el contrato DiagnosticReceipt de #519. No se acepta
un skip por ausencia de PHP. La CI debe ejecutar los dos tests sobre fixtures
sintéticos descartables y sin redes externas.

**Reversión:** revertir solo el squash de #524. No existen migraciones, recursos
externos, secretos, release, despliegue ni switches que desactivar.
