#!/usr/bin/env python3
"""Prove release ZIP equality across LF and CRLF working-tree checkouts."""

from __future__ import annotations

import hashlib
from pathlib import Path
import subprocess
import sys
import tempfile


def run(*args: str, cwd: Path | None = None) -> None:
    subprocess.run(args, cwd=cwd, check=True)


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    source = Path(subprocess.check_output(["git", "rev-parse", "--show-toplevel"], text=True).strip())
    commit = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=source, text=True).strip()
    with tempfile.TemporaryDirectory(prefix="discussionbridge-wp-release-") as temporary:
        root = Path(temporary)
        outputs = []
        for name, autocrlf in (("lf", "false"), ("crlf", "true")):
            checkout = root / name
            run("git", "clone", "--quiet", "--no-hardlinks", str(source), str(checkout))
            run("git", "config", "core.autocrlf", autocrlf, cwd=checkout)
            run("git", "reset", "--hard", "--quiet", commit, cwd=checkout)
            output = root / f"{name}.zip"
            run(sys.executable, "scripts/build-release.py", "--treeish", commit, "--output", str(output), cwd=checkout)
            outputs.append(output)

        hashes = [digest(path) for path in outputs]
        if len(set(hashes)) != 1:
            raise RuntimeError(f"Release ZIPs differ: {hashes}")
        print(f"Reproducible release SHA-256: {hashes[0]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
