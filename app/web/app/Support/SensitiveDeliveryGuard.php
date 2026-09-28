<?php

namespace App\Support;

final class SensitiveDeliveryGuard
{
    /** @var list<string> */
    private const DURABLE_QUEUE_DRIVERS = ['database', 'redis', 'beanstalkd', 'sqs'];

    /** @var list<string> */
    private const SECRET_SAFE_MAIL_TRANSPORTS = [
        'smtp',
        'sendmail',
        'mailgun',
        'ses',
        'ses-v2',
        'postmark',
        'resend',
    ];

    public static function queueIsDurable(?string $connection = null): bool
    {
        return self::queueConnectionIsDurable(
            $connection ?? (string) config('queue.default'),
            [],
        );
    }

    public static function mailerProtectsSecrets(?string $mailer = null): bool
    {
        return self::mailerNameProtectsSecrets(
            $mailer ?? (string) config('mail.default'),
            [],
        );
    }

    /**
     * @param  list<string>  $visited
     */
    private static function queueConnectionIsDurable(string $name, array $visited): bool
    {
        if ($name === '' || in_array($name, $visited, true)) {
            return false;
        }

        $connection = config("queue.connections.{$name}");
        if (! is_array($connection)) {
            return false;
        }

        $driver = (string) ($connection['driver'] ?? '');
        if ($driver === 'failover') {
            $children = $connection['connections'] ?? null;
            if (! is_array($children) || $children === []) {
                return false;
            }

            $visited[] = $name;

            return collect($children)->every(
                fn ($child): bool => is_string($child)
                    && self::queueConnectionIsDurable($child, $visited),
            );
        }

        return in_array($driver, self::DURABLE_QUEUE_DRIVERS, true);
    }

    /**
     * @param  list<string>  $visited
     */
    private static function mailerNameProtectsSecrets(string $name, array $visited): bool
    {
        if ($name === '' || in_array($name, $visited, true)) {
            return false;
        }

        $mailer = config("mail.mailers.{$name}");
        if (! is_array($mailer)) {
            return false;
        }

        $transport = (string) ($mailer['transport'] ?? '');
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $mailer['mailers'] ?? null;
            if (! is_array($children) || $children === []) {
                return false;
            }

            $visited[] = $name;

            return collect($children)->every(
                fn ($child): bool => is_string($child)
                    && self::mailerNameProtectsSecrets($child, $visited),
            );
        }

        if (! in_array($transport, self::SECRET_SAFE_MAIL_TRANSPORTS, true)) {
            return false;
        }

        if ($transport !== 'smtp') {
            return true;
        }

        if (filled($mailer['url'] ?? null)) {
            $parts = parse_url((string) $mailer['url']);
            if (! is_array($parts)) {
                return false;
            }

            $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
            if ($scheme === 'smtps') {
                return true;
            }

            parse_str((string) ($parts['query'] ?? ''), $options);

            return $scheme === 'smtp' && filter_var($options['require_tls'] ?? false, FILTER_VALIDATE_BOOL);
        }

        return mb_strtolower((string) ($mailer['scheme'] ?? '')) === 'smtps'
            || filter_var($mailer['require_tls'] ?? false, FILTER_VALIDATE_BOOL);
    }
}
