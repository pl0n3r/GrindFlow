#!/usr/bin/env python3
"""Create byte-exact legal handoff copies; --check is strictly read-only."""
from __future__ import annotations
import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = [["aviso-privacidad.md","Aviso de privacidad y autorización"],["canal-derechos.md","Canal de solicitudes de derechos"],["operacion-derechos-incidentes.md","Runbook de derechos e incidentes"],["politica-tratamiento.md","Política de tratamiento de datos"],["registro-tratamientos.md","Registro técnico de tratamientos"],["retencion.md","Tabla de plazos de conservación"],["terminos-condiciones.md","Términos y condiciones del servicio"]]
TEMPLATE = "Última actualización automática de las fuentes: {day} · SHA de origen: `{sha}`\n\n# Documentos para revisión jurídica · GrindFlow\n\n**Estado: `documented_not_legally_approved`.** Este paquete NO constituye aprobación legal ni autoriza datos reales, publicación o paso a live.\n\n## Procedencia\n\n- Proyecto: `pl0n3r/GrindFlow` · versión declarada: `{version}`.\n- Fuente: `docs/privacidad/` y `datos.yml`; último commit que modificó esas fuentes: `{sha}` ({day}).\n- Las copias de los siete documentos son byte-exactas; no se editan en esta carpeta. Las correcciones del abogado deben aplicarse primero a las fuentes.\n\n## Documentos para el abogado\n\n| Archivo | Qué contiene / qué debe confirmar o corregir el abogado |\n| --- | --- |\n{document_rows}| `paquete-completo.md` | Concatenación ordenada de todos los textos anteriores, para entregar un solo archivo. |\n\n## Hechos del responsable que siguen pendientes\n\n- [ ] Nombre o razón social del responsable\n- [ ] Identificación o NIT\n- [ ] Dirección física y domicilio\n- [ ] Canal/correo de ejercicio de derechos\n- [ ] Finalidades, fundamentos jurídicos y consentimientos\n- [ ] Retención, transferencias y contratos con encargados\n- [ ] Condiciones comerciales, jurisdicción y mecanismos de reclamación\n\n## Proveedores o subprocesadores declarados\n\nEstos identificadores figuran en `datos.yml`, sin afirmar que estén operativos. El abogado debe comprobar uso efectivo, contratos y transferencias:\n\n{provider_rows}\n## Mantenimiento\n\nEjecutar `python3 scripts/generar_revision_juridica.py` para generar desde las fuentes; usar `--check` para validar sin escrituras. El workflow de `main` detecta desfases y abre un Issue deduplicado para que los agentes los corrijan por PR; nunca escribe directamente en `main`.\n"
DEST = "revision-juridica"


def origin(root: Path) -> tuple[str, str]:
    """Use full Git history; shallow acceptance uses index provenance and checks source bytes."""
    shallow = subprocess.run(
        ["git", "rev-parse", "--is-shallow-repository"],
        cwd=root, capture_output=True, text=True, check=True, timeout=20,
    ).stdout.strip()
    if shallow == "true":
        # A depth-1 checkout treats the synthetic PR merge as a root commit:
        # 'git log -- docs/privacidad' would falsely attribute old files to it.
        index = (root / DEST / "00-LEEME.md").read_text(encoding="utf-8")
        match = re.match(
            r"Última actualización automática de las fuentes: (\\d{4}-\\d\\d-\\d\\d) · SHA de origen: `([0-9a-f]{40})`",
            index,
        )
        if not match:
            raise ValueError("Proveniencia ausente en checkout superficial")
        return match.group(2), match.group(1)
    raw = subprocess.run(
        ["git", "log", "-1", "--format=%H%n%cs", "--", "docs/privacidad", "datos.yml"],
        cwd=root, capture_output=True, text=True, check=True, timeout=20,
    ).stdout.strip().splitlines()
    if len(raw) != 2 or not re.fullmatch(r"[0-9a-f]{40}", raw[0]) or not re.fullmatch(r"\\d{4}-\\d\\d-\\d\\d", raw[1]):
        raise ValueError("SHA y fecha fuente faltantes o inválidos")
    return raw[0], raw[1]

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
    """Return stale names, writing differences only when explicitly generating."""
    sha, day = origin(root)
    output = render(root, sha, day)
    differences = []
    for name, expected in output.items():
        target = root / DEST / name
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
    except (ValueError, OSError, TypeError, KeyError, subprocess.SubprocessError) as exc:
        print(f"Paquete jurídico inválido: {exc}", file=sys.stderr)
        return 2
    if differences:
        print("Documentos desactualizados: " + ", ".join(differences))
    return 1 if args.check and differences else 0


if __name__ == "__main__":
    raise SystemExit(main())
