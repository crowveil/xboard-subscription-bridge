"""Offline tests for the GitHub-Actions-backed release launcher."""

import json
import contextlib
import io
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import call, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import publish
import build
import release


def result(args=(), stdout="", code=0, stderr=""):
    return subprocess.CompletedProcess(args, code, stdout, stderr)


class PublishTests(unittest.TestCase):
    def test_wrong_account_stops_before_auth_status(self):
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

    def test_existing_release_is_immutable_except_v011_repair(self):
        existing = {"object": {"type": "commit", "sha": "1" * 40}}
        with patch.object(publish, "api_optional", return_value=existing):
            with self.assertRaisesRegex(publish.PublishError, "递增版本号"):
                publish.validate_release_state("0.1.1", False)
            publish.validate_release_state("0.1.1", True)
            with self.assertRaisesRegex(publish.PublishError, "只允许"):
                publish.validate_release_state("0.1.2", True)

    def test_repair_requires_both_tag_and_release(self):
        with patch.object(
            publish, "api_optional", side_effect=[{"object": {}}, None]
        ):
            with self.assertRaisesRegex(publish.PublishError, "不存在"):
                publish.validate_release_state("0.1.1", True)

    def test_wait_for_tests_watches_matching_main_run(self):
        runs = [
            {
                "databaseId": 42,
                "headBranch": "main",
                "headSha": "abc",
                "status": "completed",
                "conclusion": "success",
            }
        ]
        with patch.object(publish, "workflow_runs", return_value=runs), patch.object(
            publish, "watch"
        ) as watch:
            self.assertEqual(publish.wait_for_tests("abc", attempts=1, delay=0), "42")
            watch.assert_called_once_with("42")

    def test_dispatch_uses_exact_repair_confirmation_and_new_run(self):
        old = {
            "databaseId": 10,
            "headBranch": "main",
            "headSha": "abc",
            "status": "completed",
            "conclusion": "success",
        }
        new = dict(old, databaseId=11)
        with patch.object(
            publish, "workflow_runs", side_effect=[[old], [old, new]]
        ), patch.object(publish, "gh", return_value=result()) as gh, patch.object(
            publish, "api_optional", return_value={"object": {"sha": "abc"}}
        ), patch.object(publish, "account"), patch.object(publish, "watch") as watch:
            self.assertEqual(
                publish.dispatch_release(
                    "0.1.1", "abc", True, attempts=1, delay=0
                ),
                "11",
            )
            self.assertIn(
                call(
                    "workflow",
                    "run",
                    "release.yml",
                    "--repo",
                    publish.REPO,
                    "--ref",
                    "main",
                    "-f",
                    "version=0.1.1",
                    "-f",
                    "confirmation=REPUBLISH v0.1.1",
                    "-f",
                    "expected_commit=abc",
                ),
                gh.call_args_list,
            )
            watch.assert_called_once_with("11")

    def test_main_change_stops_dispatch(self):
        with patch.object(publish, "api_optional", return_value={"object": {"sha": "other"}}), patch.object(publish, "gh") as gh:
            with self.assertRaises(publish.PublishError):
                publish.dispatch_release("0.1.1", "abc", True)
            gh.assert_not_called()

    def test_real_git_source_check_failure_and_resume(self):
        original_git = publish.git
        original_root = build.ROOT
        files = build.source_files()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source, seed, bare = root / "source", root / "seed", root / "remote.git"
            for base in (source, seed):
                for file in files:
                    target = base / file.relative_to(original_root)
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(file, target)
            original_git(seed, "init", "--initial-branch=main")
            for key, value in (("user.name", publish.NAME), ("user.email", publish.EMAIL), ("commit.gpgsign", "false")):
                original_git(seed, "config", "--local", key, value)
            (seed / "README.md").write_text("Synthetic old source\n")
            original_git(seed, "add", "--all")
            original_git(seed, "commit", "-m", "Synthetic base")
            base_sha = original_git(seed, "rev-parse", "HEAD").stdout.strip()
            (source / "RELEASE_BASE").write_text(base_sha + "\n")
            original_git(root, "clone", "--bare", str(seed), str(bare))
            pushes = []

            def local_git(cwd, *args, **kwargs):
                if args[:2] in (("ls-remote", "--get-url"), ("remote", "get-url")):
                    return result(stdout=publish.URL + "\n")
                args = list(args)
                if args[0] == "clone":
                    args[args.index(publish.URL)] = str(bare)
                if args[0] == "push":
                    self.assertEqual(args, ["push", "origin", "HEAD:refs/heads/main"])
                    args[1] = str(bare)
                    pushes.append(args)
                return original_git(cwd, *args, **kwargs)

            def fake_gh(*args, **kwargs):
                if args[:2] == ("repo", "view"):
                    return result(stdout=json.dumps({"nameWithOwner": publish.REPO, "defaultBranchRef": {"name": "main"}}))
                raise AssertionError(f"Unexpected gh command: {args}")

            with patch.object(publish, "ROOT", source), patch.object(build, "ROOT", source), patch.object(
                publish, "git", side_effect=local_git
            ), patch.object(publish, "account"), patch.object(publish, "gh", side_effect=fake_gh), patch.object(
                publish, "api_optional", return_value={"draft": False, "object": {"sha": base_sha}}
            ), patch.object(publish.shutil, "which", return_value="available"), patch(
                "builtins.input", return_value="REPUBLISH v0.1.1"
            ), patch.object(publish, "wait_for_tests") as tests, patch.object(
                publish, "dispatch_release"
            ) as dispatch, contextlib.redirect_stdout(io.StringIO()):
                publish.publish(check_only=True)
                self.assertFalse(pushes)
                dispatch.assert_not_called()
                tests.side_effect = publish.PublishError("synthetic failed tests")
                with self.assertRaisesRegex(publish.PublishError, "failed tests"):
                    publish.publish(replace_existing=True)
                self.assertEqual(len(pushes), 1)
                dispatch.assert_not_called()
                head = original_git(bare, "rev-parse", "main").stdout.strip()
                tests.side_effect = None
                dispatch.side_effect = publish.PublishError("synthetic network interruption")
                with self.assertRaisesRegex(publish.PublishError, "network interruption"):
                    publish.publish(replace_existing=True)
                self.assertEqual(len(pushes), 1)
                dispatch.side_effect = None
                publish.publish(replace_existing=True)
                dispatch.assert_called_with("0.1.1", head, True)
                self.assertEqual(len(pushes), 1)
                self.assertEqual(original_git(bare, "rev-parse", "main^").stdout.strip(), base_sha)


