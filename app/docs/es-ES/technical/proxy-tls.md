# Proxy y TLS

Volver al [manual tecnico](index.md).

## Caddyfile de referencia

Esta configuracion es una plantilla del operador. Debe adaptarse a la red real, pero conserva las reglas de seguridad de la arquitectura: solo Caddy publica `80/443`, aplica TLS, limita cuerpo a 50 MB y envia todo al frontend.

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

No crear rutas publicas directas a `web:8000` ni `ai-service:8001`. Next.js ya reescribe las rutas necesarias hacia Laravel en red privada.

## Puesta en marcha

Precondicion: DNS de `app.example.org` resuelve al host y los puertos `80` y `443` estan abiertos hacia Caddy.

```sh
# Directorio: /srv/ia4sustainability
docker compose up -d caddy
```

Resultado esperado: Caddy solicita o renueva certificados automaticamente y reenvia trafico al frontend.

Comprobacion:

```sh
# Directorio: cualquier directorio administrativo del host
curl -I https://app.example.org/
```

La respuesta esperada es HTTP correcto, cabeceras de seguridad presentes y sin exposicion de Laravel/FastAPI.

Con Podman:

```sh
# Directorio: /srv/ia4sustainability
podman-compose up -d caddy
```

## Renovacion TLS

Caddy renueva certificados automaticamente si conserva su volumen `caddy_data`, el DNS sigue apuntando al host y los puertos necesarios siguen abiertos. El volumen de Caddy debe incluirse en la estrategia de backup de configuracion operativa o reconstruirse deliberadamente.

Comprobacion periodica:

```sh
# Directorio: /srv/ia4sustainability
docker compose logs --since=24h caddy
```

Resultado esperado: no hay errores persistentes de ACME, DNS ni almacenamiento.

## Validacion externa

Precondicion: servicio publicado.

```sh
# Directorio: cualquier directorio administrativo del host
curl -fsS https://app.example.org/api/auth/register-config
```

Resultado esperado: JSON publico de configuracion de registro, sin secretos.

Comprobacion negativa:

```sh
# Directorio: cualquier directorio administrativo del host
curl -fsS https://app.example.org:8000/healthz
```

Resultado esperado: la conexion falla o no existe ruta publica. Si responde Laravel directamente, el firewall o Compose exponen un puerto que debe cerrarse.
