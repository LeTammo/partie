# Deploying Partie to production

The production stack is a **single Docker container** built on [FrankenPHP](https://frankenphp.dev) (Caddy + PHP 8.4). 
An advantage is that the Mercure hub is a Caddy module inside the same server.

## First deployment

```bash
git clone https://github.com/LeTammo/partie
cd partie

# 1. Create the env file and edit it to your liking (see below)
cp .env.example .env

# 2. Generate the two secrets and paste them into .env
openssl rand -hex 32   # -> APP_SECRET
openssl rand -hex 32   # -> MERCURE_JWT_SECRET
# (no openssl? php -r "echo bin2hex(random_bytes(32)), PHP_EOL;")

# 4. Build and start
docker compose up -d
```

Open `https://<your-domain>` - the first request may take a few seconds for the TLS certificate.

## Environment variables

All environmental variables are set in the root `.env` (not the symfony `app/.env`!):

| Variable | Default                                    | Purpose |
| --- |--------------------------------------------| --- |
| `SERVER_NAME` | `localhost`                                | Domain FrankenPHP serves (and gets a certificate for) |
| `HTTP_PORT` | `80`                                       | Host port mapped to the container's HTTP port |
| `HTTPS_PORT` | `443`                                      | Host port mapped to the container's HTTPS port |
| `APP_SECRET` | - (required)                               | Symfony kernel secret (CSRF tokens, signed URIs) |
| `MERCURE_JWT_SECRET` | - (required)                               | Shared HS256 key between app and Mercure hub (≥ 256 bits) |
| `DEFAULT_URI` | `https://$SERVER_NAME`                     | Absolute URLs outside HTTP requests (CLI) |
| `MERCURE_PUBLIC_URL` | `https://$SERVER_NAME/.well-known/mercure` | Hub URL browsers connect to |
| `TRUSTED_PROXIES` | private IP ranges                          | IPs allowed to set `X-Forwarded-*` headers |

## Running behind an existing reverse proxy

If you have a reverse proxy setup, you can run Caddy as plain HTTP on an internal port and proxy to it.

### 1. Update `.env`
Configure the container to run on a local HTTP port (e.g., `8080`) and set the correct public URLs:

```bash
SERVER_NAME=:80            # serve plain HTTP inside the container, no auto-TLS
HTTP_PORT=8080             # choose any free local port your proxy will forward to
DEFAULT_URI=https://partie.example.com
MERCURE_PUBLIC_URL=https://partie.example.com/.well-known/mercure
```

### 2. Update `compose.yaml`
Because the reverse proxy is handling SSL on ports `80` and `443`,
you must remove the HTTPS port mappings in your Compose file to prevent conflicts.

Additionally, bind the HTTP port strictly to localhost (`127.0.0.1`):

```yaml
    ports:
        - "127.0.0.1:${HTTP_PORT:-80}:80"
        # Remove or comment out the 443 / 443/udp (HTTP/3) port mappings:
        # - "${HTTPS_PORT:-443}:443"
        # - "${HTTPS_PORT:-443}:443/udp"
```

### 3. Configure your reverse proxy (Nginx example)
Ensure Nginx is configured to pass the appropriate headers and disable buffering for the Mercure SSE stream.

Then, configure your site's `server` block (replace `8080` with your configured `HTTP_PORT`):
```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    listen [::]:80;
    server_name partie.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    server_name partie.example.com;

    ssl_certificate     /etc/letsencrypt/live/partie.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/partie.example.com/privkey.pem;

    location /.well-known/mercure {
        proxy_pass http://127.0.0.1:8080;
        proxy_read_timeout 24h;
        proxy_http_version 1.1;
        proxy_buffering off;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
    }

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_buffering off;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;

        # Support WebSockets / Upgrades
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
    }
}
```

---

## Updating

```bash
git pull
docker compose up -d --build
```
