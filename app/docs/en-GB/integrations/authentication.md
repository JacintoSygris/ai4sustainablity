# Authentication Integration

Back to the [documentation index](../index.md).

Authentication uses Laravel web sessions and CSRF protection. Protected routes require an authenticated user and, when enabled, email verification.

## Public Configuration

`GET /api/auth/register-config` reports the public Turnstile key if present, whether email verification is required and the antibot field name. This information contains no secrets.

## Registration And Login

The client must send registration fields, CSRF and the empty antibot field. If Turnstile is configured, it must also send the widget token. Errors are handled as form validation.

## Verification

When verification is enabled, protected APIs may return HTTP 409 with `email_unverified`. The client should navigate to the verification screen and allow the email to be resent.
