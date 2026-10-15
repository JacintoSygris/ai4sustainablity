<?php

namespace App\Services\Report;

use DomainException;
use Illuminate\Http\JsonResponse;

final class ReportScopeException extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['data' => [
            'type' => 'guided_report_blocked',
            'reason_code' => $this->getMessage(),
        ]], 409);
    }
}
