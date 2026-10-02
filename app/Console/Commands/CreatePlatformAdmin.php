<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:platform-admin
    {--name= : Nama admin platform}
    {--email= : Email admin platform}
    {--username= : Username admin platform}
    {--password= : Password (min. 8 karakter)}')]
#[Description('Buat akun admin platform SaaS (tanpa toko) untuk membuka panel Platform')]
class CreatePlatformAdmin extends Command
{
    public function handle(CurrentTenant $currentTenant): int
    {
        $data = [
            'name' => $this->option('name') ?: text('Nama', required: true),
            'email' => $this->option('email') ?: text('Email', required: true),
            'username' => strtolower(trim($this->option('username') ?: text('Username', default: 'platform', required: true))),
            'password' => $this->option('password') ?: password('Password (min. 8 karakter)', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => User::usernameRules(),
            'password' => ['required', 'string', 'min:8'],
        ], User::identityValidationMessages());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = $currentTenant->run(null, function () use ($data) {
            $user = User::query()->create($data);
            $user->forceFill(['email_verified_at' => now(), 'is_platform_admin' => true])->save();

            return $user;
        });

        $this->components->info("Admin platform {$user->email} dibuat. Panel: ".route('platform.tenants'));

        return self::SUCCESS;
    }
}
