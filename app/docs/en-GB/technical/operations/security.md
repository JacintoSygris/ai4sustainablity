# Security

Back to the [documentation index](../index.md).

Effective security depends on operator configuration. The repository includes useful pieces, but not a complete production posture.

## Available Controls

- session authentication;
- CSRF protection in forms;
- email verification when enabled;
- antibot field and Turnstile when configured;
- localised validation messages;
- headers and proxying configurable in the deployment environment.

## Controls Not Covered By The Local Profile

- real secrets and rotation;
- organisation-level access policies;
- storage encryption managed by the operator;
- central logging and incident detection;
- operational document scanning;
- dependency review and host hardening.

Do not put secrets in documentation or versioned templates.
