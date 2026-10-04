<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'otp_token' => ['required', 'string'],
            'otp' => ['required', 'string', 'size:6'],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'otp.required' => 'Masukkan 6-digit kode OTP.',
            'otp.size' => 'Kode OTP harus tepat 6 angka.',
        ];
    }
}
