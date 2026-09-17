"""Prepare console assets and build auditable plugin/source archives."""

import argparse
import hashlib
import fnmatch
import json
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "ExternalNodeBridge"
ROOT_FILES = {
    ".editorconfig",
    ".gitattributes",
    ".gitignore",
    ".prettierignore",
    ".prettierrc.json",
    "AGENTS.md",
    "LICENSE",
    "README.md",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "phpunit.xml",
    "pint.json",
    "upgrade.php",
}
SOURCE_DIRS = {"ExternalNodeBridge", "deploy", "docs", "tests", "tools", ".github"}
EXCLUDED = {"runtime", "__pycache__", "node_modules", "vendor", ".local"}
SUFFIXES = {
    ".php",
    ".js",
    ".cjs",
    ".css",
    ".html",
    ".json",
    ".py",
    ".md",
    ".yaml",
    ".yml",
    ".toml",
}


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def source_files():
    files = []
    for path in sorted(ROOT.rglob("*")):
        relative = path.relative_to(ROOT)
        if relative.parts[0] not in SOURCE_DIRS and str(relative) not in ROOT_FILES:
            continue
        if any(
            fnmatch.fnmatch(path.name, pattern)
            for pattern in (
                ".env*",
                "credentials*",
                "secrets*",
                "config.local.*",
                "id_rsa*",
                "id_ed25519*",
            )
        ):
            continue
        if EXCLUDED.intersection(relative.parts) or not path.is_file():
            continue
        if path.is_symlink():
            raise ValueError(f"Symbolic links are not release inputs: {relative}")
        if (
            str(relative) in ROOT_FILES
            or path.suffix in SUFFIXES
            or path.name == "LICENSE"
        ):
            files.append(path)
    return files


def archive(destination, paths, prefix=""):
    with zipfile.ZipFile(destination, "w", zipfile.ZIP_DEFLATED) as output:
        for path in paths:
            name = str(Path(prefix) / path.relative_to(ROOT))
            entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            output.writestr(entry, path.read_bytes())
    with zipfile.ZipFile(destination) as output:
        if output.testzip() is not None:
            raise ValueError(f"Invalid ZIP: {destination.name}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--check", action="store_true", help="Check asset hashes without writing"
    )
    parser.add_argument(
        "--package", action="store_true", help="Also create release ZIP files"
    )
    args = parser.parse_args()
    manifest = json.loads((PLUGIN / "config.json").read_text())
    version = manifest["version"]
    if manifest["code"] != "external_node_bridge" or not re.fullmatch(
        r"\d+\.\d+\.\d+", version
    ):
        raise ValueError("Invalid plugin identity or release version")
    html_path = PLUGIN / "resources/assets/console.html"
    original = html_path.read_text()
    html = original
    for name in ("console.css", "console.js"):
        query = digest(html_path.parent / name)[:12]
        html, count = re.subn(
            re.escape(name) + r"\?v=[^\"]+", f"{name}?v={query}", html
        )
        if count != 1:
            raise ValueError(f"Expected exactly one asset reference: {name}")
    if args.check and html != original:
        raise SystemExit("Console hashes are stale; run python3 tools/build.py")
    if not args.check:
        html_path.write_text(html)
    print(f"Console assets verified for {version}")
    if not args.package:
        return
    destination = ROOT / "dist" / version
    destination.mkdir(parents=True, exist_ok=True)
    paths = source_files()
    plugin_paths = [path for path in paths if path.is_relative_to(PLUGIN)]
    install = destination / f"ExternalNodeBridge-{version}.zip"
    source = destination / f"xboard-subscription-bridge-{version}-source.zip"
    archive(install, plugin_paths)
    archive(source, paths, f"xboard-subscription-bridge-{version}")
    sums = "".join(f"{digest(path)}  {path.name}\n" for path in (install, source))
    (destination / "SHA256SUMS").write_text(sums)
    print(sums, end="")


if __name__ == "__main__":
    main()
