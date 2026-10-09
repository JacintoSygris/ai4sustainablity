<?php

namespace App\Console\Commands;

use App\Support\CanonicalPublicUrl;
use App\Support\RegistrationGuard;
use App\Support\SensitiveDeliveryGuard;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

class DeployCheck extends Command
{
    protected $signature = 'i4s:deploy:check';

    protected $description = 'Check deployment configuration without displaying secret values';

    public function handle(): int
    {
        $hardFailure = false;
        $report = function (string $name, bool $passed, bool $hard = false, array $reasons = []) use (&$hardFailure): void {
            $this->line(($passed ? 'PASS ' : 'FAIL ').$name.($reasons === [] ? '' : ': '.implode(', ', $reasons)));
            $hardFailure = $hardFailure || ($hard && ! $passed);
        };

        $host = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));
        $urlValid = ! in_array($host, ['', 'localhost', 'example.com', 'app.example.org'], true);
        try {
            CanonicalPublicUrl::root();
        } catch (\Throwable) {
            $urlValid = false;
        }
        $report('APP_URL', $urlValid, true);
        $hosts = (array) config('app.trusted_hosts', []);
        $enforced = (bool) config('app.enforce_trusted_hosts', false);
        $report('ENFORCE_TRUSTED_HOSTS', $enforced, $this->laravel->environment('production'));
        $report('trusted_hosts', $enforced && $urlValid && in_array($host, $hosts, true) && in_array('web', $hosts, true), true);

        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        $report('APP_KEY', is_string($key) && Encrypter::supported($key, (string) config('app.cipher')), true);

        $report('DB_writable', $this->databaseIsWritable(), true);
        $report('migrations_current', $this->migrationsAreCurrent(), true);
        $reasons = RegistrationGuard::registrationUnavailableReasons();
        $report('registration', $reasons === [], reasons: $reasons);
        $report('mailer_safe', SensitiveDeliveryGuard::mailerProtectsSecrets());
        // The public image has no local MTA. Validate the documented SMTP path too.
        $report('smtp_configured', $this->smtpIsConfigured());
        $report('queue_durable', SensitiveDeliveryGuard::queueIsDurable());

        return $hardFailure ? self::FAILURE : self::SUCCESS;
    }

    private function databaseIsWritable(): bool
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() === 'sqlite') {
                $path = $connection->getDatabaseName();
                if ($path !== ':memory:' && (! is_file($path) || ! is_writable($path) || ! is_writable(dirname($path)))) {
                    return false;
                }
            }

            // Exercise write permissions without changing data, including on read-only DBs.
            $connection->table(config('database.migrations.table', 'migrations'))
                ->whereRaw('1 = 0')->update(['batch' => DB::raw('batch')]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function migrationsAreCurrent(): bool
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return false;
            }
            $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

            return array_diff(array_keys($files), $migrator->getRepository()->getRan()) === [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function smtpIsConfigured(): bool
    {
        $mailer = (array) config('mail.mailers.'.config('mail.default'), []);

        return ($mailer['transport'] ?? '') === 'smtp'
            && filled($mailer['host'] ?? null)
            && (int) ($mailer['port'] ?? 0) > 0
            && filled($mailer['username'] ?? null)
            && filled($mailer['password'] ?? null)
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }
}
