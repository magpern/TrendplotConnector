#!/usr/bin/env python3
"""Verify trendplot-connector production release ZIP contents."""
from __future__ import annotations

import sys
import zipfile

PLUGIN_SLUG = "trendplot-connector"

FORBIDDEN_SEGMENTS = frozenset({
    ".git",
    "vendor",
    "node_modules",
    "scripts",
    "tests",
    "docs",
    ".github",
    "build",
    "builds",
    ".phpcs-cache",
    ".phpunit.result.cache",
})

FORBIDDEN_ROOT_FILES = frozenset({
    "composer.json",
    "composer.lock",
    "composer.phar",
    "phpcs.xml.dist",
    "phpunit.xml.dist",
    "README.md",
    "CHANGELOG.md",
    ".gitignore",
    ".distignore",
    ".editorconfig",
})

REQUIRED_ROOT_FILES = frozenset({
    "readme.txt",
    "LICENSE",
    "uninstall.php",
})

REQUIRED_DIRS = frozenset({"src"})

FORBIDDEN_BRAND_TERMS = frozenset({"biopentra"})


def segment_violations(path: str) -> list[str]:
    parts = [p for p in path.split("/") if p]
    hits = []
    for part in parts:
        if part in FORBIDDEN_SEGMENTS:
            hits.append(part)
    if parts and parts[-1] in FORBIDDEN_ROOT_FILES:
        hits.append(parts[-1])
    if parts:
        leaf = parts[-1]
        if leaf.startswith(".env"):
            hits.append(leaf)
        for suffix in (".log", ".sql", ".sql.gz", ".dump", ".sqlite"):
            if leaf.endswith(suffix):
                hits.append(leaf)
    return hits


def verify(zip_path: str, plugin_slug: str = PLUGIN_SLUG) -> int:
    root_prefix = f"{plugin_slug}/"
    main_file = f"{root_prefix}{plugin_slug}.php"

    with zipfile.ZipFile(zip_path) as zf:
        names = [n for n in zf.namelist() if n]

        if not names:
            print("ERROR: zip is empty", file=sys.stderr)
            return 1

        non_root = [n for n in names if not n.startswith(root_prefix)]
        if non_root:
            print(
                f"ERROR: entries must live under {root_prefix}; found: {non_root[:5]}",
                file=sys.stderr,
            )
            return 1

        if main_file not in names:
            print(f"ERROR: missing {main_file}", file=sys.stderr)
            return 1

        # Structural violations
        forbidden_hits: list[str] = []
        for name in names:
            rel = name[len(root_prefix):] if name.startswith(root_prefix) else name
            if not rel:
                continue
            for hit in segment_violations(rel):
                forbidden_hits.append(f"{name} ({hit})")

        if forbidden_hits:
            print("ERROR: zip contains forbidden paths:", file=sys.stderr)
            for line in forbidden_hits[:20]:
                print(f"  - {line}", file=sys.stderr)
            return 1

        # Required dirs
        dir_segments: set[str] = set()
        for name in names:
            rel = name[len(root_prefix):]
            if not rel:
                continue
            dir_segments.add(rel.split("/")[0])

        missing_dirs = REQUIRED_DIRS - dir_segments
        if missing_dirs:
            print(
                f"ERROR: zip missing required directories: {', '.join(sorted(missing_dirs))}",
                file=sys.stderr,
            )
            return 1

        # Required files
        missing_files = [
            req for req in REQUIRED_ROOT_FILES
            if f"{root_prefix}{req}" not in names
        ]
        if missing_files:
            print(
                f"ERROR: zip missing required files: {', '.join(sorted(missing_files))}",
                file=sys.stderr,
            )
            return 1

        # Source files present
        src_entries = sum(1 for n in names if n.startswith(f"{root_prefix}src/"))
        if src_entries < 1:
            print("ERROR: zip has no files under src/", file=sys.stderr)
            return 1

        # Brand term check inside ZIP contents
        brand_violations: list[str] = []
        for name in names:
            try:
                text = zf.read(name).decode("utf-8", errors="replace").lower()
            except Exception:
                continue
            for term in FORBIDDEN_BRAND_TERMS:
                if term in text:
                    brand_violations.append(f"{name} (contains '{term}')")
                    break
        if brand_violations:
            print("ERROR: zip contains forbidden brand terms:", file=sys.stderr)
            for line in brand_violations[:20]:
                print(f"  - {line}", file=sys.stderr)
            return 1

        print(f"OK: {len(names)} entries under {root_prefix}")
        print(f"    main: {main_file}")
        print(f"    src/: {src_entries} paths")
        print(f"    forbidden segments absent: {', '.join(sorted(FORBIDDEN_SEGMENTS))}")
        print(f"    forbidden brand terms absent: {', '.join(sorted(FORBIDDEN_BRAND_TERMS))}")
        return 0


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(f"usage: {sys.argv[0]} ZIP_PATH [PLUGIN_SLUG]", file=sys.stderr)
        return 2
    slug = sys.argv[2] if len(sys.argv) == 3 else PLUGIN_SLUG
    return verify(sys.argv[1], slug)


if __name__ == "__main__":
    sys.exit(main())
