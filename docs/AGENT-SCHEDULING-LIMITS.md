# Scheduling Limits: módulo puro de planificación

Issue: GrindFlow #423. Build-ahead reversible: **sin red, persistencia, tokens ni publicaciones**.

Entrada de cada regla: tenant_id, network_id, account_id, scheduled_at_utc estricto YYYY-MM-DDTHH:mm:ssZ. DailyCapRule y BlockedWindowRule requieren una zona horaria IANA explícita. Los registros existentes deben contener ese mismo scope y UTC. Entradas ilegibles o mayores al límite fallan cerradas con invalid_input.

- MinimumSeparationRule::evaluate(candidate, scheduled, minimumMinutes) compara instantes UTC por tenant/red/cuenta; separación exactamente igual al mínimo sí es permitida. Una programación cancelada no consume separación; estados de programación desconocidos se rechazan (fail-closed).
- DailyCapRule::evaluate(candidate, scheduled, maximumPerDay) cuenta **todas** las campañas por la fecha local de la cuenta; solo cancelled se excluye explícitamente.
- BlockedWindowRule::evaluate(candidate, windows, blockedDates) prohíbe fechas y ventanas locales [inicio, fin) con weekday ISO 1–7. Busca hacia delante en UTC al minuto exacto (segundos = 00, sin conservar los segundos del candidato), máximo 14 días; devuelve suggested_at_utc si existe reubicación. No acepta rangos que crucen medianoche: dividirlos en dos ventanas.

El resultado común LimitDecision::toArray() tiene status=permitido|rechazado, reason y suggested_at_utc opcional. Una sugerencia **no constituye una reserva** y debe volver a validarse al confirmar. El Scheduler canónico debe verificar autorización, permisos, elegibilidad, locks, cupos y estado externos por separado antes de persistir. No usar este componente como bypass de ese flujo.

DST: se recibe siempre un instante UTC exacto, nunca una hora local ambigua; el cálculo local con DateTimeZone interpreta ambas ocurrencias del fold y salta los minutos inexistentes del gap. Sin I/O externo ni datos personales en errores.

Pruebas: php symfony/tests/php/SchedulingLimitRulesTest.php o python -m unittest tests/test_scheduling_limit_rules_contract.py.
