# Technical Architecture

Back to the [documentation index](../index.md).

The included profile separates three components:

- Next.js frontend for the interface;
- Laravel API for authentication, state, catalogues, responses and exports;
- FastAPI service for topic proposals from characterisation.

Compose publishes ports for local evaluation. A localhost URL in documentation does not by itself guarantee network isolation in another environment; the operator must review port bindings, firewalls and proxying.

## Storage

The public profile uses temporary SQLite and recreates the database when the web component starts. It does not offer production persistence, backups or recovery.

## Exports

Laravel generates JSON summaries, CSV, HTML, guided documents and, where appropriate, a technical XHTML/iXBRL candidate. Each output has its own requirements and limits. Technical validation does not certify content quality or regulatory acceptance.

## Missing Dependencies

The repository does not supply a full production deployment, reverse proxy, object storage, mail provider, persistent queue worker, real OAuth keys or compatible document extraction service.
