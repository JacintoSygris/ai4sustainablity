<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LocalizedExceptionResponse
{
    private const MESSAGES = [
        'Unauthenticated.', 'CSRF token mismatch.', 'Too Many Attempts.',
        'The given data was invalid.', 'Server Error', 'Not Found', 'Forbidden',
        'This action is unauthorized.', 'The POST data is too large.', 'Service Unavailable',
    ];

    public static function apply(Response $response, ?Throwable $exception = null): Response
    {
        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            $data = $response->getData(true);
            if (is_array($data)) {
                if ($exception instanceof ValidationException && $response->getStatusCode() === $exception->status) {
                    $messages = $exception->validator->errors()->all();
                    $data['message'] = array_shift($messages) ?? __('The given data was invalid.');
                    if ($count = count($messages)) {
                        $data['message'] .= ' '.trans_choice($count === 1 ? '(and :count more error)' : '(and :count more errors)', $count, ['count' => $count]);
                    }
                } elseif ($response->getStatusCode() === 404 && ($exception instanceof ModelNotFoundException || $exception?->getPrevious() instanceof ModelNotFoundException)) {
                    $data['message'] = __('Not Found');
                } elseif ($response->getStatusCode() === 405) {
                    $data['message'] = __('The HTTP method is not supported for this route.');
                } elseif (in_array($data['message'] ?? null, self::MESSAGES, true)) {
                    $data['message'] = __($data['message']);
                }
                $response->setData($data);
            }
        }

        return $response;
    }
}
