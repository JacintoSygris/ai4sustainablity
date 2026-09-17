# Server Preparation

Back to the [technical manual](index.md).

## System Requirements

Size the host with headroom for Next.js, Laravel, FastAPI, PostgreSQL, Redis, Arelle and the worker. As a starting point for a small installation: 4 vCPU, 8 GB RAM and SSD storage with separate capacity for database, Redis and document volume. The operator must adjust resources after measuring real traffic, document size and P9/P10 timings.

Install:

- maintained and updated Linux system;
- Docker Engine with Compose v2, or Podman with Podman Compose;
- Caddy if it runs outside Compose, or the Caddy image if it runs as a service;
- PostgreSQL backup tools compatible with the deployed version;
- secret mechanism outside the repository.

## DNS And Firewall

Prerequisite: `app.example.org` points to the operator-managed host.

Expected result: the Internet reaches only Caddy on `80` and `443`. Laravel, FastAPI, PostgreSQL and Redis ports are not published.

Check:

```sh
# Directory: any administrative directory on the host
# Prerequisite: operator-managed firewall.
sudo ss -ltnp
```

The output should show processes listening on `80` and `443` for Caddy. If `8000`, `8001`, `5432` or `6379` appear bound to public interfaces, close that exposure before continuing.

## Service User

Prerequisite: administrative access to the host.

```sh
# Directory: any administrative directory on the host
sudo useradd --system --create-home --home-dir /srv/ia4sustainability --shell /usr/sbin/nologin ia4sustainability
sudo install -d -o ia4sustainability -g ia4sustainability -m 0750 /srv/ia4sustainability
sudo install -d -o ia4sustainability -g ia4sustainability -m 0750 /srv/ia4sustainability/{config,data,backups,logs}
sudo install -d -o ia4sustainability -g ia4sustainability -m 0750 /srv/ia4sustainability/data/{postgres,redis,laravel-storage}
```

Expected result: directories exist and are not writable by other users.

Check:

```sh
# Directory: any administrative directory on the host
sudo ls -ld /srv/ia4sustainability /srv/ia4sustainability/data/laravel-storage
```

## Docker Compose

Prerequisite: Docker Engine and Compose plugin installed.

```sh
# Directory: /srv/ia4sustainability
docker compose version
```

Expected result: a Docker Compose v2 version is shown.

Additional check:

```sh
# Directory: /srv/ia4sustainability
docker network ls
```

It should list networks without exposing services yet.

## Podman Compose

Prerequisite: Podman and `podman-compose` installed; if rootless mode is used, the operator has enabled persistent user services according to the distribution.

```sh
# Directory: /srv/ia4sustainability
podman --version
podman-compose --version
```

Expected result: both tools respond.

Check:

```sh
# Directory: /srv/ia4sustainability
podman network ls
```

## Volume Permissions

Laravel needs write access to `storage` and `bootstrap/cache`; in the production architecture the local document volume must be mounted into Laravel private storage. PostgreSQL and Redis need separate volumes so each component can be backed up and restored.

Do not reuse `/tmp` for data. Do not place secrets in the document volume. Do not use `0777` permissions to make write errors disappear; correct UID/GID and mounts.

## Resource Limits

Apply limits according to the platform: memory for FastAPI/model, CPU for Arelle and worker, storage for PostgreSQL, Redis and documents. If limits are too low, typical symptoms are `/predict` timeouts, iXBRL validation timeouts, stuck jobs and PHP runtime restarts.
