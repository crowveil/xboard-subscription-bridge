"""Check repository-local and effective Git identities before crowveil releases."""

import argparse
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NAME = "crowveil"
EMAIL = "330225440+crowveil@users.noreply.github.com"


def git(*args):
    return subprocess.check_output(["git", "-C", str(ROOT), *args], text=True).strip()


def main():
    global ROOT
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, help="Check a separate release checkout")
    parser.add_argument(
        "--history", action="store_true", help="Check all commits reachable from HEAD"
    )
    args = parser.parse_args()
    if args.repo:
        ROOT = args.repo.resolve()
    if (
        git("config", "--local", "user.name") != NAME
        or git("config", "--local", "user.email") != EMAIL
    ):
        raise SystemExit("Repository-local Git identity does not match crowveil")
    expected = f"{NAME} <{EMAIL}> "
    for variable in ("GIT_AUTHOR_IDENT", "GIT_COMMITTER_IDENT"):
        if not git("var", variable).startswith(expected):
            raise SystemExit(f"Effective {variable} does not match crowveil")
    if args.history:
        for row in git("log", "--format=%an%x09%ae%x09%cn%x09%ce", "HEAD").splitlines():
            if row.split("\t") != [NAME, EMAIL, NAME, EMAIL]:
                raise SystemExit(
                    "Commit history contains an unexpected author or committer"
                )
    print("Verified local author and committer: crowveil (noreply)")
    print(
        "Check the authenticated GitHub account and remote separately before publishing."
    )


if __name__ == "__main__":
    main()
