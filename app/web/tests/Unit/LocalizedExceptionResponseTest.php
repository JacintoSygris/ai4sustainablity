<?php

use App\Support\LocalizedExceptionResponse;
use Illuminate\Http\JsonResponse;

uses(Tests\TestCase::class);

it('localizes method failures while preserving allowed methods and other response fields', function () {
    foreach (['es', 'en'] as $locale) {
        app()->setLocale($locale);
        $payload = ['message' => 'The GET method is not supported for route api/report. Supported methods: POST.', 'code' => 'method_not_allowed', 'errors' => ['note' => ['Texto libre unchanged']]];
        $response = new JsonResponse($payload, 405, ['Allow' => 'POST', 'X-Test' => 'unchanged']);
        $result = LocalizedExceptionResponse::apply($response);
        expect($result)->toBe($response);
        expect($result->getStatusCode())->toBe(405);
        expect($result->headers->get('Allow'))->toBe('POST');
        expect($result->headers->get('X-Test'))->toBe('unchanged');
        $payload['message'] = $locale === 'es' ? 'El método HTTP no está permitido para esta ruta.' : 'The HTTP method is not supported for this route.';
        expect($result->getData(true))->toBe($payload);
    }
});

it('translates only known framework messages while retaining response status headers codes and protected data', function () {
    foreach (['es', 'en'] as $locale) {
        app()->setLocale($locale);
        $response = new JsonResponse(['message' => 'Too Many Attempts.', 'code' => 'rate_limited', 'note' => '=1+1'], 429, ['Retry-After' => '60', 'X-Test' => 'unchanged']);
        $result = LocalizedExceptionResponse::apply($response);
        expect($result)->toBe($response);
        expect($result->getStatusCode())->toBe(429);
        expect($result->headers->get('Retry-After'))->toBe('60');
        expect($result->getData(true)['code'])->toBe('rate_limited');
        expect($result->getData(true)['note'])->toBe('=1+1');
        expect($result->getData(true)['message'])->toBe($locale === 'es' ? 'Demasiados intentos. Espera antes de volver a intentarlo.' : 'Too Many Attempts.');
        $opaque = new JsonResponse(['message' => 'Texto libre unchanged'], 422);
        expect(LocalizedExceptionResponse::apply($opaque)->getData(true)['message'])->toBe('Texto libre unchanged');
    }
});

it('localizes validation summaries and resource failures without leaking model names or rewriting field evidence', function () {
    foreach (['es', 'en'] as $locale) {
        app()->setLocale($locale);
        $exception = \Illuminate\Validation\ValidationException::withMessages([
            'expected_revision' => [__('validation.required', ['attribute' => __('validation.attributes.expected_revision')])],
            'responses.0.status' => [__('validation.in', ['attribute' => __('validation.attributes.responses.*.status')])],
        ]);
        $errors = $exception->errors();
        $response = new JsonResponse(['message' => $exception->getMessage(), 'errors' => $errors], 422, ['X-Test' => 'same']);
        $result = LocalizedExceptionResponse::apply($response, $exception);
        expect($result->getData(true)['errors'])->toBe($errors);
        expect($result->getData(true)['message'])->toContain($locale === 'es' ? '(y 1 error más)' : '(and 1 more error)');
        expect($result->headers->get('X-Test'))->toBe('same');
        $missing = (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(\App\Models\ReportSnapshot::class, [99]);
        $notFound = new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException($missing->getMessage(), $missing);
        $response = new JsonResponse(['message' => $notFound->getMessage(), 'code' => 'not_found'], 404);
        $data = LocalizedExceptionResponse::apply($response, $notFound)->getData(true);
        expect($data['message'])->toBe($locale === 'es' ? 'No se ha encontrado el recurso solicitado.' : 'Not Found');
        expect($data['code'])->toBe('not_found');
        expect(json_encode($data))->not->toContain('ReportSnapshot', 'App', '99');
    }
});
