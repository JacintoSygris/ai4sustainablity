# Mail And OAuth

Back to the [technical manual](index.md).

## SMTP

Mail is not optional if email verification or password reset is required. The repository includes Laravel SMTP configuration; the provider, credentials, SPF, DKIM, DMARC and reputation are provided by the operator.

Minimum variables:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.org
MAIL_PORT=587
MAIL_USERNAME=example-smtp-user
MAIL_PASSWORD=managed-secret
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@app.example.org
MAIL_FROM_NAME=IA4Sustainability
AUTH_REQUIRE_EMAIL_VERIFICATION=true
```

Prerequisite: valid SMTP credentials in the secret manager.

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan config:clear
docker compose exec web php artisan optimize
```

Expected result: Laravel reloads configuration.

Check: request a password reset from `https://app.example.org/laravel/forgot-password` with an operator test account and confirm that the email arrives. Do not use private accounts or domains in documentation.

Common errors:

| Symptom | Check | Safe fix |
|---|---|---|
| Email does not arrive | Laravel logs and SMTP provider | Correct host, port, user or authorised sender. |
| Link uses wrong domain | `APP_URL` | Set `https://app.example.org` and clear cache. |
| TLS fails | Provider port/encryption | Use provider-documented mode; do not disable security globally. |

## Google OAuth

OAuth is optional. If enabled, set `SOCIAL_LOGIN_ENABLED=true` and source credentials from a web application created by the operator in Google Cloud.

Example redirect URI:

```text
https://app.example.org/auth/google/callback
```

Variables:

```dotenv
SOCIAL_LOGIN_ENABLED=true
GOOGLE_CLIENT_ID=example-google-client-id
GOOGLE_CLIENT_SECRET=managed-secret
GOOGLE_REDIRECT_URI=https://app.example.org/auth/google/callback
```

Prerequisite: the URI above is registered exactly in Google Cloud.

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan route:list --path=auth/google
```

Expected result: redirect and callback routes appear.

Check: open `https://app.example.org/auth/google/redirect`, complete login with an authorised test account and confirm return to the application.

## Microsoft Entra OAuth

Create an application in Microsoft Entra ID with web platform and exact redirect:

```text
https://app.example.org/auth/microsoft/callback
```

Variables:

```dotenv
SOCIAL_LOGIN_ENABLED=true
MICROSOFT_CLIENT_ID=example-microsoft-client-id
MICROSOFT_CLIENT_SECRET=managed-secret
MICROSOFT_REDIRECT_URI=https://app.example.org/auth/microsoft/callback
MICROSOFT_TENANT_ID=common
```

`MICROSOFT_TENANT_ID=common` allows broad behaviour. The operator may restrict it to its tenant if the identity policy requires that.

Prerequisite: Entra application created and secret valid.

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan route:list --path=auth/microsoft
```

Expected result: redirect and callback routes appear.

Check: open `https://app.example.org/auth/microsoft/redirect` with a test account and verify return.

## OAuth Secret Storage

Store client secrets in the secret manager or a protected host file. Do not version secrets in `web.env`, Compose, Markdown or images. Rotate before expiry and verify both providers after each rotation.
