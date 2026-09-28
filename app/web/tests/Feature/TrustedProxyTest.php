<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/client-ip', static fn (Request $request) => response()->json([
        'ip' => $request->ip(),
    ]));
});

test('a configured reverse proxy supplies the client ip without trusting arbitrary peers', function () {
    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'example.com',
    ])->getJson('/_test/client-ip')
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.10');

    $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.50',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.11',
    ])->getJson('/_test/client-ip')
        ->assertOk()
        ->assertJsonPath('ip', '198.51.100.50');
});