class ReleaseTests(unittest.TestCase):
    def test_dispatch_context_rejects_race_and_wrong_actor(self):
        env = dict(INPUT_VERSION="0.1.1", INPUT_COMMIT="a" * 40,
                   INPUT_CONFIRMATION="REPUBLISH v0.1.1", GITHUB_REPOSITORY=publish.REPO,
                   GITHUB_ACTOR="crowveil", GITHUB_TRIGGERING_ACTOR="crowveil",
                   GITHUB_REF="refs/heads/main", GITHUB_SHA="a" * 40,
                   GITHUB_EVENT_NAME="workflow_dispatch")
        self.assertEqual(release.validate_request(env), ("0.1.1", "a" * 40, True))
        for key, value in (("GITHUB_SHA", "b" * 40), ("GITHUB_ACTOR", "other"),
                           ("GITHUB_TRIGGERING_ACTOR", "other"), ("GITHUB_REF", "refs/tags/v0.1.1")):
            with self.subTest(key=key), self.assertRaises(publish.PublishError):
                release.validate_request({**env, key: value})

    def test_repair_cannot_replace_unrelated_tag(self):
        ref = {"object": {"type": "commit", "sha": release.ORIGINAL_V011}}
        release.check_tag_target(ref, "a" * 40, True)
        with self.assertRaises(publish.PublishError):
            release.check_tag_target(ref, "a" * 40, False)
        ref["object"]["sha"] = "b" * 40
        with self.assertRaises(publish.PublishError):
            release.check_tag_target(ref, "a" * 40, True)

    def test_tests_gate_rejects_other_sha_and_later_failed_run(self):
        success = dict(id=1, head_sha="a" * 40, head_branch="main", event="push", conclusion="success")
        for runs in ([], [dict(success, head_sha="b" * 40)], [success, dict(success, id=2, conclusion="failure")]):
            with patch.object(release, "gh", return_value=result(stdout=json.dumps({"workflow_runs": runs}))):
                with self.assertRaises(publish.PublishError):
                    release.require_tests("a" * 40)
        with patch.object(release, "gh", return_value=result(stdout=json.dumps({"workflow_runs": [success]}))):
            release.require_tests("a" * 40)

    def test_asset_upload_interruption_resumes_and_repair_is_explicit(self):
        with tempfile.TemporaryDirectory() as tmp:
            assets = [Path(tmp) / name for name in ("plugin.zip", "source.zip", "SHA256SUMS")]
            for file in assets:
                file.write_bytes(file.name.encode())
            uploaded, writes = {}, []
            interrupt = [True]

            def fake_gh(*args):
                if args[:2] == ("release", "view"):
                    return result(stdout=json.dumps({"assets": [{"name": n} for n in uploaded]}))
                if args[:2] == ("release", "download"):
                    name = args[args.index("--pattern") + 1]
                    (Path(args[args.index("--dir") + 1]) / name).write_bytes(uploaded[name])
                elif args[:2] == ("release", "upload"):
                    if interrupt[0] and len(uploaded) == 1:
                        interrupt[0] = False
                        raise publish.PublishError("interrupted")
                    file = Path(args[3])
                    uploaded[file.name] = file.read_bytes()
                    writes.append(file.name)
                else:
                    raise AssertionError(args)
                return result()

            with patch.object(release, "gh", side_effect=fake_gh):
                with self.assertRaisesRegex(publish.PublishError, "interrupted"):
                    release.sync_assets("v0.1.1", assets, False, False)
                release.sync_assets("v0.1.1", assets, False, False)
                self.assertEqual(len(writes), 3)
                release.sync_assets("v0.1.1", assets, False, True)
                self.assertEqual(len(writes), 3)
                uploaded["plugin.zip"] = b"old"
                with self.assertRaises(publish.PublishError):
                    release.sync_assets("v0.1.1", assets, False, True)
                release.sync_assets("v0.1.1", assets, True, True)
                self.assertEqual(uploaded["plugin.zip"], b"plugin.zip")

    def test_workflow_run_query_is_machine_readable(self):
        payload = [{"databaseId": 1, "headBranch": "main", "headSha": "abc"}]
        with patch.object(
            publish, "gh", return_value=result(stdout=json.dumps(payload))
        ) as gh:
            self.assertEqual(
                publish.workflow_runs("test.yml", "abc", "push"), payload
            )
            self.assertIn("databaseId,headBranch,headSha,status,conclusion", gh.call_args.args)


if __name__ == "__main__":
    unittest.main()
