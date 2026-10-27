"""AC-01..04: contrato offline del guion CORS para operador de GrindFlow #655."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "launch" / "cors-operator-script.md"
SOURCE = ROOT / "docs" / "lista-salida-produccion.md"
ROW = "PUT firmado y preflight OPTIONS desde el origin real"
SECTIONS = (
    "## 1. Preparación y autoridad",
    "## 2. Preflight OPTIONS",
    "## 3. PUT firmado",
    "## 4. Diagnóstico de errores comunes",
    "## 5. Plantilla de recibo sanitizado",
    "## 6. Límite y retorno a la lista de salida",
)


def validate_sections(text):
    offsets = [text.find(title) for title in SECTIONS]
    if any(p < 0 for p in offsets) or offsets != sorted(offsets):
        raise ValueError("faltan secciones obligatorias o están desordenadas")
    if any(text.count(title) != 1 for title in SECTIONS):
        raise ValueError("sección ausente o duplicada")
    for token in (
        "curl --config - --silent --show-error --request OPTIONS",
        "Access-Control-Request-Method: PUT",
        "Access-Control-Request-Headers: content-type",
        "curl --config - --silent --show-error --request PUT",
        "--upload-file",
        "Access-Control-Allow-Origin", "Access-Control-Allow-Methods",
        "Access-Control-Allow-Headers", "OPTIONS 403/405", "PUT 400/403",
        "Medición UTC", "Integridad SHA-256", "--dump-header",
        "curl --config -", "IFS= read -r -s", "unset URL_FIRMADA",
    ):
        if token not in text:
            raise ValueError("falta un elemento del guion: " + token)
    if text.count("~~~sh") != 2 or text.count("~~~text") != 1:
        raise ValueError("plantillas incompletas")


def validate_privacy(text):
    dangerous_patterns = (
        r"(?i)AKIA[0-9A-Z]{16}",
        r"(?i)ASIA[0-9A-Z]{16}",
        r"(?i)-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
        r"(?i)\b(?:secret_access_key|aws_secret_access_key|private_key|access_token)\s*[:=]\s*['\"]?[^<\s'\"]+",
        r"(?im)(?:^|[?&])X-Amz-(?:Signature|Credential|Security-Token)=\S+",
        r"(?i)\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b",
        r"(?i)https?://[^\s'\"]+:[^\s'\"]+@",
    )
    if any(re.search(p, text) for p in dangerous_patterns):
        raise ValueError("posible secreto o dato personal")
    for safe in ("<ORIGIN_HTTPS_REAL_CONFIRMADO>", "<URL_FIRMADA_PUT_EFIMERA_PRIVADA>", "<RUTA_PRIVADA_FIXTURE_JPEG_SIN_PII>"):
        if safe not in text:
            raise ValueError("marcador seguro ausente")
    lines = text.splitlines()
    for start, line in enumerate(lines):
        if not line.lstrip().startswith("curl "):
            continue
        end = start
        while end + 1 < len(lines) and lines[end].rstrip().endswith("\\"):
            end += 1
        tokens = " ".join(
            segment.strip().rstrip("\\") for segment in lines[start:end + 1]
        ).split()
        if any(
            token in ("-v", "--verbose", "--trace", "--trace-ascii")
            or token.startswith("--trace=")
            or token.startswith("--trace-ascii=")
            for token in tokens
        ):
            raise ValueError("curl con salida sensible")


def validate_honesty(text):
    required = (
        "Estado operativo: DEL_DUEÑO",
        "Verificación real: NO ENSAYADO",
        "Origin y bucket: UNKNOWN",
        "Decisión: NO-GO",
        "**PROPUESTA**", "NO EJECUTAR",
        "Resultado hoy: NO ENSAYADO / UNKNOWN / NO-GO",
        "no equivale", "No cambiar",
    )
    for marker in required:
        if marker.lower() not in text.lower():
            raise ValueError("afirmación o límite faltante: " + marker)
    if re.search(r"(?im)(?:verificación real|estado de la prueba|decisión):\s*(?:LISTO|VERIFICADO|GO|SUCCESS)\b", text):
        raise ValueError("estado productivo falsamente acreditado")


def validate_reference(text, source):
    original = next((row for row in source.splitlines() if ROW in row), "")
    if not original or "| DEL_DUEÑO |" not in original:
        raise ValueError("el checklist dejó de señalar la puerta DEL_DUEÑO")
    if "[«" + ROW + "»](../lista-salida-produccion.md)" not in text:
        raise ValueError("enlace a la fila de salida faltante")
    for link in ("../MEDIA-STORAGE-CORS-PREFLIGHT.md", "../AGENT-VAULT-UPLOADS.md", "https://github.com/pl0n3r/GrindFlow/issues/345"):
        if link not in text:
            raise ValueError("sin fuente o evidencia explícita")


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = DOC.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self):
        self.assertTrue(DOC.is_file())
        validate_sections(self.text)
        with self.assertRaises(ValueError):
            validate_sections(self.text.replace(SECTIONS[1], "## Incorrección", 1))

    def test_sin_datos_sensibles(self):
        validate_privacy(self.text)
        for bad in ("AKIA" + "ABCDEFGHIJKLMNOP", "access_token=real123", "persona@example.com", "X-Amz-Signature=abcdef"):
            with self.subTest(sensitive=bad), self.assertRaises(ValueError):
                validate_privacy(self.text + "\n" + bad)
        unsafe = self.text.replace(
            "curl --config - --silent --show-error --request PUT " + chr(92) + "\n",
            "curl --config - --silent --show-error --request PUT " + chr(92) + "\n  -v " + chr(92) + "\n",
            1,
        )
        self.assertNotEqual(unsafe, self.text)
        with self.assertRaises(ValueError):
            validate_privacy(unsafe)

    def test_estado_honesto(self):
        validate_honesty(self.text)
        with self.assertRaises(ValueError):
            validate_honesty(self.text.replace("Verificación real: NO ENSAYADO", "Verificación real: LISTO", 1))
        with self.assertRaises(ValueError):
            validate_honesty(self.text + "\nVerificación real: LISTO\n")

    def test_enlaza_fila_de_salida(self):
        source = SOURCE.read_text(encoding="utf-8")
        validate_reference(self.text, source)
        with self.assertRaises(ValueError):
            validate_reference(self.text.replace("[«" + ROW + "»](../lista-salida-produccion.md)", ROW), source)


if __name__ == "__main__":
    unittest.main()
