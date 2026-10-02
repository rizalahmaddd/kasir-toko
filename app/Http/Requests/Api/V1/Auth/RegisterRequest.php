<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => is_string($this->username) ? strtolower(trim($this->username)) : $this->username,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'phone' => filled($this->phone) ? User::normalizePhone((string) $this->phone) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...self::accountRules(),
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * Dipakai juga halaman daftar di web supaya aturannya sama persis.
     *
     * @return array<string, mixed>
     */
    public static function accountRules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'min:3', 'max:150'],
            'name' => ['required', 'string', 'max:255'],
            'username' => User::usernameRules(),
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => User::phoneRules(),
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return User::identityValidationMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['shop_name' => 'nama toko'];
    }
}
