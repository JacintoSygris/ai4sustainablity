# Contrato público de autenticación y registro v0

Este contrato describe el comportamiento público de registro, inicio de sesión,
protección antibots y verificación de correo. Las rutas usan sesión web de
Laravel y token CSRF.

## Configuración pública del registro

`GET /api/auth/register-config` es público y devuelve:

```json
{
  "data": {
    "turnstile_site_key": "<clave pública o null>",
    "require_email_verification": true,
    "honeypot_field": "company_website"
  }
}
```

`turnstile_site_key` es una clave pública. Si está ausente, el formulario no
debe renderizar Turnstile ni enviar token de Turnstile.

## Envío del formulario de registro

El formulario envía datos codificados de formulario a `/laravel/register`.
Debe incluir:

- Los campos normales de registro: `name`, `email`, `password` y
  `password_confirmation`.
- El token CSRF de la sesión.
- Un campo antibot con el nombre indicado por `data.honeypot_field`. Debe estar
  visualmente oculto, no ser enfocable y enviarse vacío por usuarios reales.
- `cf-turnstile-response` solo cuando `data.turnstile_site_key` esté presente y
  el widget de Cloudflare Turnstile haya generado token.

Si honeypot o Turnstile fallan, el backend responde con el flujo normal de
errores de validación del registro y mensaje en español asociado al campo
`email`.

## Verificación de correo

`GET /api/auth/session` devuelve, entre otros campos:

```json
{
  "data": {
    "authenticated": true,
    "user": {
      "email_verified": false
    },
    "require_email_verification": true
  }
}
```

Cuando `require_email_verification` es `true` y `email_verified` es `false`, la
interfaz debe mostrar una pantalla de verificación de correo antes del flujo
P5-P10. Esa pantalla puede ofrecer un botón "Reenviar correo" que haga `POST` a
`/laravel/email/verification-notification` con CSRF.

Las API protegidas del flujo devuelven HTTP `409` con `code:
"email_unverified"` para usuarios autenticados pero no verificados cuando la
verificación está activada. El cliente debe tratar ese caso como navegación a
la pantalla de verificación, no como un error genérico.

Cuando el usuario abre el enlace de verificación (`/verify-email/{id}/{hash}`)
y vuelve a la aplicación, `email_verified` pasa a `true` y el flujo protegido se
reanuda con normalidad.

## Reglas de integración

- Las rutas protegidas usan sesión web normal y middleware `auth`.
- Las API del flujo P5-P10 añaden `verified.required` cuando la verificación de
  correo está activada.
- La copia visible al usuario debe ser clara, en español y sin nombres de
  frameworks.
