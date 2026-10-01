<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Auth\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\V1\Auth\CurrentUserResource;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

#[ApiTag('Profil', 'Akun')]
class ProfileController extends Controller
{
    /**
     * Ubah profil.
     *
     * Mengganti email akan menghapus status verifikasi email.
     */
    public function update(UpdateProfileRequest $request): CurrentUserResource
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return new CurrentUserResource($user);
    }

    /**
     * Ganti password.
     *
     * Kirim `revoke_other_tokens: true` untuk sekaligus mengeluarkan akun dari perangkat lain.
     */
    public function password(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->update(['password' => Hash::make($request->string('password'))]);

        if ($request->boolean('revoke_other_tokens')) {
            $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
        }

        return response()->noContent();
    }
}
