# Local development setup

---

For production deployment, see [DEPLOYMENT.md](./DEPLOYMENT.md).

---

Setup in short:
- `app/` is the Symfony app that runs with `symfony serve`
- `compose.dev.yaml` is the dev Docker Compose file that runs the Mercure hub
- configure `app/.env.dev` for dev environment variables (you can just leave it as-is)

---

```bash
# 1. Run the Symfony app (in the `app/` directory):
composer install
#    Option A - Symfony binary:
symfony server:start
#    Option B - PHP built-in server:
php -S 127.0.0.1:8000 -t public

# 2. Build the Tailwind CSS with live reload (in the `app/` directory):
php bin/console tailwind:build --watch

# 3. To use WebSockets, run the Mercure hub:
#    Option A - Docker:
docker compose -f compose.dev.yaml up -d
#    Option B - standalone binary, no Docker:
# Download the binary once:
mkdir -p tools/mercure && cd tools/mercure \
  && curl -sL https://github.com/dunglas/mercure/releases/download/v0.24.2/mercure_$(uname -s)_$(uname -m).tar.gz | tar xz \
  && cd ../..
# Run the binary, it runs on `:3000`:
./bin/mercure-dev.sh
```

Open http://127.0.0.1:8000 to see test the app. 

## Stopping the dev server

1. Stop the Symfony server: `symfony server:stop` (or just Ctrl+C if running in foreground)
2. Stop the Mercure hub: `docker compose -f compose.dev.yaml down` (it needs the explicit `-f` flag)

## Dev environment notes

- `app/.env.dev` points `MERCURE_URL` / `MERCURE_PUBLIC_URL` to `http://localhost:3000/.well-known/mercure` 
  (matching the `mercure` service in `compose.dev.yaml`, plain HTTP, anonymous subscriptions). If you change
  `MERCURE_PORT` in the root `.env`, update these two URLs to match.
- Lobbies/game states are cached in `app/var/lobbies/` for 12 hours (symfony cache clear doesn't delete them).
