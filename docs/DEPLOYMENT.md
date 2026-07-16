# LombokClarion — Deployment Guide

Supporting files already in the repo:
`Dockerfile` (multi-stage: `base` FPM + `worker` + `cloudrun` targets) ·
`docker-compose.yml` (app + nginx + worker + Postgres) · `deploy/nginx.conf` ·
`deploy/lombokclarion-worker.service` · `.github/workflows/ci.yml` ·
`.github/workflows/npm-publish.yml` · `.github/workflows/pages.yml` ·
`.dockerignore` · `.env.example`.

**Cross-target principles (from spec §5–§8):**
1. `php bin/lombokclarion optimize` runs AT BUILD/deploy time — producing
   `storage/services.compiled.php`, `storage/config.compiled.php`, hashed
   `public/assets/*` + manifest. Never commit these artifacts; regenerate each deploy.
2. Run `migrate` under a **higher-privileged DB role** (DDL); the app runtime only
   needs SELECT/INSERT/UPDATE/DELETE (least privilege, §7).
3. `/assets/*` is served by the web server/CDN directly with
   `Cache-Control: immutable` (nginx.conf already does this) —
   `StaticAssetsMiddleware` is a dev fallback only.
4. Secrets (`APP_KEY`, DB credentials) via env/secret manager, never in the repo.
5. Sandbox note: this repo uses an `autoload.php` shim because the build environment
   had no Packagist access. In the real world: remove the shim, run
   `composer install --no-dev --optimize-autoloader`, change
   `require autoload.php` → `vendor/autoload.php` (every per-package composer.json
   is already correct).

---

## 1. GitHub
```bash
cd lombokclarion
git init && git add -A && git commit -m "LombokClarion v1"
gh repo create youruser/lombokclarion --private --source=. --push
```
CI (`.github/workflows/ci.yml`) automatically runs the full test suite, the
domain-boundary check, `optimize`, `audit:security`, and `audit:sql --explain` —
exactly the local quality gates. ColdStartTest is part of the suite, so a
cold-start regression fails CI (§5).
**npm publish**: add the `NPM_TOKEN` secret; publishing triggers on GitHub Release
(quality gate first, version synced from the tag, `--provenance`).
**GitHub Pages**: Settings → Pages → Source = "GitHub Actions"; every push touching
`docs/**` or `README.md` rebuilds and deploys the static docs site.

## 2. VPS (Ubuntu 24.04 — nginx + PHP-FPM + systemd worker)
```bash
sudo apt install -y nginx php8.3-fpm php8.3-sqlite3 php8.3-pgsql git
sudo git clone https://github.com/youruser/lombokclarion /var/www/lombokclarion
cd /var/www/lombokclarion
sudo cp .env.example .env && sudo nano .env        # APP_ENV=production, APP_DEBUG=false, APP_KEY=...
sudo -u www-data php bin/lombokclarion migrate     # ideally with a separate migration DB role
sudo -u www-data php bin/lombokclarion optimize
sudo cp deploy/nginx.conf /etc/nginx/sites-available/lombokclarion
# edit: fastcgi_pass unix:/run/php/php8.3-fpm.sock; root /var/www/lombokclarion/public;
sudo ln -s /etc/nginx/sites-available/lombokclarion /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo cp deploy/lombokclarion-worker.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now lombokclarion-worker
```
Redeploy = `git pull && php bin/lombokclarion migrate && php bin/lombokclarion
optimize && systemctl restart php8.3-fpm lombokclarion-worker`. TLS: `certbot --nginx`.

## 3. Docker (local / any registry)
```bash
export APP_KEY=$(openssl rand -hex 32) DB_PASSWORD=$(openssl rand -hex 16)
docker compose up -d --build        # web :80, app (fpm), worker, postgres
docker compose exec app php bin/lombokclarion migrate
```
The `base` image runs `optimize` at build time (opcache `validate_timestamps=0` —
immutable code, per §5). The `worker` target runs `work --loop`. Push:
`docker build -t registry/you/lombokclarion:1.0 . && docker push ...`.

## 4. Google Cloud (Cloud Run — the most "serverless-first" aligned path)
Cloud Run needs one HTTP-listening container; use the `cloudrun` stage:
```bash
gcloud artifacts repositories create app --repository-format=docker --location=asia-southeast2
gcloud builds submit --tag asia-southeast2-docker.pkg.dev/PROJECT/app/lombokclarion
gcloud run deploy lombokclarion \
  --image asia-southeast2-docker.pkg.dev/PROJECT/app/lombokclarion \
  --region asia-southeast2 --allow-unauthenticated \
  --set-secrets APP_KEY=app-key:latest \
  --set-env-vars APP_ENV=production,DB_DRIVER=pgsql
```
DB: Cloud SQL Postgres + connection pooling is **mandatory** (PgBouncer/Cloud SQL
connector) — spec §5 calls this a near-mandatory FaaS pairing. Worker: deploy the
`worker` image as a scheduled Cloud Run **Job** (Cloud Scheduler → one-shot `work`
drain) or a min-instances=1 service for `--loop`.
Migrations: a one-off Cloud Run Job running `php bin/lombokclarion migrate`.

## 5. AWS
**Option A — ECS Fargate (most direct):** push the image to ECR; a task definition
with two containers (nginx + FPM app sharing the `public/` volume) or the
single-container `cloudrun` stage behind an ALB; a separate service for `worker`.
RDS Postgres + RDS Proxy (pooling, §5).
```bash
aws ecr create-repository --repository-name lombokclarion
docker build -t ACCT.dkr.ecr.REGION.amazonaws.com/lombokclarion:1.0 . && docker push ...
```
**Option B — Lambda (true edge/serverless):** use the Bref runtime
(`bref/php-83-fpm`) with `public/index.php` as the handler; the existing
`FunctionAdapter` was designed for exactly this — a thin per-provider shim
translates the API Gateway event → `Request`. Run `optimize` in CI before
`serverless deploy`; ColdStartTest keeps the ~5ms budget honest. Queue: swap
`DatabaseQueueStore` for an SQS-backed `QueueStore` implementation (the interface
provides the seam) + an SQS-triggered Lambda worker.
**Option C — EC2:** identical to the VPS section.

## 6. DigitalOcean
**Droplet:** identical to the VPS section (Ubuntu). **App Platform (Dockerfile-based):**
```yaml
# .do/app.yaml
name: lombokclarion
services:
  - name: web
    dockerfile_path: Dockerfile        # use the cloudrun stage (single HTTP container)
    http_port: 8080
    envs: [{ key: APP_KEY, type: SECRET }, { key: APP_ENV, value: production }]
workers:
  - name: queue
    dockerfile_path: Dockerfile        # worker target
databases: [{ name: db, engine: PG }]
```
`doctl apps create --spec .do/app.yaml`. DO Managed Postgres ships built-in
PgBouncer pooling — enable it (§5). Migrations via an App Platform job/console.

---

## Post-deploy checklist (every target)
```bash
curl -I https://host/                    # 200 + SecurityHeaders (CSP/XFO/HSTS)
curl -I https://host/assets/<hash>.css   # 200 + Cache-Control: immutable
curl -X POST https://host/widgets        # 419 (CSRF active)
php bin/lombokclarion audit:security     # clean (verifies APP_DEBUG=false)
```
