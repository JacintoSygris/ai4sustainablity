<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('characterizations.{userId}', function ($user, int $userId) {
    return (int) $user->id === $userId;
});
