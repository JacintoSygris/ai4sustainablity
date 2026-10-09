<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class AppUrlWarning
{
    public const MESSAGE = 'APP_URL must have a public host; its host is empty, localhost or a placeholder.';

    public function __construct(private readonly ?string $directory = null) {}

    public function logOnce(): void
    {
        // The public Linux image exposes procfs. PHP request statics do not
        // survive cli-server/FPM requests; PID alone also fails after PID reuse.
        $stat = @file_get_contents('/proc/self/stat');
        $boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
        $fields = is_string($stat) ? explode(' ', substr($stat, strrpos($stat, ')') + 2)) : [];
        $start = $fields[19] ?? ''; // field 22, after pid and parenthesised comm
        if (! is_string($boot) || ! ctype_digit($start)) {
            $this->withoutDeduplication();
            return;
        }

        $directory = $this->directory ?? sys_get_temp_dir().'/i4s-app-url-'.hash('sha256', base_path());
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->withoutDeduplication();
            return;
        }
        $marker = @fopen($directory.'/'.getmypid(), 'c+');
        if ($marker === false) {
            $this->withoutDeduplication();
            return;
        }
        try {
            if (! @flock($marker, LOCK_EX)) {
                $this->withoutDeduplication();
                return;
            }
            $identity = trim($boot).':'.$start;
            if (stream_get_contents($marker) === $identity) {
                return;
            }
            Log::error(self::MESSAGE);
            rewind($marker);
            if (! @ftruncate($marker, 0) || @fwrite($marker, $identity) !== strlen($identity) || ! @fflush($marker)) {
                Log::error('APP_URL warning deduplication unavailable: process marker is not writable.');
            }
        } finally {
            fclose($marker); // releases the lock, including on early return
        }
    }

    private function withoutDeduplication(): void
    {
        // Report broken runtime prerequisites rather than suppressing APP_URL
        // errors or aborting requests when the temporary filesystem is broken.
        Log::error(self::MESSAGE.' Warning deduplication requires readable procfs and a writable temporary directory.');
    }
}
