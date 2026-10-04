<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['login' => 'username, email, atau nomor HP'];
    }

    /**
     * Same detection as the web LoginForm: "@" means email, digits (optionally "+", spaces,
     * dashes) mean phone number, anything else is a username.
     *
     * @return array{email?: string, phone?: string, username?: string}
     */
    public function identifier(): array
    {
        $login = trim($this->string('login'));

        return match (true) {
            str_contains($login, '@') => ['email' => Str::lower($login)],
            (bool) preg_match('/^\+?[\d\s\-()]{8,}$/', $login) => ['phone' => User::normalizePhone($login)],
            default => ['username' => Str::lower($login)],
        };
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')).'|'.$this->ip());
    }
}
