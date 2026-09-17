# Persistence And Migrations

Back to the [documentation index](../index.md).

The local profile recreates the database when the web component starts. This simplifies evaluation but destroys test data.

## Local Evaluation

- SQLite database in a temporary location;
- migrations and seed data recreated at each startup;
- sessions and queues configured for local use;
- no retention guarantees.

## Own Environment

An operator that wants to keep data must design persistent storage and review the startup process. Adding a volume by itself is not enough if startup still recreates tables.

## Migrations

Migrations prepare database structure. They are not a guarantee of data quality, continuity, backups or compatibility with old data outside the process tested by the operator.
