<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\SensitiveDeliveryGuard;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendRegistrationVerification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly int $userId,
        private readonly string $email,
        private readonly int $authVersion,
    ) {}

    public function handle(): void
    {
        if ($this->userId <= 0
            || (app()->environment('production') && ! SensitiveDeliveryGuard::mailerProtectsSecrets())) {
            return;
        }

        DB::transaction(function (): void {
            $user = User::query()->lockForUpdate()->find($this->userId);

            if (! $user
                || ! hash_equals(mb_strtolower($this->email), mb_strtolower($user->email))
                || (int) $user->auth_version !== $this->authVersion
                || $user->hasVerifiedEmail()) {
                return;
            }

            $user->sendEmailVerificationNotification();
        }, 3);
    }
}
