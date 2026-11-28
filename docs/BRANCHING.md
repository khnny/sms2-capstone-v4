# Branch workflow

`main` is the live code. Never edit it directly.

| Branch | Para saan | Sino ang gumagamit |
|---|---|---|
| `main` | Stable, live. HostForge deploys from here. PC and laptop auto-update from here (`tools/update-local.ps1`, every 30 min and at sign-in). | Merge lang galing `develop` (or a `hotfix/` branch) |
| `develop` | Integration and testing. All new work merges here first. | Merge galing feature/fix branches |
| `feature/<name>` | New feature, e.g. `feature/gdrive-data-backup` | Ikaw / Claude |
| `fix/<name>` | Bug fix, e.g. `fix/gdrive-connect-csp` | Ikaw / Claude |
| `hotfix/<name>` | Urgent fix sa live. Start from `main`, PR to `main`, then merge `main` back into `develop`. | Emergency lang |
| `landing-page` | Separate landing site (own history). Huwag i-merge sa main. | |

## Daily flow

1. `git checkout develop && git pull`
2. `git checkout -b feature/my-change`
3. Work, commit, `git push -u origin feature/my-change`
4. Open a PR: `feature/my-change` → `develop`. Wait for CI (green check).
5. Test `develop` on XAMPP (`git checkout develop`). Note: the auto-update skips while you are on a branch other than `main`; that is expected.
6. When `develop` is good, open a PR `develop` → `main` and merge. Live and the PC/laptop get it automatically.

## May problem sa live?

- Revert the bad PR on GitHub (PR page → **Revert**), merge the revert PR into `main`. No need to edit files on `main`.
- Or make `hotfix/<name>` from `main`, fix, PR to `main`.

## Notes

- `tools/git-auto-sync.ps1` (the watcher that auto-commits and pushes) pushes straight to whatever branch is checked out. Do not run it on `main` when branch protection is on; use it only on a feature branch, or turn it off.
- Database patches in `database/patches/` are applied by `update-local.ps1` only when they reach `main`.
