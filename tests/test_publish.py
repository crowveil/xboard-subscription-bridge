"""Offline release workflow tests: real local Git, simulated GitHub responses."""

import contextlib
import io
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import build
import publish


def result(args=(), stdout="", code=0, stderr=""):
    return subprocess.CompletedProcess(args, code, stdout, stderr)


class PublishTests(unittest.TestCase):
    def test_wrong_account_stops_before_auth_status_or_writes(self):
        with patch.object(
            publish, "gh", return_value=result(stdout="another-account\n")
        ) as gh:
            with self.assertRaisesRegex(publish.PublishError, "不是 crowveil"):
                publish.account()
            self.assertEqual(gh.call_count, 1)

    def test_wrong_identity_and_remote_are_rejected(self):
        with patch.object(publish, "git", return_value=result(stdout="unexpected")):
            with self.assertRaises(publish.PublishError):
                publish.identity(Path("."))
            with self.assertRaises(publish.PublishError):
                publish.remote(Path("."))

    def test_conflicting_tag_is_never_overwritten(self):
        with patch.object(
            publish,
            "api_optional",
            return_value={"object": {"type": "commit", "sha": "1" * 40}},
        ):
            with self.assertRaisesRegex(publish.PublishError, "不会覆盖"):
                publish.check_tag(Path("."), "v0.1.1", "2" * 40)

    def test_source_export_checks_publishes_and_resumes_without_rewriting(self):
        original_git = publish.git
        original_root = build.ROOT
        inputs = build.source_files()
        with tempfile.TemporaryDirectory(prefix="bridge-release-test-") as temporary:
            base_dir = Path(temporary)
            source = base_dir / "source"
            source.mkdir()
            for path in inputs:
                target = source / path.relative_to(original_root)
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(path, target)
            seed = base_dir / "seed"
            seed.mkdir()
            for path in inputs:
                target = seed / path.relative_to(original_root)
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(path, target)
            original_git(seed, "init", "--initial-branch=main")
            for key, value in (
                ("user.name", publish.NAME),
                ("user.email", publish.EMAIL),
                ("commit.gpgsign", "false"),
            ):
                original_git(seed, "config", "--local", key, value)
            publish.identity(seed)
            (seed / "README.md").write_text("Synthetic previous release\n")
            original_git(seed, "add", "--all")
            original_git(seed, "commit", "-m", "Synthetic release base")
            base = original_git(seed, "rev-parse", "HEAD").stdout.strip()
            (source / "RELEASE_BASE").write_text(base + "\n")
            bare = base_dir / "remote.git"
            original_git(base_dir, "clone", "--bare", str(seed), str(bare))
            state = {"release": None, "assets": {}, "interrupt": True, "pushes": 0}

            def local_git(cwd, *args, **kwargs):
                if args[:2] == ("ls-remote", "--get-url") or args[:2] == (
                    "remote",
                    "get-url",
                ):
                    return result(stdout=publish.URL + "\n")
                args = list(args)
                if args[0] == "clone":
                    args[args.index(publish.URL)] = str(bare)
                elif args[0] == "push":
                    self.assertIn("--atomic", args)
                    self.assertNotIn("--force", args)
                    args[args.index("origin")] = str(bare)
                    state["pushes"] += 1
                return original_git(cwd, *args, **kwargs)

            def fake_gh(*args, check=True):
                if args[:3] == ("api", "--hostname", "github.com"):
                    endpoint = args[3]
                    if endpoint == "user":
                        return result(stdout=publish.NAME + "\n")
                    if "/git/ref/tags/" in endpoint:
                        tag = endpoint.rsplit("/", 1)[1]
                        ref = original_git(
                            bare,
                            "rev-parse",
                            "--verify",
                            f"refs/tags/{tag}^{{commit}}",
                            check=False,
                        )
                        if ref.returncode:
                            return result(code=1, stderr="HTTP 404")
                        return result(
                            stdout=json.dumps(
                                {
                                    "object": {
                                        "type": "commit",
                                        "sha": ref.stdout.strip(),
                                    }
                                }
                            )
                        )
                    if "/releases/tags/" in endpoint:
                        return (
                            result(stdout=json.dumps(state["release"]))
                            if state["release"]
                            else result(code=1, stderr="HTTP 404")
                        )
                if args[:2] == ("auth", "status"):
                    return result()
                if args[:2] == ("repo", "view"):
                    return result(
                        stdout=json.dumps(
                            {
                                "nameWithOwner": publish.REPO,
                                "defaultBranchRef": {"name": "main"},
                            }
                        )
                    )
                if args[:2] == ("release", "view"):
                    return result(
                        stdout=json.dumps(
                            {"assets": [{"name": name} for name in state["assets"]]}
                        )
                    )
                if args[:2] == ("release", "create"):
                    self.assertIn("--verify-tag", args)
                    self.assertIn("--draft", args)
                    state["release"] = {"draft": True}
                    return result()
                if args[:2] == ("release", "upload"):
                    if state["interrupt"] and len(state["assets"]) == 1:
                        state["interrupt"] = False
                        raise publish.PublishError("synthetic interruption")
                    file = Path(args[3])
                    state["assets"][file.name] = file.read_bytes()
                    return result()
                if args[:2] == ("release", "download"):
                    name = args[args.index("--pattern") + 1]
                    directory = Path(args[args.index("--dir") + 1])
                    (directory / name).write_bytes(state["assets"][name])
                    return result()
                if args[:2] == ("release", "edit"):
                    self.assertEqual(len(state["assets"]), 3)
                    state["release"]["draft"] = False
                    return result()
                raise AssertionError(f"Unexpected simulated gh call: {args}")

            with patch.object(publish, "ROOT", source), patch.object(
                build, "ROOT", source
            ), patch.object(publish, "git", side_effect=local_git), patch.object(
                publish, "gh", side_effect=fake_gh
            ), patch.object(
                publish.shutil, "which", return_value="synthetic-command"
            ), patch(
                "builtins.input", return_value="publish"
            ), contextlib.redirect_stdout(
                io.StringIO()
            ):
                publish.publish(check_only=True)
                self.assertEqual(state["pushes"], 0)
                self.assertIsNone(state["release"])
                with self.assertRaisesRegex(
                    publish.PublishError, "synthetic interruption"
                ):
                    publish.publish()
                self.assertTrue(state["release"]["draft"])
                published_commit = original_git(
                    bare, "rev-parse", "main"
                ).stdout.strip()
                publish.publish()
                self.assertFalse(state["release"]["draft"])
                publish.publish()
                self.assertEqual(state["pushes"], 1)
                self.assertEqual(
                    published_commit,
                    original_git(bare, "rev-parse", "main").stdout.strip(),
                )
                self.assertEqual(
                    base, original_git(bare, "rev-parse", "main^").stdout.strip()
                )
                self.assertEqual(len(state["assets"]), 3)
                state["assets"]["SHA256SUMS"] = b"different published content"
                with self.assertRaisesRegex(publish.PublishError, "不会覆盖"):
                    publish.publish()


if __name__ == "__main__":
    unittest.main()
