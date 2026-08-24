<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'lexible:admin
        {--name=    : Display name}
        {--email=   : Login email}
        {--password= : Leave empty to generate one}';

    protected $description = 'Create an admin panel account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Ism', 'Admin');
        $email = $this->option('email') ?: $this->ask('Email');

        if (Admin::where('email', $email)->exists()) {
            $this->error("{$email} allaqachon mavjud.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(12, symbols: false);

        Admin::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->newLine();
        $this->info('✅ Admin yaratildi');
        $this->table(['Email', 'Parol'], [[$email, $password]]);
        $this->comment('Panel: '.rtrim(config('app.url'), '/').'/admin');

        return self::SUCCESS;
    }
}
