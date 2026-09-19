#!/usr/bin/env python3
"""Emit only validated migration filenames and batch fingerprint from Admin System HTML.

Never prints the HTML, authentication cookies, CSRF tokens or operator form values
other than the non-secret, content-derived migration_batch fingerprint.
"""
from __future__ import annotations

import re
import sys
from html.parser import HTMLParser
from pathlib import Path

NAME = re.compile(r"[A-Za-z0-9_]{1,180}\Z")
FINGERPRINT = re.compile(r"[a-f0-9]{64}\Z")
MAX_MIGRATIONS = 200


class MigrationInventory(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.counts: list[str] = []
        self.fingerprints: list[str] = []
        self.names: list[str] = []
        self.inventory = False
        self.inventory_sections = 0
        self.in_li = False
        self.in_code = False
        self.code_count = 0
        self.code_text: list[str] = []
        self.invalid = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        if "data-pending-migrations" in values:
            self.counts.append(values["data-pending-migrations"] or "")
        if tag == "input" and values.get("name") == "migration_batch":
            self.fingerprints.append(values.get("value") or "")
        if tag == "ol" and "data-pending-migration-inventory" in values:
            if self.inventory:
                self.invalid = True
            self.inventory_sections += 1
            self.inventory = True
        elif self.inventory and tag == "li":
            if self.in_li:
                self.invalid = True
            self.in_li = True
            self.code_count = 0
            self.code_text = []
        elif self.in_li and tag == "code":
            if self.in_code:
                self.invalid = True
            self.in_code = True
            self.code_count += 1

    def handle_data(self, data: str) -> None:
        if self.in_code:
            self.code_text.append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag == "code" and self.in_code:
            self.in_code = False
        elif tag == "li" and self.in_li:
            name = "".join(self.code_text).strip()
            if self.code_count != 1 or NAME.fullmatch(name) is None:
                self.invalid = True
            else:
                self.names.append(name)
            self.in_li = False
            self.in_code = False
        elif tag == "ol" and self.inventory:
            if self.in_li:
                self.invalid = True
            self.inventory = False


def main() -> int:
    if len(sys.argv) != 3:
        print("ERROR: migration inventory invocation is invalid.", file=sys.stderr)
        return 2

    try:
        expected = int(sys.argv[2])
        if expected < 1 or expected > MAX_MIGRATIONS:
            raise ValueError
        page = Path(sys.argv[1]).read_text(encoding="utf-8")
        parser = MigrationInventory()
        parser.feed(page)
        parser.close()
    except (OSError, UnicodeError, ValueError):
        print("ERROR: migration inventory cannot be verified.", file=sys.stderr)
        return 2

    if (
        parser.invalid
        or parser.inventory
        or parser.in_li
        or len(parser.counts) != 1
        or parser.counts[0] != str(expected)
        or parser.inventory_sections != 1
        or len(parser.names) != expected
        or len(set(parser.names)) != expected
        or len(parser.fingerprints) != 1
        or FINGERPRINT.fullmatch(parser.fingerprints[0]) is None
    ):
        print("ERROR: migration inventory cannot be verified.", file=sys.stderr)
        return 2

    # Only emit anything after *all* fields have been validated.
    print(f"MIGRATION_BATCH_SHA256={parser.fingerprints[0]}")
    for name in parser.names:
        print(f"MIGRATION_NAME={name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
