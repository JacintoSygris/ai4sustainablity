<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Input\StreamableInputInterface;

class CreateUser extends Command
{
    protected $signature = 'i4s:user:create {email} {--name=} {--verified} {--password-stdin}';

    protected $description = 'Create an account using a hidden password prompt or one line from STDIN';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = $this->option('name') ?? $email;

        try {
            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $this->error('FAIL user_create: duplicate_email');

                return self::FAILURE;
            }

            if ($this->option('password-stdin')) {
                $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
                $line = fgets($stream ?? STDIN);
                $password = $line === false ? '' : rtrim($line, "\r\n");
            } elseif ($this->input->isInteractive()) {
                // Never fall back to an echoed prompt if terminal hiding is unavailable.
                $password = $this->secret(__('Contraseña'), false);
            } else {
                $this->error('FAIL user_create: password_input_required');

                return self::FAILURE;
            }

            $validator = Validator::make([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
                'password' => ['required', Password::defaults()],
            ]);

            if ($validator->fails()) {
                // Field names only: custom validation messages may interpolate inputs.
                $this->error('FAIL user_create: invalid_'.implode(',invalid_', $validator->errors()->keys()));

                return self::FAILURE;
            }

            $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
            if ($this->option('verified')) {
                $user->email_verified_at = now();
            }
            $user->save();
        } catch (UniqueConstraintViolationException) {
            $this->error('FAIL user_create: duplicate_email');

            return self::FAILURE;
        } catch (\Throwable) {
            // Do not expose input, SQL bindings or connection credentials in errors.
            $this->error('FAIL user_create: operation_failed');

            return self::FAILURE;
        }

        $this->info('PASS user_create: user_id='.$user->getKey());

        return self::SUCCESS;
    }
}
