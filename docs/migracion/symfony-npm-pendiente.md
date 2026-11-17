# Recuperación de los ajustes npm retirados de PR #951

**Incidente de coordinación:** [GrindFlow #1038](https://github.com/pl0n3r/GrindFlow/issues/1038).  
**PR que conserva la migración:** [#951](https://github.com/pl0n3r/GrindFlow/pull/951) (`codex-symfony-retirement`, DRAFT).  
**PR que necesita las rutas npm:** [#995](https://github.com/pl0n3r/GrindFlow/pull/995), hoja [#994](https://github.com/pl0n3r/GrindFlow/issues/994).

## Alcance y motivo

El PR #951 agregó cambios de configuración de Node a `symfony/package.json` y `symfony/package-lock.json`. Como #995 necesita actualizar esas mismas rutas con reserva V3 propia para React y Playwright, la coordinación rechaza el solapamiento. El OWNER autorizó en #1038 retirar **solo** esos dos archivos del diff de #951, sin cerrar ni rebasar la migración ni modificar #995.

## Delta a conservar para una hoja posterior

| Archivo original en #951 | Delta retirado | Reintroducción condicionada |
| --- | --- | --- |
| `symfony/package.json` | `scripts.check = "npm run typecheck && npm run build"` | Restablecer el script tras fusionar #995, con pruebas de typecheck y build |
| `symfony/package.json` | `engines.node = ">=22 <23"` | Revisar compatibilidad con el Node objetivo real de CI/Hostinger; no introducir sin prueba |
| `symfony/package-lock.json` | `packages[""].engines.node = ">=22 <23"` | Regenerar/validar el lockfile junto al manifiesto, sin alterar versiones de otras dependencias |

El contenido de ambos archivos se restablece **exactamente** al árbol de `main` observado en el preflight, no se sustituyen por los del PR #995 y no se altera ningún archivo adicional de la migración. Cuando #995 termine, la futura hoja deberá inspeccionar el `main` **nuevo**, proponer solo el delta todavía necesario, reclamar sus propias rutas y reserva V3, probar `npm ci`, typecheck y build y cumplir los gates de seguridad y coordinación.

## Límites de evidencia

Este cambio documental y la extracción del diff de #951 solo desbloquean una **colisión de archivos** de GitHub. No acreditan CI verde de #995, aceptación de su contrato, compatibilidad real del runtime Symfony, Hostinger, despliegue, `/health` exacto ni producción. #951 sigue DRAFT, no cambia la autoridad de cutover y no se ejecutan proveedores, secretos ni datos reales.

**Reversión:** deshacer el commit puntual de extracción en `codex-symfony-retirement` y revertir esta documentación si se cancela #1038. Toda reintroducción necesita coordinación y autorización independientes.

## Reconciliación posterior a la liberación de rutas — 2026-11-17

- **Dependencia desbloqueada y fusionada:** [PR #995](https://github.com/pl0n3r/GrindFlow/pull/995), que cierra [#994](https://github.com/pl0n3r/GrindFlow/issues/994), fue fusionado por squash el 2026-11-17T01:15:50Z en `main@35f716e997624082fb449f524644ca6da6cf00c0`. El resultado de [Coordinación #43989068145](https://github.com/pl0n3r/GrindFlow/actions/runs/43989068145), intento 2, acreditó `validar-pr / Validar coordinación=success` sobre HEAD `3f9ca6094f5d5be6304dd06e35f0b40797cc9528` de #995; no equivale por sí mismo a los demás checks.
- **Alcance preservado:** el PR #951 continúa DRAFT, sin los dos paths npm en su diff; la hoja #1038 conserva únicamente este documento. No se reintroduce aquí `scripts.check`, `engines.node` ni el bloque del lockfile; una futura modificación requiere claim V3 propio y pruebas tras el merge de #995.
- **Evidencia de #1039 sobre HEAD previo `00178d835b17bec2477c5153a3e09bc75aa785cf`:** `validate`, `Factory CI reusable / Validar`, `validar-pr / Validar coordinación`, `CodeQL`, `SonarCloud` y `acceptance` completaron en `success`. `Factory policy` quedó `failure` (precondición de timeline de revisión); cualquier nuevo HEAD debe revalidarse y no hereda estos checks. Esto documenta un desbloqueo de coordinación, no un despliegue ni un estado verde de producción.
