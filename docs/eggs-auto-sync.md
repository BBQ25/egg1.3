# Eggs Auto Sync

`eggs.ryhnsolutions.shop` is deployed by a site-specific server script:

- script: `scripts/eggs-auto-sync.sh`
- source repo: `https://github.com/BBQ25/egg1.3.git`
- branch: `main`

Intended workflow:

1. Edit locally in VS Code.
2. Test locally.
3. Commit and push to `origin/main`.
4. GitHub sends a signed webhook to `/ops/deploy/github`.
5. The server starts the sync script immediately.
5. Refresh `https://eggs.ryhnsolutions.shop`.

The script is designed to touch only the `eggs.ryhnsolutions.shop` site:

- builds from a separate cache under `/www/git-cache/egg1.3`
- keeps the live `.env`
- leaves `storage/` alone
- updates code, `vendor/`, and `public/build/`
- runs `php artisan migrate --force`
- clears Laravel caches after deployment

Webhook setup:

- payload URL: `https://eggs.ryhnsolutions.shop/ops/deploy/github`
- content type: `application/json`
- event: `Just the push event`
- branch filter is enforced server-side as `main`
- secret: must match `EGGS_DEPLOY_WEBHOOK_SECRET` in the live `.env`

Notes:

- The webhook route only accepts signed requests and only triggers deploys for `BBQ25/egg1.3` pushes to `main`.
- Future manual hotfixes made only on the server can be overwritten by the next GitHub sync.
- The safe source of truth is the local repo plus GitHub.
