# Cookie and local-storage consent

This public build includes a shared consent layer for the Next frontend and Laravel Blade pages.
It is product documentation, not legal advice or a certification of compliance.

## What is included

- Equal first-layer accept and reject actions.
- Granular choices for optional preferences, local draft recovery and real-time updates.
- Necessary authentication, CSRF and server-side workflow state remain available without optional consent.
- Withdrawal is available from the footer and cleans only the product's own optional browser keys.
- The local consent record is browser-scoped. It is not a central identity log or proof of consent across devices.

## Runtime contract

The shared browser assets live in both public roots:

- `app/frontend/public/consent/`
- `app/web/public/consent/`

The implementation keeps the two copies byte-identical where required by the tests. Optional consumers must call the consent helpers before reading, writing, subscribing or reconnecting optional browser state.

## Operator notes

Configure any third-party security or real-time provider through environment variables and provider dashboards. Do not treat this repository as proof that a deployed host's provider settings, cookie attributes or retention periods are compliant; verify the effective production headers, cookies, consent text and provider configuration for the actual deployment domain.

The default policy version in the shipped code uses a 180-day local expiry decision. If purposes, text, providers or retention behavior change, update the policy version, tests and visible copy together.

## Verification scope

The repository contains automated tests for the consent engine, UI behavior, storage failures, cross-tab behavior, shared asset parity and consumer gating. Passing these tests demonstrates behavior for the tested source tree only; legal sign-off and production-provider verification remain separate operator responsibilities.
