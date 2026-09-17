# Data And Privacy

Back to the [documentation index](../index.md).

Public documentation describes the repository and the included local profile. An installation operated by another entity needs its own privacy policy, controller, contact details, legal basis, retention periods and technical measures.

## Included Local Profile

The Compose profile uses a temporary SQLite database in the container and the Laravel startup recreates the tables. Data should therefore not be considered persistent. Use fictitious data only.

## Data Processed By The Application

It may process account data, email, password protected through a cryptographic hash, organisation profile, materiality decisions, responses, evidence references and technical export metadata.

## Documents

Document upload is disabled in the public profile and the compatible extraction service is missing. If an operator enables its own document capability, it must explain which files are stored, which extractions are retained, how they are deleted and which records may remain.

## Deletion And Retention

Absolute deletion promises should not be made without checking the real configuration. Account records, metadata, backups, logs or references may be retained for technical or legal reasons. In the local profile, restarting the web component recreates the database and removes test data, but that is not a production deletion policy.
