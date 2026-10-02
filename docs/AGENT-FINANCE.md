# GrindFlow — reglas de dominio Finance para agentes

> Extensión normativa de [AGENTS.md](../AGENTS.md). Este documento conserva las
> reglas Finance trasladadas para reducir el contexto de arranque sin cambiar su
> semántica. La precedencia general sigue definida por AGENTS.md y los requisitos
> funcionales canónicos siguen en `docs/REQUIREMENTS.md`.

### Finance: reconciliacion por eventos, no saldo bancario

- La fecha filtrada en Finance es `occurred_on` del asiento: una reversa
  cuenta en SU propia fecha UTC (puede generar neto negativo temporal).
  No presentar esto como conciliacion de pagos bancarios o saldo historico
  sin verificar el periodo completo de vida de los asientos.
- Un SOLO builder tenant-scoped genera ledger paginado, totales por moneda,
  resumen por moneda+beneficiario y CSV de TODOS los grupos coincidentes.
  No sumar monedas distintas ni usar el preview de 25 filas para reportes.
- Preservar currency, beneficiary, from/to validados en enlaces de pagina,
  y excluir otros parametros. Limitar page y no entregar identificadores
  internos, notas, cookies ni nombres de clientes fuera del tenant en CSV.
- Beneficiarios sin miembro activo aparecen como 'Former beneficiary'
  y nulos como 'Organization / unassigned', sin intentar inferirlos de
  records de otra organizacion; un beneficiary_id arbitrario no es filtro
  autorizado. Nombres que comiencen con formula de planilla se prefijan.
- Finance solo Admin/Studio autorizados, GET fallback con schema no migrado,
  descarga 503 hasta schema y encabezados private/no-store/nosniff.
- Nunca reescribir, borrar ni compensar el ledger automaticamente para
  cuadrar un reporte. La gestion de reversas permanece append-only.
### Regla de Finance

- `revenue_allocations` es un ledger tenant-owned **append-only**. No existe
  update/delete funcional; una correccion crea una fila nueva con
  `reversal_of_id`. MariaDB refuerza el contrato con triggers BEFORE UPDATE /
  BEFORE DELETE para que SQL directo tampoco pueda reescribir historia.
- Solo Admin/Studio pueden ver o mutar Finance. Editor/Model no reciben acceso
  por ocultar UI: la autorizacion se repite server-side.
- Los montos se persisten como enteros positivos en `amount_minor`; nunca usar
  float/double para dinero. `currency` es un codigo de tres letras en mayuscula.
- El beneficiario es opcional pero, si existe, debe tener membership en la misma
  organizacion al momento de crear el asiento.
- Cada asiento conserva actor, fecha, fuente y nota. Si un actor/beneficiario se
  elimina, su FK puede quedar null sin reescribir el asiento historico.
- Una reversa copia monto, moneda, fuente y beneficiario del original, exige
  razon y solo puede existir una vez por asiento. Una reversa no se revierte.
- Totales netos se derivan como asignaciones originales menos reversas **por
  moneda**; nunca se suman minor units de currencies distintas y no se persiste
  un balance mutable separado en este slice.
- La migration de Finance es explicita. Antes de aplicarla, el GET explica
  `Migration required` y los writes responden 503 antes del FormRequest.
- Finance core v1 no implementa cobros, payouts bancarios, impuestos, invoices
  ni conciliacion; esos flujos deben vivir detras de este ledger auditable.
