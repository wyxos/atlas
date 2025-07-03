<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AddSuperAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:add-super-admin {email : The email address of the super admin} {--password= : Optional password for the super admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add a new super admin to the system or update an existing user to super admin';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Get the email from the command arguments
        $email = $this->argument('email');

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email format.');
            return 1;
        }

        // Check if user with this email already exists
        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            // Update existing user to super admin
            $existingUser->is_super_admin = true;
            $existingUser->save();

            $this->info("User {$email} has been updated to super admin successfully!");
            return 0;
        }

        // Get password from options or generate one
        $password = $this->option('password');
        $passwordWasGenerated = false;

        if (empty($password)) {
            $password = Str::password(12);
            $passwordWasGenerated = true;
        }

        // Create a name from the email (use part before @)
        $name = Str::before($email, '@');

        // Create the super admin user
        try {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_super_admin' => true,
            ]);

            $this->info('Super admin created successfully!');
            $this->info("Email: {$email}");

            if ($passwordWasGenerated) {
                $this->info("Generated password: {$password}");
                $this->warn('Please save this password as it will not be shown again.');
            } else {
                $this->info('Password: [HIDDEN]');
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to create super admin: ' . $e->getMessage());
            return 1;
        }
    }
}
