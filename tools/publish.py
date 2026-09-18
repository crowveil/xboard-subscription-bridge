"""Push a reviewed source snapshot and delegate verification/release to Actions."""

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time

from build import ROOT, source_files

REPO = "crowveil/xboard-subscription-bridge"
URL = f"https://github.com/{REPO}.git"
NAME = "crowveil"
EMAIL = "330225440+crowveil@users.noreply.github.com"
BRANCH = "main"
REPAIR_VERSION = "0.1.1"


class PublishError(RuntimeError):
    pass


def run(args, cwd=None, check=True):
    result = subprocess.run(
        args,
        cwd=cwd or ROOT,
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
        shutil.copyfile(path, target)
        target.chmod(0o644)
    identity(destination)
    git(destination, "add", "--all")
    git(destination, "diff", "--cached", "--check")


def workflow_runs(workflow, commit, event):
    result = gh(
        "run",
        "list",
        "--repo",
        REPO,
        "--workflow",
        workflow,
        "--event",
        event,
        "--commit",
        commit,
        "--limit",
        "30",
        "--json",
        "databaseId,headBranch,headSha,status,conclusion",
    )
    return json.loads(result.stdout)


def wait_for_tests(commit, attempts=60, delay=5):
    for _ in range(attempts):
        candidates = [
            item
            for item in workflow_runs("test.yml", commit, "push")
            if item["headBranch"] == BRANCH and item["headSha"] == commit
        ]
        if candidates:
            run_id = str(candidates[0]["databaseId"])
            print(f"等待 main 测试：run {run_id}", flush=True)
            watch(run_id)
            return run_id
        time.sleep(delay)
    raise PublishError("等待 main 测试工作流创建超时。")


def watch(run_id):
    print(f"https://github.com/{REPO}/actions/runs/{run_id}", flush=True)
    code = subprocess.call(
        ["gh", "run", "watch", str(run_id), "--repo", REPO, "--exit-status"],
        env={**os.environ, "GH_HOST": "github.com", "GH_PROMPT_DISABLED": "1"},
    )
    if code:
        raise PublishError(f"Actions run {run_id} 未成功；请查看上方链接，不会继续发布。")


def dispatch_release(version, commit, replace_existing, attempts=60, delay=5):
    current = api_optional(f"repos/{REPO}/git/ref/heads/main")
    if current is None or current["object"]["sha"] != commit:
        raise PublishError("main 在测试期间已变化；不会发布其他提交。")
    before = {
        item["databaseId"]
        for item in workflow_runs("release.yml", commit, "workflow_dispatch")
    }
    tag = f"v{version}"
    confirmation = f"REPUBLISH {tag}" if replace_existing else f"PUBLISH {tag}"
    account()
    gh(
        "workflow",
        "run",
        "release.yml",
        "--repo",
        REPO,
        "--ref",
        BRANCH,
        "-f",
        f"version={version}",
        "-f",
        f"confirmation={confirmation}",
        "-f",
        f"expected_commit={commit}",
    )
    for _ in range(attempts):
        candidates = [
            item
            for item in workflow_runs("release.yml", commit, "workflow_dispatch")
            if item["databaseId"] not in before and item["headSha"] == commit
        ]
        if candidates:
            run_id = str(candidates[0]["databaseId"])
            print(f"等待 GitHub 发布：run {run_id}", flush=True)
            watch(run_id)
            return run_id
        time.sleep(delay)
    raise PublishError("等待 Release 工作流创建超时。")


def validate_release_state(version, replace_existing, check_only=False):
    tag = f"v{version}"
    tag_ref = api_optional(f"repos/{REPO}/git/ref/tags/{tag}")
    release = api_optional(f"repos/{REPO}/releases/tags/{tag}")
    if replace_existing:
        if version != REPAIR_VERSION:
            raise PublishError("只允许对 v0.1.1 执行一次性修正；其他版本必须递增版本号。")
        if tag_ref is None or release is None:
            raise PublishError("v0.1.1 的标签或 Release 不存在，不能执行修正发布。")
    elif not check_only and release is not None and not release.get("draft", False):
        raise PublishError("版本已经发布；请递增版本号，不会覆盖旧版本。")


def publish(check_only=False, replace_existing=False):
    print("publish.sh：GitHub Actions 发布模式；本机无需 PHP、Composer、Node 或 npm。", flush=True)
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
        raise PublishError("RELEASE_BASE 必须是本版本所基于的远端 main 完整提交 SHA。")
    notes = ROOT / f"docs/releases/v{version}.md"
    if not notes.is_file():
        raise PublishError(f"缺少发布说明 {notes.relative_to(ROOT)}。")

    account()
    metadata = json.loads(
        gh("repo", "view", REPO, "--json", "nameWithOwner,defaultBranchRef").stdout
    )
    if (
        metadata["nameWithOwner"] != REPO
        or metadata["defaultBranchRef"]["name"] != BRANCH
    ):
        raise PublishError("远端仓库或默认分支不是预期的 crowveil 仓库 / main。")
    if git(ROOT, "ls-remote", "--get-url", URL).stdout.strip() != URL:
        raise PublishError("Git URL 被全局规则重写，已停止；脚本不会修改全局设置。")
    validate_release_state(version, replace_existing, check_only)
    print(run([sys.executable, "tools/build.py", "--check"]).stdout, end="")

    with tempfile.TemporaryDirectory(prefix="bridge-publish-") as directory:
        checkout = Path(directory) / "repo"
        git(
            Path(directory),
            "clone",
            "--branch",
            BRANCH,
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

        remote_head = git(checkout, "rev-parse", "HEAD").stdout.strip()
        prepare_snapshot(checkout)
        changed = bool(
            git(checkout, "diff", "--cached", "--name-only").stdout.strip()
        )
        if changed and remote_head != base:
            raise PublishError(
                "远端 main 已变化，与 RELEASE_BASE 不一致；请先整合更新。"
            )
        if not changed and remote_head != base:
            parent = git(checkout, "show", "-s", "--format=%P", "HEAD").stdout.strip()
            if parent != base:
                raise PublishError("远端历史不符合本次发布的中断恢复条件。")

        existing_tag = api_optional(f"repos/{REPO}/git/ref/tags/v{version}")
        if existing_tag is not None and not replace_existing and not check_only:
            from release import resolve_tag
            if changed or resolve_tag(existing_tag) != remote_head:
                raise PublishError("已有标签不对应当前源码，不能继续；请递增版本号。")

        print(git(checkout, "log", "-1", "--format=fuller").stdout)
        print("请检查下面的实际暂存差异，确认不包含真实凭据或私人信息：")
        print(
            git(
                checkout,
                "diff",
                "--cached",
                "--no-ext-diff",
                "--no-textconv",
            ).stdout,
            flush=True,
        )
        if check_only:
            print("检查完成；未推送代码，也未触发 GitHub Actions 发布。")
            return

        tag = f"v{version}"
        expected = f"REPUBLISH {tag}" if replace_existing else f"PUBLISH {tag}"
        if input(f"确认后请输入 {expected}：").strip() != expected:
            raise PublishError("确认文字不匹配，已取消。")

        account()
        identity(checkout)
        if changed:
            subject = (
                f"Repair {tag}: move release verification to GitHub Actions"
                if replace_existing
                else f"Prepare {tag} release"
            )
            git(checkout, "commit", "--no-gpg-sign", "-m", subject)
            identity(checkout)
            print(git(checkout, "log", "-1", "--format=fuller").stdout)
            account()
            remote(checkout)
            git(checkout, "push", "origin", f"HEAD:refs/heads/{BRANCH}")

        commit = git(checkout, "rev-parse", "HEAD").stdout.strip()
        identity(checkout)
        wait_for_tests(commit)
        dispatch_release(version, commit, replace_existing)
        print(f"发布完成：https://github.com/{REPO}/releases/tag/{tag}")


def main():
    parser = argparse.ArgumentParser(
        description="审阅并推送源码，由 GitHub Actions 测试、打包和发布。"
    )
    parser.add_argument(
        "--check", action="store_true", help="只检查身份和差异，不进行远端写操作"
    )
    parser.add_argument(
        "--repair-0.1.1",
        dest="replace_existing",
        action="store_true",
        help="仅用于修正已经发布的 v0.1.1",
    )
    args = parser.parse_args()
    try:
        publish(args.check, args.replace_existing)
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
