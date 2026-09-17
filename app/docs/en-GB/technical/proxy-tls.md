# Proxy And TLS

Back to the [technical manual](index.md).

## Reference Caddyfile

This configuration is an operator template. It must be adapted to the real network, but preserves the architecture security rules: only Caddy publishes `80/443`, applies TLS, limits body size to 50 MB and sends everything to the frontend.

```caddyfile
app.example.org {
	encode zstd gzip
	request_body {
		max_size 50MB
	}

	header {
		X-Content-Type-Options "nosniff"
		Referrer-Policy "strict-origin-when-cross-origin"
		X-Frame-Options "DENY"
		Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
		Strict-Transport-Security "max-age=31536000; includeSubDomains"
		-Server
	}

	reverse_proxy frontend:3000 {
		header_up X-Forwarded-Proto {scheme}
		header_up X-Forwarded-Host {host}
	}
}
```

Do not create public direct routes to `web:8000` or `ai-service:8001`. Next.js already rewrites the required routes to Laravel on the private network.

## Startup

Prerequisite: DNS for `app.example.org` resolves to the host and ports `80` and `443` are open to Caddy.

```sh
# Directory: /srv/ia4sustainability
docker compose up -d caddy
```

Expected result: Caddy requests or renews certificates automatically and forwards traffic to the frontend.

Check:

```sh
# Directory: any administrative directory on the host
curl -I https://app.example.org/
```

The expected response is a correct HTTP response, security headers present and no Laravel/FastAPI exposure.

With Podman:

```sh
# Directory: /srv/ia4sustainability
podman-compose up -d caddy
```

## TLS Renewal

Caddy renews certificates automatically if it keeps its `caddy_data` volume, DNS still points to the host and the necessary ports remain open. The Caddy volume must either be included in the operational configuration backup or deliberately rebuilt.

Periodic check:

```sh
# Directory: /srv/ia4sustainability
docker compose logs --since=24h caddy
```

Expected result: no persistent ACME, DNS or storage errors.

## External Validation

Prerequisite: service published.

```sh
# Directory: any administrative directory on the host
curl -fsS https://app.example.org/api/auth/register-config
```

Expected result: public register configuration JSON, with no secrets.

Negative check:

```sh
# Directory: any administrative directory on the host
curl -fsS https://app.example.org:8000/healthz
```

Expected result: the connection fails or no public route exists. If Laravel responds directly, the firewall or Compose exposes a port that must be closed.
