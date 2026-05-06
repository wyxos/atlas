<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:setup {--name=} {--email=} {--password=} {--generate-password : Generate a secure password (non-interactive)}', function () {
    $generatePassword = function (int $length = 20): string {
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits = '0123456789';
        $symbols = '!@#$%^&*()-_=+[]{};:,.?';

        $password = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        $all = $lower.$upper.$digits.$symbols;
        while (count($password) < $length) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($password) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    };

    $name = (string) ($this->option('name') ?: ($this->input->isInteractive() ? $this->ask('Name') : ''));
    while (true) {
        $validator = Validator::make(
            ['name' => $name],
            ['name' => ['required', 'string', 'max:255']],
        );

        if (! $validator->fails()) {
            break;
        }

        $this->error($validator->errors()->first('name'));

        if (! $this->input->isInteractive()) {
            return 1;
        }

        $name = (string) $this->ask('Name');
    }

    $email = (string) ($this->option('email') ?: ($this->input->isInteractive() ? $this->ask('Email') : ''));
    while (true) {
        $validator = Validator::make(
            ['email' => $email],
            // Avoid DNS validation here: it can be slow/hang on some systems and isn't required for setup.
            ['email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email']],
        );

        if (! $validator->fails()) {
            break;
        }

        $this->error($validator->errors()->first('email'));

        if (! $this->input->isInteractive()) {
            return 1;
        }

        $email = (string) $this->ask('Email');
    }

    $passwordRule = Password::min(12)
        ->letters()
        ->mixedCase()
        ->numbers()
        ->symbols();

    $passwordProvidedViaOption = (bool) $this->option('password');
    $password = (string) ($this->option('password') ?? '');

    if ($this->option('generate-password')) {
        $password = $generatePassword();
        $this->warn('Generated password (store this now; it will not be shown again):');
        $this->line($password);
    } elseif ($password === '') {
        if (! $this->input->isInteractive()) {
            $this->error('Password is required in non-interactive mode. Use --password= or --generate-password.');

            return 1;
        }

        $password = (string) $this->secret('Password (leave blank to generate a secure one)');

        if ($password === '') {
            $password = $generatePassword();
            $this->warn('Generated password (store this now; it will not be shown again):');
            $this->line($password);
        }
    }

    if ($passwordProvidedViaOption) {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', $passwordRule]],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return 1;
        }
    } elseif ($this->input->isInteractive() && ! $this->option('generate-password') && $password !== '') {
        while (true) {
            $confirm = (string) $this->secret('Confirm password');

            if (! hash_equals($password, $confirm)) {
                $this->error('Passwords do not match.');
                $password = (string) $this->secret('Password');

                continue;
            }

            $validator = Validator::make(
                ['password' => $password],
                ['password' => ['required', 'string', $passwordRule]],
            );

            if (! $validator->fails()) {
                break;
            }

            $this->error($validator->errors()->first('password'));
            $password = (string) $this->secret('Password');
        }
    }

    if (User::where('email', $email)->exists()) {
        $this->error('A user with that email already exists.');

        return 1;
    }

    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'is_admin' => true,
    ]);

    $user->forceFill([
        'email_verified_at' => now(),
    ])->save();

    $this->info('Administrator created successfully.');

    return 0;
})->purpose('Interactive application setup (creates an administrator)');

// Horizon maintenance tasks
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('horizon:clear-metrics')->hourly();
Schedule::command('horizon:clear')->daily();
Schedule::command('metrics:sync')->hourly();

Artisan::command('mail:test {to : Recipient email address}', function () {
    $to = (string) $this->argument('to');

    Mail::raw('Atlas mail test: '.now()->toIso8601String(), function ($message) use ($to) {
        $message->to($to)->subject('Atlas mail test');
    });

    $this->info("Sent test mail to {$to}.");
})->purpose('Send a test email using current mail configuration');
