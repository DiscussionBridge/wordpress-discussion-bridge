#!/usr/bin/env python3
"""Build a byte-reproducible WordPress plugin ZIP from committed Git blobs."""

from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import zipfile


PLUGIN_SLUG = "wordpress-discussion-bridge"
FIXED_TIMESTAMP = (1980, 1, 1, 0, 0, 0)
EXCLUDED_FILES = {".gitattributes", ".gitignore"}
EXCLUDED_PREFIXES = (".github/", "scripts/")


def git(*args: str, text: bool = False) -> bytes | str:
    result = subprocess.run(
        ["git", *args],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=text,
    )
    return result.stdout


def committed_files(treeish: str) -> list[str]:
    raw = git("ls-tree", "-r", "-z", "--name-only", treeish)
    assert isinstance(raw, bytes)
    files = []
    for value in raw.split(b"\0"):
        if not value:
            continue
        path = value.decode("utf-8")
        pure = PurePosixPath(path)
        if pure.is_absolute() or ".." in pure.parts or "\\" in path:
            raise ValueError(f"Unsafe repository path: {path}")
        if path in EXCLUDED_FILES or path.startswith(EXCLUDED_PREFIXES):
            continue
        files.append(path)
    return sorted(files)


def blob(treeish: str, path: str) -> bytes:
    value = git("show", f"{treeish}:{path}")
    assert isinstance(value, bytes)
    return value


def plugin_version(treeish: str) -> str:
    source = blob(treeish, "wordpress-discussion-bridge.php").decode("utf-8")
    match = re.search(r"^\s*\*\s*Version:\s*([^\s]+)\s*$", source, re.MULTILINE)
    if not match:
        raise ValueError("Plugin Version header is missing")
    return match.group(1)


def zip_entry(name: str, *, directory: bool) -> zipfile.ZipInfo:
    info = zipfile.ZipInfo(name, FIXED_TIMESTAMP)
    info.create_system = 3
    info.compress_type = zipfile.ZIP_STORED
    info.flag_bits = 0
    mode = 0o40755 if directory else 0o100644
    info.external_attr = mode << 16
    if directory:
        info.external_attr |= 0x10
    return info


def build(treeish: str, output: Path) -> tuple[str, int, int]:
    resolved = str(git("rev-parse", "--verify", f"{treeish}^{{commit}}", text=True)).strip()
    files = committed_files(resolved)
    if "wordpress-discussion-bridge.php" not in files:
        raise ValueError("Plugin bootstrap is missing from the committed tree")

    directories = {PLUGIN_SLUG + "/"}
    for path in files:
        current = PLUGIN_SLUG
        for part in PurePosixPath(path).parts[:-1]:
            current += f"/{part}"
            directories.add(current + "/")

    output.parent.mkdir(parents=True, exist_ok=True)
    temporary = output.with_suffix(output.suffix + ".tmp")
    try:
        with zipfile.ZipFile(temporary, "w", allowZip64=False) as archive:
            for directory in sorted(directories):
                archive.writestr(zip_entry(directory, directory=True), b"")
            for path in files:
                archive.writestr(
                    zip_entry(f"{PLUGIN_SLUG}/{path}", directory=False),
                    blob(resolved, path),
                )
        os.replace(temporary, output)
    finally:
        if temporary.exists():
            temporary.unlink()

    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    return digest, len(files), len(directories)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--treeish", default="HEAD", help="Committed Git revision to package")
    parser.add_argument("--output", type=Path, help="Output ZIP path")
    args = parser.parse_args()

    version = plugin_version(args.treeish)
    output = args.output or Path("dist") / f"{PLUGIN_SLUG}-{version}.zip"
    digest, files, directories = build(args.treeish, output)
    print(f"Built {output} from {args.treeish}")
    print(f"Files: {files}; directories: {directories}; SHA-256: {digest}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
