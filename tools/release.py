"""GitHub Actions release worker; invoked only after successful Tests."""

import hashlib
import json
import os
from pathlib import Path
import re
import sys
import tempfile

from publish import EMAIL, NAME, REPO, ROOT, PublishError, api_optional, gh, git, identity, remote

ORIGINAL_V011 = "01eabc2eccc89e068a486980c08e2b2e4def2391"


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def resolve_tag(ref):
    obj = ref["object"]
    for _ in range(8):
        if obj["type"] == "commit":
            return obj["sha"]
        if obj["type"] != "tag":
            break
        obj = json.loads(gh("api", f"repos/{REPO}/git/tags/{obj['sha']}").stdout)["object"]
    raise PublishError("Cannot resolve release tag.")


def validate_request(env):
    version = env.get("INPUT_VERSION", "")
    commit = env.get("INPUT_COMMIT", "")
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise PublishError("Invalid release version.")
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise PublishError("Expected commit must be a full SHA.")
    if (
        env.get("GITHUB_REPOSITORY") != REPO
        or env.get("GITHUB_ACTOR") != NAME
        or env.get("GITHUB_TRIGGERING_ACTOR") != NAME
        or env.get("GITHUB_REF") != "refs/heads/main"
        or env.get("GITHUB_SHA") != commit
        or env.get("GITHUB_EVENT_NAME") != "workflow_dispatch"
    ):
        raise PublishError("Unexpected repository, actor, event, branch or commit.")
    confirmation = env.get("INPUT_CONFIRMATION")
    repair = version == "0.1.1" and confirmation == "REPUBLISH v0.1.1"
    if not repair and confirmation != f"PUBLISH v{version}":
        raise PublishError("Release confirmation does not match.")
    return version, commit, repair


def check_tag_target(ref, commit, repair):
    if ref is None:
        if repair:
            raise PublishError("Repair requires the original tag.")
        return
    target = resolve_tag(ref)
    allowed = {commit, ORIGINAL_V011} if repair else {commit}
    if target not in allowed:
        raise PublishError("Tag points to another commit; refusing to overwrite it.")


def require_tests(commit):
    runs = json.loads(gh(
        "api", "--method", "GET", f"repos/{REPO}/actions/workflows/test.yml/runs",
        "-f", f"head_sha={commit}", "-f", "event=push", "-f", "per_page=100",
    ).stdout)["workflow_runs"]
    matches = [r for r in runs if r["head_sha"] == commit
               and r["head_branch"] == "main" and r["event"] == "push"]
    if not matches or max(matches, key=lambda r: r["id"])["conclusion"] != "success":
        raise PublishError("Latest Tests run for this exact main commit has not succeeded.")


def sync_assets(tag, assets, replace, published):
    release = json.loads(gh("release", "view", tag, "--repo", REPO, "--json", "assets").stdout)
    actual = {a["name"] for a in release["assets"]}
    wanted = {p.name for p in assets}
    if actual - wanted:
        raise PublishError("Release contains unexpected assets; review before continuing.")
    with tempfile.TemporaryDirectory(prefix="bridge-release-") as directory:
        for path in assets:
            downloaded = Path(directory) / path.name
            if path.name in actual:
                gh("release", "download", tag, "--repo", REPO,
                   "--pattern", path.name, "--dir", directory)
                if sha256(downloaded) == sha256(path):
                    continue
                if not replace:
                    raise PublishError(f"Existing asset differs: {path.name}")
            elif published and not replace:
                raise PublishError(f"Published release is missing asset: {path.name}")
            args = ["release", "upload", tag, str(path), "--repo", REPO]
            if replace:
                args.append("--clobber")
            gh(*args)
            gh("release", "download", tag, "--repo", REPO,
               "--pattern", path.name, "--dir", directory, "--clobber")
            if sha256(downloaded) != sha256(path):
                raise PublishError(f"Uploaded asset failed verification: {path.name}")


def release():
    version, commit, repair = validate_request(os.environ)
    manifest = json.loads((ROOT / "ExternalNodeBridge/config.json").read_text())
    if manifest["version"] != version or manifest["code"] != "external_node_bridge":
        raise PublishError("Manifest does not match the requested release.")
    if git(ROOT, "rev-parse", "HEAD").stdout.strip() != commit:
        raise PublishError("Checkout does not match the tested commit.")
    tag = f"v{version}"
    notes = ROOT / "docs/releases" / f"{tag}.md"
    if not notes.is_file():
        raise PublishError("Release notes are missing.")
    require_tests(commit)
    for key, value in (("user.name", NAME), ("user.email", EMAIL),
                       ("commit.gpgsign", "false"), ("tag.gpgsign", "false")):
        git(ROOT, "config", "--local", key, value)
    identity(ROOT)
    remote(ROOT)
    head_identity = git(ROOT, "log", "-1", "--format=%an|%ae|%cn|%ce").stdout.strip()
    if head_identity != f"{NAME}|{EMAIL}|{NAME}|{EMAIL}":
        raise PublishError("Release commit identity does not match crowveil.")
    ref = api_optional(f"repos/{REPO}/git/ref/tags/{tag}")
    check_tag_target(ref, commit, repair)
    existing = api_optional(f"repos/{REPO}/releases/tags/{tag}")
    if repair and existing is None:
        raise PublishError("Repair requires the existing Release.")
    if existing and existing.get("immutable"):
        raise PublishError("GitHub release is immutable; publish a new version instead.")

    from build import main as build
    original_args = sys.argv
    try:
        sys.argv = ["build.py", "--check", "--package"]
        build()
    finally:
        sys.argv = original_args
    if git(ROOT, "status", "--porcelain", "--untracked-files=no").stdout.strip():
        raise PublishError("Build modified tracked sources.")

    # Preserve an already-correct annotated tag during retries.
    if ref is None or resolve_tag(ref) != commit:
        identity(ROOT)
        remote(ROOT)
        git(ROOT, "tag", "--force", "--annotate", tag,
            "--message", f"XBoard Subscription Bridge {version}", commit)
        old_object = ref["object"]["sha"] if ref else ""
        git(ROOT, "push", f"--force-with-lease=refs/tags/{tag}:{old_object}",
            "origin", f"refs/tags/{tag}:refs/tags/{tag}")

    if existing is None:
        gh("release", "create", tag, "--repo", REPO, "--verify-tag", "--draft",
           "--title", f"XBoard Subscription Bridge {version}", "--notes-file", str(notes))
        existing = {"draft": True}
    dist = ROOT / "dist" / version
    assets = [dist / f"ExternalNodeBridge-{version}.zip",
              dist / f"xboard-subscription-bridge-{version}-source.zip", dist / "SHA256SUMS"]
    sync_assets(tag, assets, replace=repair, published=not existing["draft"])
    if repair or existing["draft"]:
        identity(ROOT)
        gh("release", "edit", tag, "--repo", REPO, "--draft=false", "--latest",
           "--title", f"XBoard Subscription Bridge {version}", "--notes-file", str(notes))
    print(f"Verified release: https://github.com/{REPO}/releases/tag/{tag}")


if __name__ == "__main__":
    try:
        release()
    except (PublishError, OSError, ValueError, KeyError) as error:
        sys.exit(str(error))
