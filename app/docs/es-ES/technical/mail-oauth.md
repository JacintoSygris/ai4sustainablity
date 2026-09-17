# Correo y OAuth

Volver al [manual tecnico](index.md).

## SMTP

El correo no es opcional si se exige verificacion de email o restablecimiento de contrasena. El repositorio incluye configuracion SMTP Laravel; el proveedor, credenciales, SPF, DKIM, DMARC y reputacion los aporta el operador.

Variables minimas:

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

Precondicion: credenciales SMTP validas en el gestor de secretos.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan config:clear
docker compose exec web php artisan optimize
```

Resultado esperado: Laravel recarga configuracion.

Comprobacion: solicitar un restablecimiento de contrasena desde `https://app.example.org/laravel/forgot-password` con una cuenta de prueba del operador y confirmar que llega el correo. No usar cuentas ni dominios privados en documentacion.

Errores comunes:

| Sintoma | Comprobacion | Solucion segura |
|---|---|---|
| No llega email | Logs de Laravel y proveedor SMTP | Corregir host, puerto, usuario o remitente autorizado. |
| Enlace usa dominio incorrecto | `APP_URL` | Ajustar a `https://app.example.org` y limpiar cache. |
| TLS falla | Puerto/encryption del proveedor | Usar el modo documentado por el proveedor, no desactivar seguridad global. |

## OAuth Google

OAuth es opcional. Si se activa, `SOCIAL_LOGIN_ENABLED=true` y las credenciales deben venir de una aplicacion web creada por el operador en Google Cloud.

Redirect URI de ejemplo:

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

Precondicion: la URI anterior esta registrada exactamente en Google Cloud.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan route:list --path=auth/google
```

Resultado esperado: aparecen rutas de redirect y callback.

Comprobacion: abrir `https://app.example.org/auth/google/redirect`, completar login con una cuenta de prueba autorizada y confirmar que vuelve a la aplicacion.

## OAuth Microsoft Entra

Crear una aplicacion en Microsoft Entra ID con plataforma web y redirect exacto:

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

`MICROSOFT_TENANT_ID=common` permite un comportamiento amplio. El operador puede restringirlo a su tenant si su politica de identidad lo requiere.

Precondicion: aplicacion Entra creada y secreto vigente.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan route:list --path=auth/microsoft
```

Resultado esperado: aparecen rutas de redirect y callback.

Comprobacion: abrir `https://app.example.org/auth/microsoft/redirect` con una cuenta de prueba y verificar retorno.

## Almacenamiento de secretos OAuth

Guardar client secrets en el gestor de secretos o fichero protegido del host. No versionar secretos en `web.env`, Compose, Markdown ni imagenes. Rotar antes de caducar y verificar ambos proveedores tras cada rotacion.
