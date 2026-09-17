# Project guidance

- Preserve the plugin code `external_node_bridge`, namespace and installation directory `ExternalNodeBridge`, API routes, storage paths and source IDs unless a migration is explicitly requested.
- Keep `ExternalNodeBridge/config.json` as the release version source. Update the changelog for releases and run `python3 tools/build.py` after changing console assets.
- Run PHPUnit and the DOM tests after relevant changes. Use synthetic credentials. Do not reformat or remove attribution from `tests/upstream`.
- Keep real subscriptions, diagnostics, local state, credentials and private identity information out of commits and archives. Review the actual staged diff, not only ignore rules.

## Git identity when acting for crowveil

Use only repository-local `user.name=crowveil` and `user.email=330225440+crowveil@users.noreply.github.com`. Before Git writes, verify both local settings and effective author / committer. Before publishing, inspect commit metadata, staged changes, remote and the authenticated GitHub account. The intended repository is `crowveil/xboard-subscription-bridge`.

Do not change global identity, reuse another identity's signing keys or Git history, force-push, change an existing remote, switch accounts, create credentials or authorize another app without explicit approval. If identity or authentication cannot be verified, stop before publishing and explain the concrete blocker. Preserve third-party attribution.

`python3 tools/check_identity.py --history` checks local metadata; it does not establish GitHub authentication. Other contributors retain their own identities and third-party copyrights.
