"""Publish a reviewed source snapshot through the explicitly authenticated gh account."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

from build import ROOT, source_files

REPO = "crowveil/xboard-subscription-bridge"
URL = f"https://github.com/{REPO}.git"
NAME = "crowveil"
EMAIL = "330225440+crowveil@users.noreply.github.com"


class PublishError(RuntimeError):
    pass


def run(args, cwd=ROOT, check=True):
    result = subprocess.run(
        args,
        cwd=cwd,
        text=True,
        capture_output=True,
        env={**os.environ, "GH_HOST": "github.com", "GH_PROMPT_DISABLED": "1"},
    )
    if check and result.returncode:
        detail = (
            result.stderr.strip()
            or result.stdout.strip()
            or f"exit {result.returncode}"
        )
        raise PublishError(f"命令失败（{args[0]}）：{detail}")
    return result


def gh(*args, check=True):
    return run(["gh", *args], check=check)


def git(repo, *args, check=True):
    # Empty helper first: do not reuse another account's cached Git credentials.
    return run(
        [
            "git",
            "-c",
            "credential.helper=",
            "-c",
            "credential.https://github.com.helper=!gh auth git-credential",
            "-c",
            "core.hooksPath=/dev/null",
            *args,
        ],
        cwd=repo,
        check=check,
    )


def account():
    login = gh(
        "api", "--hostname", "github.com", "user", "--jq", ".login"
    ).stdout.strip()
    if login != NAME:
        raise PublishError("gh 当前实际账号不是 crowveil；已停止，不会自动切换账号。")
    gh("auth", "status", "--hostname", "github.com")


def identity(repo):
    for key, expected in (("user.name", NAME), ("user.email", EMAIL)):
        if git(repo, "config", "--local", key).stdout.strip() != expected:
            raise PublishError("仓库本地 Git 身份不符合 crowveil 规则。")
    for key in ("GIT_AUTHOR_IDENT", "GIT_COMMITTER_IDENT"):
        if not git(repo, "var", key).stdout.startswith(f"{NAME} <{EMAIL}> "):
            raise PublishError("环境变量覆盖了 Git 作者或提交者；请清除覆盖后重试。")


def remote(repo):
    for args in (
        ("remote", "get-url", "origin"),
        ("remote", "get-url", "--push", "origin"),
    ):
        if git(repo, *args).stdout.strip() != URL:
            raise PublishError("origin 或 URL 重写规则不符合目标 HTTPS 仓库，已停止。")


def api_optional(path):
    result = gh("api", "--hostname", "github.com", path, check=False)
    if result.returncode == 0:
        return json.loads(result.stdout)
    if "HTTP 404" in result.stderr:
        return None
    raise PublishError(f"无法确认 GitHub 状态：{result.stderr.strip()}")


def check_tag(repo, tag, commit):
    ref = api_optional(f"repos/{REPO}/git/ref/tags/{tag}")
    if ref is None:
        return False
    obj = ref["object"]
    # Resolve annotated tags, including nested annotations, without changing refs.
    for _ in range(8):
        if obj["type"] == "commit":
            if obj["sha"] != commit:
                raise PublishError("同名远端标签指向不同提交；不会覆盖已发布版本。")
            return True
        if obj["type"] != "tag":
            break
        data = gh(
            "api", "--hostname", "github.com", f"repos/{REPO}/git/tags/{obj['sha']}"
        )
        obj = json.loads(data.stdout)["object"]
    raise PublishError("远端标签无法解析为预期提交。")


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def release_assets(tag, assets, published):
    listing = json.loads(
        gh("release", "view", tag, "--repo", REPO, "--json", "assets").stdout
    )["assets"]
    expected = {path.name: path for path in assets}
    actual = {item["name"]: item for item in listing}
    if set(actual) - set(expected):
        raise PublishError("Release 包含未预期的附件，已停止，请手动核对。")
    with tempfile.TemporaryDirectory(prefix="bridge-assets-") as directory:
        for name, path in expected.items():
            if name in actual:
                gh(
                    "release",
                    "download",
                    tag,
                    "--repo",
                    REPO,
                    "--pattern",
                    name,
                    "--dir",
                    directory,
                )
                if sha256(Path(directory) / name) != sha256(path):
                    raise PublishError(f"已有附件 {name} 与本地构建不同；不会覆盖。")
            elif published:
                raise PublishError(
                    "已公开的 Release 缺少附件；不会自动修改已发布版本。"
                )
            else:
                account()
                gh("release", "upload", tag, str(path), "--repo", REPO)


def prepare_snapshot(destination):
    paths = source_files()
    wanted = {path.relative_to(ROOT).as_posix() for path in paths}
    tracked = git(destination, "ls-files", "-z").stdout.split("\0")
    for name in filter(None, tracked):
        if name not in wanted:
            (destination / name).unlink()
    for path in paths:
        target = destination / path.relative_to(ROOT)
        target.parent.mkdir(parents=True, exist_ok=True)
        # Source ZIPs have normalized modes; keep snapshot modes deterministic.
        shutil.copyfile(path, target)
        target.chmod(0o644)
    identity(destination)
    git(destination, "add", "--all")
    git(destination, "diff", "--cached", "--check")


def publish(check_only=False):
    for command in ("git", "gh"):
        if shutil.which(command) is None:
            raise PublishError(f"缺少 {command}，请先安装。")
    manifest = json.loads((ROOT / "ExternalNodeBridge/config.json").read_text())
    version = manifest["version"]
    if (
        not re.fullmatch(r"\d+\.\d+\.\d+", version)
        or manifest["code"] != "external_node_bridge"
    ):
        raise PublishError("版本或插件标识不正确。")
    base = (ROOT / "RELEASE_BASE").read_text().strip()
    if not re.fullmatch(r"[0-9a-f]{40}", base):
        raise PublishError("RELEASE_BASE 必须是上一个版本的完整提交 SHA。")
    tag = f"v{version}"
    notes = ROOT / f"docs/releases/{tag}.md"
    if not notes.is_file():
        raise PublishError(f"缺少发布说明 docs/releases/{tag}.md。")
    account()
    metadata = json.loads(
        gh("repo", "view", REPO, "--json", "nameWithOwner,defaultBranchRef").stdout
    )
    if (
        metadata["nameWithOwner"] != REPO
        or metadata["defaultBranchRef"]["name"] != "main"
    ):
        raise PublishError("远端仓库或默认分支不是预期的 crowveil 仓库 / main。")
    # Detect global insteadOf rewrites before the first Git network request.
    if git(ROOT, "ls-remote", "--get-url", URL).stdout.strip() != URL:
        raise PublishError("Git URL 被全局规则重写，已停止；脚本不会修改全局设置。")
    print(f"已确认 GitHub 账号 crowveil，目标 {REPO}，版本 {tag}。", flush=True)
    print(run([sys.executable, "tools/build.py", "--check"]).stdout, end="")

    # Work in a fresh clone: downloaded source archives need no .git directory,
    # and the user's existing checkout / remotes / credentials are untouched.
    with tempfile.TemporaryDirectory(prefix="bridge-publish-") as directory:
        checkout = Path(directory) / "repo"
        git(
            Path(directory),
            "clone",
            "--branch",
            "main",
            "--single-branch",
            "--no-tags",
            URL,
            str(checkout),
        )
        for key, value in (
            ("user.name", NAME),
            ("user.email", EMAIL),
            ("commit.gpgsign", "false"),
            ("tag.gpgsign", "false"),
        ):
            git(checkout, "config", "--local", key, value)
        identity(checkout)
        remote(checkout)
        # Never import unpublished local histories or silently rewrite remote history.
        print(
            run(
                [
                    sys.executable,
                    str(ROOT / "tools/check_identity.py"),
                    "--repo",
                    str(checkout),
                    "--history",
                ]
            ).stdout,
            end="",
        )
        head = git(checkout, "rev-parse", "HEAD").stdout.strip()
        prepare_snapshot(checkout)
        changed = bool(git(checkout, "diff", "--cached", "--name-only").stdout.strip())
        if changed and head != base:
            raise PublishError(
                "远端 main 已变化，与 RELEASE_BASE 不一致；请先整合更新，不会覆盖远端。"
            )
        if not changed and head != base:
            parents = git(checkout, "show", "-s", "--format=%P", "HEAD").stdout.strip()
            if parents != base:
                raise PublishError("远端历史不符合本版本的续传条件，请手动检查。")
        if changed and api_optional(f"repos/{REPO}/git/ref/tags/{tag}") is not None:
            raise PublishError("版本标签已存在，但源码仍有变化；请使用新的版本号。")
        print(git(checkout, "log", "-1", "--format=fuller").stdout)
        print("请检查下面的实际暂存差异，确认不包含真实凭据或私人信息：")
        print(
            git(checkout, "diff", "--cached", "--no-ext-diff", "--no-textconv").stdout,
            flush=True,
        )
        print(
            run(
                [sys.executable, "tools/build.py", "--check", "--package"], cwd=checkout
            ).stdout,
            end="",
        )
        if check_only:
            if not changed:
                check_tag(checkout, tag, head)
            print("检查完成。尚未提交、推送、创建标签或 Release。")
            return
        if (
            input(f"将发布 {REPO} {tag}；确认上述差异后输入 publish：").strip()
            != "publish"
        ):
            raise PublishError("已取消发布。")
        account()
        identity(checkout)
        if changed:
            git(
                checkout,
                "commit",
                "-m",
                f"Release {tag}: native console link and publishing workflow",
            )
        head = git(checkout, "rev-parse", "HEAD").stdout.strip()
        identity(checkout)
        print(git(checkout, "log", "-1", "--format=fuller").stdout)
        tag_exists = check_tag(checkout, tag, head)
        if not tag_exists:
            git(
                checkout,
                "tag",
                "-a",
                tag,
                "-m",
                f"XBoard Subscription Bridge {version}",
            )
            tagger = git(
                checkout,
                "for-each-ref",
                "--format=%(taggername) %(taggeremail)",
                f"refs/tags/{tag}",
            ).stdout.strip()
            if tagger != f"{NAME} <{EMAIL}>":
                raise PublishError("标签作者不符合 crowveil 身份。")
            account()
            identity(checkout)
            remote(checkout)
            git(
                checkout,
                "push",
                "--atomic",
                "origin",
                "HEAD:refs/heads/main",
                f"refs/tags/{tag}:refs/tags/{tag}",
            )
        release = api_optional(f"repos/{REPO}/releases/tags/{tag}")
        dist = checkout / "dist" / version
        assets = [
            dist / f"ExternalNodeBridge-{version}.zip",
            dist / f"xboard-subscription-bridge-{version}-source.zip",
            dist / "SHA256SUMS",
        ]
        if release is None:
            account()
            identity(checkout)
            gh(
                "release",
                "create",
                tag,
                "--repo",
                REPO,
                "--verify-tag",
                "--draft",
                "--title",
                f"XBoard Subscription Bridge {version}",
                "--notes-file",
                str(notes),
            )
            release = {"draft": True}
        release_assets(tag, assets, published=not release["draft"])
        # Re-download all assets after uploading; publish only a complete, matching set.
        if release["draft"]:
            release_assets(tag, assets, published=True)
            account()
            identity(checkout)
            gh("release", "edit", tag, "--repo", REPO, "--draft=false", "--latest")
        print(f"发布完成：https://github.com/{REPO}/releases/tag/{tag}")


def main():
    parser = argparse.ArgumentParser(
        description="检查身份、审阅差异并推送 main、版本标签和 Release。"
    )
    parser.add_argument(
        "--check", action="store_true", help="只检查和构建，不提交、不推送、不发布"
    )
    args = parser.parse_args()
    try:
        publish(args.check)
    except (
        PublishError,
        OSError,
        ValueError,
        KeyError,
        EOFError,
        KeyboardInterrupt,
    ) as error:
        print(f"停止：{error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
