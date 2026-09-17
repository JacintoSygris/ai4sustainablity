# Configuration

Back to the [technical manual](index.md).

This page is kept as a bridge route. Full configuration is in [secrets and parameters](secrets-and-parameters.md), with persistence in [database and persistence](database-persistence.md) and deployment in [services and deployment](services-deployment.md).

Main rule: a configured variable does not make a missing capability functional. In particular, `P6_DOCUMENT_UPLOAD_ENABLED` must remain `false` until `POST /extract-document` is implemented.
