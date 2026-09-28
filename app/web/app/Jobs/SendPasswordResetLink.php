<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\PasswordResetGuard;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
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
        private readonly string $generation,
    ) {}

    public function handle(): void
    {
        if (! PasswordResetGuard::available()) {
            return;
        }

        $issued = DB::transaction(function (): ?array {
            $user = User::query()->lockForUpdate()->find($this->userId);

            if (! $user
                || ! hash_equals(mb_strtolower($this->email), mb_strtolower($user->email))
                || (int) $user->auth_version !== $this->authVersion
                || ! hash_equals((string) $user->password_reset_generation, $this->generation)) {
                return null;
            }

            $token = app('auth.password.broker')->createToken($user);
            $bound = DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->update([
                    'user_id' => $user->getKey(),
                    'auth_version' => (int) $user->auth_version,
                    'generation' => $this->generation,
                    'token_fingerprint' => hash('sha256', $token),
                ]);

            if ($bound !== 1) {
                app('auth.password.broker')->deleteToken($user);

                return null;
            }

            return [$user, $token];
        }, 3);

        if (! $issued) {
            return;
        }

        [$user, $token] = $issued;
        $user->sendPasswordResetNotification($token);
        User::query()
            ->whereKey($this->userId)
            ->where('auth_version', $this->authVersion)
            ->where('password_reset_generation', $this->generation)
            ->update(['password_reset_generation' => null]);
    }
}
