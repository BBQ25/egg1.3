# `/serve update` Ops Registry

This file is the safe, committed operations reference for releases. Store sensitive values in `.ops/serve-update.local.json`, which is gitignored.

## Live URLs

- GitHub repository: `https://github.com/BBQ25/egg1.3`
- Release branch: `main`
- Live application: `https://eggs.ryhnsolutions.shop`
- GitHub webhook endpoint: `https://eggs.ryhnsolutions.shop/ops/deploy/github`

## GitHub

- Repository full name: `BBQ25/egg1.3`
- Default remote for releases: `origin`
- Only pushes to `main` are intended to deploy to production.
- The live application accepts signed GitHub `push` webhooks and queues deployment through `/ops/deploy/github`.
- Relevant repo files:
  - `config/deploy.php`
  - `app/Http/Controllers/Ops/GithubDeployWebhookController.php`
  - `scripts/eggs-auto-sync.sh`
  - `scripts/eggs-webhook-dispatch.sh`
  - `scripts/serve-update.ps1`

## Hostinger

- Hostinger is documentation-only for `/serve update`.
- Record account email, panel URL, DNS notes, SSL notes, and domain ownership details in `.ops/serve-update.local.json`.
- The current release workflow does not call Hostinger directly.
- Use this section to track domain and DNS ownership context for `eggs.ryhnsolutions.shop`.

## aaPanel

- aaPanel is the live deployment target.
- Live site root: `/www/wwwroot/eggs.ryhnsolutions.shop`
- Live deploy log: `/www/wwwlogs/eggs-auto-sync.log`
- Git cache root: `/www/git-cache/egg1.3`
- Release cache root: `/www/git-cache/egg1.3-release`
- Deploy state root: `/www/git-cache/egg1.3-state`
- Webhook trigger file: `storage/app/deploy/github-webhook-trigger.json`
- Dispatcher script: `scripts/eggs-webhook-dispatch.sh`
- Sync script: `scripts/eggs-auto-sync.sh`
- The server keeps the live `.env` in the aaPanel site directory and does not replace it during deploys.

## Local Secrets File

- Real local file: `.ops/serve-update.local.json`
- Committed template: `.ops/serve-update.local.example.json`
- Do not commit passwords, tokens, webhook secrets, or SSH private key data.

Expected schema:

```json
{
  "github": {
    "repository": "BBQ25/egg1.3",
    "username": "",
    "contact_email": "",
    "personal_access_token_note": "",
    "webhook_secret_note": ""
  },
  "hostinger": {
    "account_email": "",
    "panel_url": "",
    "primary_domain": "eggs.ryhnsolutions.shop",
    "dns_notes": "",
    "ssl_notes": ""
  },
  "aapanel": {
    "panel_url": "",
    "username": "",
    "password": "",
    "ssh_host": "",
    "ssh_port": 22,
    "ssh_username": "",
    "site_root": "/www/wwwroot/eggs.ryhnsolutions.shop",
    "deploy_log": "/www/wwwlogs/eggs-auto-sync.log",
    "notes": ""
  }
}
```

## `/serve update` Contract

- `/serve update`
  - Run `powershell -ExecutionPolicy Bypass -File scripts/serve-update.ps1`
- `/serve update <message>`
  - Run `powershell -ExecutionPolicy Bypass -File scripts/serve-update.ps1 <message>`
- This is a Codex workflow alias, not a Laravel route and not a firmware command.

The script performs these steps:

1. Verifies the current branch is `main`.
2. Verifies `origin` points to GitHub repository `BBQ25/egg1.3`.
3. Fails if merge, rebase, cherry-pick, or revert state is in progress.
4. Runs `git diff --check`.
5. Runs `php artisan test`.
6. Stages changes with `git add -A`.
7. Unstages known local release-noise paths that should not be published.
8. Exits cleanly when there is nothing to release.
9. Commits with `chore: serve update` unless a custom message is supplied.
10. Pushes to `origin main`.
11. Reports the pushed SHA and reminds the operator that aaPanel deployment should happen through the GitHub webhook.

The release-noise exclusions exist because this repo already contains historical Playwright screenshot assets; `/serve update` must avoid staging fresh local capture noise by default.

## Failure Recovery

- If `php artisan test` fails:
  - Fix the test failures and rerun `/serve update`.
- If push fails:
  - Resolve the Git issue locally before retrying. No live deployment should occur without a successful push.
- If GitHub updates but the live site does not:
  - Check GitHub webhook deliveries for the `push` event.
  - Check the live trigger file at `storage/app/deploy/github-webhook-trigger.json`.
  - Check aaPanel server log `/www/wwwlogs/eggs-auto-sync.log`.
  - Confirm `EGGS_DEPLOY_WEBHOOK_ENABLED=1` and the webhook secret match on the live server.
- If `/serve update` reports nothing to release:
  - Confirm the intended files are not ignored and are inside the repo root.
