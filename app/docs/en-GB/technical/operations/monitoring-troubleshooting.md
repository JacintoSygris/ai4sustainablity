# Monitoring And Troubleshooting

Back to the [documentation index](../index.md).

## Laravel Health

`GET /healthz` is a public health check. HTTP 200 means the database dependency responds at that moment. HTTP 503 means that dependency is degraded.

The queue field is informational unless the operator adds a stricter check. The response must not expose secrets, internal versions, traces or system paths.

## Local Checks

Interface, API and proposal service checks indicate basic availability. They do not verify email delivery, proposal quality, persistence, specific exports or compliance.

## Troubleshooting

If a download fails, first review the journey state, then the technical dependencies of that download, and finally the logs of the service that generates it. Distinguish lack of data, configuration blockage and runtime failure.
