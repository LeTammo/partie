# Partie – Multiplayer Board Games

A lightweight, modular multiplayer board game web app built with **Symfony 8.1** and **PHP 8.3+**.

---

## Quick start

```bash
composer install

# 1. Start the Mercure hub on http://localhost:3000
#    Option A – standalone binary, no Docker:
./bin/mercure-dev.sh
#    Option B – Docker:
docker compose up -d mercure

# 2. Build the Tailwind CSS
php bin/console tailwind:build    # add --watch during development

# 3. Serve the app
symfony serve                     # or: php -S 127.0.0.1:8000 -t public
```

**One-time download of the Mercure binary** (for option A):

```bash
mkdir -p tools/mercure && cd tools/mercure \
  && curl -sL https://github.com/dunglas/mercure/releases/download/v0.24.2/mercure_$(uname -s)_$(uname -m).tar.gz | tar xz \
  && cd ../..
```

`bin/mercure-dev.sh` runs it with the dev config (`:3000`, permissive CORS, anonymous
subscriptions) and the JWT secret matching `.env.dev`.
