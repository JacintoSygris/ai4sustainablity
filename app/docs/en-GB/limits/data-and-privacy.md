# Data And Privacy

Back to the [documentation index](../index.md).

Public documentation describes the repository and the included local profile. An installation operated by another entity needs its own privacy policy, controller, contact details, legal basis, retention periods and technical measures.

## Included Local Profile

The Compose profile uses a temporary SQLite database in the container and the Laravel startup recreates the tables. Data should therefore not be considered persistent. Use fictitious data only.

## Data Processed By The Application

It may process account data, email, password protected through a cryptographic hash, organisation profile, materiality decisions, responses, evidence references and technical export metadata.

## Documents

Document upload is disabled in the public profile and the compatible extraction service is missing. If an operator enables its own document capability, it must explain which files are stored, which extractions are retained, how they are deleted and which records may remain.

## Optional Consent-Based Purposes

These purposes are off in the local profile. They only process data of accounts that have given specific consent, separate from accepting the service, and that can withdraw it without losing access.

- **Learning from decisions** (consent version `learning-feedback-bandit-2026-10-08`, purpose `feedback:supervised-learning`). It is on by configuration on a declared installation (`APP_ENV=production|staging` or `LEARNING_DEPLOYED=true`) and only admits accounts with a verified email. Managed in `/profile`. It uses the structured organisation profile and P8/P9 decisions with pseudonymous identifiers, without names, emails, documents, notes or report values. Pseudonymised data is not anonymous. Events, candidates and operational records are kept for up to 30 days; temporary exports are erased on completion and interrupted ones after 20 minutes. Withdrawal excludes future events, blocks use within at most 90 seconds and disables the affected versions; an individual contribution cannot be exactly subtracted. See [Optional learning engine](../learning-engine.md).
- **Usability study** (only with `USABILITY_STUDY_ENABLED=true`, whether or not the installation is declared). It requires an authenticated account and explicit consent, and a verified email only when `AUTH_REQUIRE_EMAIL_VERIFICATION=true`. It collects a random code linked to the account, the consent, task starts, completions and abandonments, dates, visible errors, SUS answers and optional comments. It does not collect company answers, keystrokes, recordings or documents. Data is kept until withdrawal or erasure at the close of the study, at most 90 days after enrolment. See the [study protocol](../../usability-study-protocol.md) (Spanish).

## Optional Modules That Store Data

- **DMA import** (`DMA_IMPORT_ENABLED`): keeps the imported rows, suggestions, original file name and SHA-256 of each file, its size, the date and the user. It is deleted in cascade with the characterisation or account.
- **EU Taxonomy eligibility** (`EU_TAXONOMY_ELIGIBILITY_ENABLED`): keeps the most recent declaration of activities and percentages, with its source context. It is deleted with the characterisation.

The controller of an installation must complete its privacy information, processors, location, rights and risk assessment before enabling these functions.

## Deletion And Retention

Absolute deletion promises should not be made without checking the real configuration. Account records, metadata, backups, logs or references may be retained for technical or legal reasons. In the local profile, restarting the web component recreates the database and removes test data, but that is not a production deletion policy.
