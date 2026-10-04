<?php

namespace App\Livewire\Forms;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    /**
     * Username, email, atau nomor HP: jenisnya ditebak dari isinya (lihat credentials()).
     */
    #[Validate('required|string|max:255', as: 'username, email, atau nomor HP')]
    public string $login = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->credentials(), $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'form.login' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Petakan isian login ke kolom yang tepat: ada "@" berarti email, hanya angka (boleh diawali
     * "+" dan berisi spasi/strip) berarti nomor HP, selain itu username.
     *
     * @return array{password: string, email?: string, phone?: string, username?: string}
     */
    protected function credentials(): array
    {
        $login = trim($this->login);

        $identifier = match (true) {
            str_contains($login, '@') => ['email' => Str::lower($login)],
            (bool) preg_match('/^\+?[\d\s\-()]{8,}$/', $login) => ['phone' => User::normalizePhone($login)],
            default => ['username' => Str::lower($login)],
        };

        return [...$identifier, 'password' => $this->password];
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->login).'|'.request()->ip());
    }
}
