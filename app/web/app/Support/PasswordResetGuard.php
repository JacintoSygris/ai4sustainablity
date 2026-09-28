<?php

namespace App\Support;

final class PasswordResetGuard
{
    public static function available(): bool
    {
        if (! config('services.auth_hardening.password_reset_enabled')) {
            return false;
        }

        if (! app()->environment('production')) {
            return true;
        }

        return SensitiveDeliveryGuard::queueIsDurable()
            && SensitiveDeliveryGuard::mailerProtectsSecrets()
            && self::hasCanonicalOrigin();
    }

    private static function hasCanonicalOrigin(): bool
    {
        try {
            CanonicalPublicUrl::root();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
