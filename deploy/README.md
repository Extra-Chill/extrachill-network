# Network deploy

`.github/workflows/deploy.yml` is the **only** workflow in the Extra-Chill org with SSH access to production. It deploys one released component at one version to extrachill.com from that component's GitHub Release asset. Production never builds, packages, or polls.

```
component repo: merge to main
  └─ release.yml@v2 → tag + GitHub Release + ZIP
       └─ repository_dispatch: component-released  (homeboy-action#476)
            └─ extrachill-network / deploy.yml
                 1. shallow-clone the component at its tag (Homeboy needs its homeboy.json)
                 2. attach it to the checked-in project config in deploy/homeboy/
                 3. [gate, once #223 lands] homeboy rig up extrachill-network
                 4. homeboy deploy extrachill-site <component> --version <v>
                 5. homeboy deploy extrachill-site <component> --check → evidence artifact
```

## Checked-in config

`deploy/homeboy/` is a Homeboy config tree. Homeboy reads `$HOME/.config/homeboy` and does not honor `XDG_CONFIG_HOME`, so the workflow copies this tree there on each run:

- `projects/extrachill-site/extrachill-site.json` — server id, base path, path roots, remote logs. Ships with **no component attachments**; the workflow attaches exactly the dispatched component per run.
- `servers/hetzner.json` — host, user, port. `identity_file` is `null`; the key arrives via `ssh-key`.

Nothing in here is secret. `database.name`/`user` are empty and `api.enabled` is false.

## Secrets (org-level, repository access restricted to `extrachill-network`)

| Name | Contents |
|---|---|
| `EXTRACHILL_DEPLOY_SSH_KEY` | Private key for the dedicated `deploy` user on the VPS |
| `EXTRACHILL_DEPLOY_KNOWN_HOSTS` | `known_hosts` line(s) for the server (`ssh-keyscan -H <host>`) |

## VPS side

A dedicated `deploy` user, **not** `opencode`, with write access limited to `wp-content/plugins`, `wp-content/themes`, and `wp-content/mu-plugins` under `/var/www/extrachill.com`. No sudo. `servers/hetzner.json` `user` must match.

## Manual deploy, rollback, dry run

Actions → Deploy → Run workflow:

- **Dry run** (default): `component`, `repository`, `version`, `dry_run: true` — plans, no server contact.
- **Deploy**: same with `dry_run: false`.
- **Rollback**: set `ref` to the prior tag or SHA; `version` is ignored. Runs `--ref <ref> --confirm-dangerous`.

Every run uploads `deploy-evidence-<run_id>` containing Homeboy's structured JSON for the deploy and the post-deploy `--check`.

## Not yet enabled

- **Scheduled `--outdated` catch-up.** The cron trigger exists but exits early. Homeboy compares local `version_targets` against the remote, which needs a checkout per component — 48 clones per tick. Tracked in #229; needs an upstream Homeboy path that compares remote version to the latest GitHub Release without a checkout.
- **Network rig gate** (#223). Placeholder step is in the workflow, commented out.

## First run

1. Create the two secrets and the `deploy` user.
2. Run workflow with `dry_run: true` for one small component (e.g. `extrachill-cache`).
3. Run again with `dry_run: false`. Confirm `--check` in the evidence artifact and zero new fatals in `wp-content/debug.log`.
4. Rollback drill: run with `ref` = the previous tag.
5. Land homeboy-action#476 in a component's `release.yml` caller with `dispatch-repo: Extra-Chill/extrachill-network`.
