# Network deploy

`.github/workflows/deploy.yml` is the **only** workflow in the Extra-Chill org with SSH access to production. It deploys released components to extrachill.com from their GitHub Release assets. Production never builds, packages, or polls; the runner never clones a component.

```
component repo: merge to main
  └─ release.yml@v2 → tag + GitHub Release + ZIP
       └─ repository_dispatch: component-released  (homeboy-action#476)
            └─ extrachill-network / deploy.yml
                 1. [gate, once #223 lands] homeboy rig up extrachill-network
                 2. homeboy deploy extrachill-site <component> --version <v>
                      resolves homeboy.json from the repo at the tag, downloads the
                      release ZIP, scp, extract — no source checkout (homeboy#14782)
                 3. homeboy deploy extrachill-site --check → evidence artifact
```

Every 30 minutes the same workflow runs `homeboy deploy extrachill-site --outdated`: any component whose installed version is behind its latest GitHub Release is deployed. This is the catch-up path if a dispatch was ever missed.

## Checked-in config: `deploy/homeboy/`

A Homeboy config root, selected with `HOMEBOY_CONFIG_ROOT` (homeboy#14783):

| Path | Contents |
|---|---|
| `projects/extrachill-site/extrachill-site.json` | server id, base path, path roots, remote logs, and the 37 deployable attachments (`id` + `remote_path`, `local_path` empty) |
| `servers/hetzner.json` | host, user, port; `identity_file` is `null` |
| `components/<id>.json` | standalone registry: `remote_url` (GitHub) + `remote_path`. The GitHub `remote_url` is what makes checkout-less resolution apply. |

Nothing here is secret. `database.name`/`user` are empty and `api.enabled` is false.

Adding a deployable = one attachment line in the project + one registry file. `tests/deploy-workflow-smoke.php` checks they agree.

## Secrets (org-level, repository access restricted to `extrachill-network`)

| Name | Contents |
|---|---|
| `EXTRACHILL_DEPLOY_SSH_KEY` | Private key for the dedicated `deploy` user on the VPS |
| `EXTRACHILL_DEPLOY_KNOWN_HOSTS` | `known_hosts` line(s) for the server (`ssh-keyscan -H <host>`) |

Homeboy also needs a GitHub token to read `homeboy.json` and release metadata from each component repository; the runner's `GITHUB_TOKEN` covers public and org repos.

## VPS side

A dedicated `deploy` user, **not** `opencode`, with write access limited to `wp-content/plugins`, `wp-content/themes`, and `wp-content/mu-plugins` under `/var/www/extrachill.com`. No sudo. `servers/hetzner.json` `user` must match.

## Manual runs

Actions → Deploy → Run workflow:

| Inputs | Effect |
|---|---|
| `component` + `version`, `dry_run: true` (default) | plan one component at one version, no server contact |
| `component` + `version`, `dry_run: false` | deploy it |
| `component` only | deploy its latest GitHub Release |
| nothing | `--outdated` catch-up across every component |

**Rollback** = deploy the previous version: `component` + the prior `version`. There is no separate rollback mode; Homeboy's `--allow-downgrade` guard applies, so a downgrade prompts for `--allow-downgrade` — add it to the plan step if you need it routinely.

Every run uploads `deploy-evidence-<run_id>` with Homeboy's structured JSON for the deploy and the post-deploy `--check`.

## Not yet enabled

- **Network rig gate** (#223). Placeholder step is in the workflow, commented out.
- **`--outdated` across the project** depends on homeboy#14795 (readiness still blocks on empty `local_path` project-wide; single-component deploys already work). Until it ships, the scheduled run will fail fast at plan time with that message — harmless, and it goes green on the next Homeboy release.

## First run

1. Create the two secrets and the `deploy` user.
2. Run workflow: `component=extrachill-cache`, `version=<latest>`, `dry_run: true`.
3. Same with `dry_run: false`. Confirm `--check` in the evidence artifact and zero new fatals in `wp-content/debug.log`.
4. Rollback drill: previous version.
5. Add `dispatch-repo: Extra-Chill/extrachill-network` to a component's `release.yml` caller (homeboy-action#476).
