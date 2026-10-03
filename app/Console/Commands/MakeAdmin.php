<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'marketplace:make-admin {email}';

    protected $description = 'Promote an existing user to admin';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => UserRole::Admin])->save();
        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
