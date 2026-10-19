#!/usr/bin/env python3
"""Create byte-exact legal handoff copies; --check is strictly read-only."""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = [["aviso-privacidad.md","Aviso de privacidad y autorización"],["canal-derechos.md","Canal de solicitudes de derechos"],["operacion-derechos-incidentes.md","Runbook de derechos e incidentes"],["politica-tratamiento.md","Política de tratamiento de datos"],["registro-tratamientos.md","Registro técnico de tratamientos"],["retencion.md","Tabla de plazos de conservación"],["terminos-condiciones.md","Términos y condiciones del servicio"]]
TEMPLATE = "Última actualización automática de las fuentes: {day} · SHA-256 de origen: `{sha}`\n\n# Documentos para revisión jurídica · GrindFlow\n\n**Estado: `documented_not_legally_approved`.** Este paquete NO constituye aprobación legal ni autoriza datos reales, publicación o paso a live.\n\n## Procedencia\n\n- Proyecto: `pl0n3r/GrindFlow` · versión declarada: `{version}`.\n- Fuentes: `docs/privacidad/`, `datos.yml` y `config/version.php`; huella SHA-256 verificable: `{sha}`. Fecha de generación del contenido: {day}. La huella se calcula sobre bytes y rutas de origen, no sobre el commit del paquete; permanece válida después de squash.\n- Las copias de los siete documentos son byte-exactas; no se editan en esta carpeta. Las correcciones del abogado deben aplicarse primero a las fuentes.\n\n## Documentos para el abogado\n\n| Archivo | Qué contiene / qué debe confirmar o corregir el abogado |\n| --- | --- |\n{document_rows}| `paquete-completo.md` | Concatenación ordenada de todos los textos anteriores, para entregar un solo archivo. |\n\n## Hechos del responsable que siguen pendientes\n\n- [ ] Nombre o razón social del responsable\n- [ ] Identificación o NIT\n- [ ] Dirección física y domicilio\n- [ ] Canal/correo de ejercicio de derechos\n- [ ] Finalidades, fundamentos jurídicos y consentimientos\n- [ ] Retención, transferencias y contratos con encargados\n- [ ] Condiciones comerciales, jurisdicción y mecanismos de reclamación\n\n## Proveedores o subprocesadores declarados\n\nEstos identificadores figuran en `datos.yml`, sin afirmar que estén operativos. El abogado debe comprobar uso efectivo, contratos y transferencias:\n\n{provider_rows}\n## Mantenimiento\n\nEjecutar `python3 scripts/generar_revision_juridica.py` para generar desde las fuentes; usar `--check` para validar sin escrituras. El workflow de `main` detecta desfases y abre un Issue deduplicado para que los agentes los corrijan por PR; nunca escribe directamente en `main`.\n"
DEST = "revision-juridica"


def origin(root: Path) -> tuple[str, str]:
    """SHA-256 of canonical sources, independent of Git history and squash.

    The index date is informational and reused only when its fingerprint
    matches the source bytes. It never authenticates the source fingerprint.
    """
    source_paths = sorted(
        ["datos.yml", "config/version.php"]
        + ["docs/privacidad/" + name for name, _ in FILES]
    )
    digest = hashlib.sha256()
    for rel in source_paths:
        digest.update(rel.encode("utf-8") + b"\0")
        digest.update((root / rel).read_bytes())
        digest.update(b"\0")
    fingerprint = digest.hexdigest()
    index_path = root / DEST / "00-LEEME.md"
    if index_path.is_file() and not index_path.is_symlink():
        header = index_path.read_text(encoding="utf-8").split("\n", 1)[0]
        match = re.fullmatch(
            r"Última actualización automática de las fuentes: (\d{4}-\d{2}-\d{2}) · SHA-256 de origen: `([0-9a-f]{64})`",
            header,
        )
        if match and match.group(2) == fingerprint:
            return fingerprint, match.group(1)
    return fingerprint, datetime.now(timezone.utc).date().isoformat()

def version(root: Path) -> str:
    """Read the declared product version without executing PHP."""
    text = (root / "config/version.php").read_text(encoding="utf-8")
    match = re.search(r"'number'\s*=>\s*'([^']+)'", text)
    if match is None:
        raise ValueError("Versión del producto no declarada")
    return match.group(1)


def render(root: Path, sha: str, day: str) -> dict[str, bytes]:
    """Build the entire deterministic package from canonical source bytes."""
    data = json.loads((root / "datos.yml").read_text(encoding="utf-8"))
    providers = sorted({name for item in data["treatments"] for name in item.get("providers", [])})
    if not all(isinstance(p, str) and p for p in providers):
        raise ValueError("Proveedor inválido")
    doc_rows = "".join(
        f"| `{name}` | {description}; validar suficiencia jurídica, exactitud y redacción de `docs/privacidad/{name}`. |\n"
        for name, description in FILES
    )
    provider_rows = "".join(
        f"- `{p}`: declarado; uso efectivo y base contractual pendientes de confirmar.\n"
        for p in providers
    )
    index = (TEMPLATE.replace("{day}", day)
             .replace("{sha}", sha).replace("{version}", version(root))
             .replace("{document_rows}", doc_rows).replace("{provider_rows}", provider_rows))
    result = {"00-LEEME.md": index.encode("utf-8")}
    package = "# Paquete completo de revisión jurídica · GrindFlow\n\nEstado: `documented_not_legally_approved` (sin aprobación jurídica).\n\n".encode("utf-8")
    for name, _ in FILES:
        content = (root / "docs/privacidad" / name).read_bytes()
        result[name] = content
        package += f"---\n\n## docs/privacidad/{name}\n\n".encode("utf-8") + content + b"\n\n"
    result["paquete-completo.md"] = package
    return result


def sync(root: Path, check: bool) -> list[str]:
    """Return stale names; reject foreign entries/links before any file writes."""
    sha, day = origin(root)
    output = render(root, sha, day)
    folder = root / DEST
    # Fail closed on an unsafe handoff directory or any ungenerated content.
    # Never auto-delete an unexpected entry or follow a link outside the folder.
    if folder.is_symlink() or (folder.exists() and not folder.is_dir()):
        raise ValueError("Carpeta jurídica inválida: no es directorio regular")
    if folder.is_dir():
        unknown = sorted(
            path.relative_to(folder).as_posix()
            for path in folder.rglob("*")
            if path.is_symlink() or path.is_dir()
            or path.parent != folder or path.name not in output
        )
        if unknown:
            raise ValueError("Entradas no previstas en carpeta jurídica: " + ", ".join(unknown))
    differences = []
    for name, expected in output.items():
        target = folder / name
        if not target.is_file() or target.read_bytes() != expected:
            differences.append(name)
            if not check:
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(expected)
    return differences


def main() -> int:
    """Fail closed on missing or stale copies and malformed provenance."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    try:
        differences = sync(ROOT, args.check)
    except (ValueError, OSError, TypeError, KeyError) as exc:
        print(f"Paquete jurídico inválido: {exc}", file=sys.stderr)
        return 2
    if differences:
        print("Documentos desactualizados: " + ", ".join(differences))
    return 1 if args.check and differences else 0


if __name__ == "__main__":
    raise SystemExit(main())
