#!/usr/bin/env python3
"""Emit only allowlisted module/media-tool statuses from authenticated Admin System HTML.

Never emit arbitrary page content, paths, CSRF values, cookies, or error details.
"""
from __future__ import annotations

import re
import sys
from html.parser import HTMLParser

MODULES = {"vault", "scheduling", "distribution", "traffic", "finance"}
TOOLS = {"ffmpeg", "ffprobe"}
MODULE_STATES = {"ready", "migration-required", "unknown"}
TOOL_STATES = {"disabled", "binary-found", "binary-missing"}
MAX_HTML = 1_000_000
STATUS = re.compile(r"([a-z]+):([a-z-]+)")


class ReadinessParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.modules: dict[str, str] = {}
        self.tools: dict[str, str] = {}
        self.invalid = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        for attr, expected, states, destination in (
            ("data-module-readiness", MODULES, MODULE_STATES, self.modules),
            ("data-media-tool", TOOLS, TOOL_STATES, self.tools),
        ):
            if attr not in values:
                continue

            match = STATUS.fullmatch(values[attr] or "")
            if match is None:
                self.invalid = True
                continue

            name, state = match.groups()
            if name not in expected or state not in states or name in destination:
                self.invalid = True
            else:
                destination[name] = state


def main() -> int:
    try:
        page = sys.stdin.read(MAX_HTML + 1)
        if len(page) > MAX_HTML:
            raise ValueError
        parsed = ReadinessParser()
        parsed.feed(page)
        parsed.close()
    except (UnicodeError, ValueError):
        print("ERROR: runtime readiness inventory cannot be verified.", file=sys.stderr)
        return 2

    if parsed.invalid or set(parsed.modules) != MODULES or set(parsed.tools) != TOOLS:
        print("ERROR: runtime readiness inventory cannot be verified.", file=sys.stderr)
        return 2

    # Deliberately emit nothing before the complete inventory passes validation.
    for name in sorted(MODULES):
        print(f"MODULE_SCHEMA_{name.upper()}={parsed.modules[name]}")
    for name in sorted(TOOLS):
        print(f"MEDIA_TOOL_{name.upper()}={parsed.tools[name]}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
