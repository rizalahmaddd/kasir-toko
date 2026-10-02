<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\WithCrudActions;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Akun pengelola layanan (tanpa toko). Akun tidak dihapus supaya jejak audit dan riwayat
 * langganan tetap menunjuk ke orangnya; aksesnya cukup dicabut.
 */
#[Layout('layouts.app', ['heading' => 'Admin Platform'])]
#[Title('Admin Platform')]
class Admins extends Component
{
    use WithCrudActions;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    public function save(): void
    {
        $this->authorizeManage();

        $this->username = strtolower(trim($this->username));
        $this->email = strtolower(trim($this->email));
        $validated = $this->validate();

        if ($this->editingId) {
            $admin = $this->findAdmin($this->editingId);
            $admin->fill(collect($validated)->only(['name', 'username', 'email'])->all());

            if (filled($validated['password'])) {
                $admin->password = Hash::make($validated['password']);
            }

            $admin->save();
            $this->notify("Akun {$admin->name} diperbarui.");
        } else {
            $admin = User::query()->create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
            $admin->forceFill(['email_verified_at' => now(), 'is_platform_admin' => true])->save();
            $this->notify("Admin platform {$admin->name} ditambahkan.");
        }

        $this->closeModal();
    }

    public function toggleAccess(int $id): void
    {
        $this->authorizeManage();

        $admin = $this->findAdmin($id);

        if ($admin->is(Auth::user())) {
            $this->notify('Tidak bisa mencabut akses akun sendiri.', 'error');

            return;
        }

        $admin->forceFill(['is_platform_admin' => ! $admin->is_platform_admin])->save();

        if (! $admin->is_platform_admin) {
            $admin->tokens()->delete();
        }

        $this->notify($admin->is_platform_admin ? "Akses {$admin->name} dipulihkan." : "Akses {$admin->name} dicabut.");
    }

    public function render()
    {
        $query = User::query()
            ->whereNull('tenant_id')
            ->when($this->search, fn ($query) => $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('username', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")));

        $this->applySorting($query, ['name' => 'name', 'created_at' => 'created_at'], 'name', 'asc');

        return view('livewire.platform.admins', ['admins' => $query->paginate($this->perPage)]);
    }

    public function canManage(): bool
    {
        return auth()->user()->can('manage-platform');
    }

    protected function modelClass(): string
    {
        return User::class;
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'username', 'email', 'password']);
    }

    /**
     * @param  User  $record
     */
    protected function fillForm($record): void
    {
        abort_unless($record->tenant_id === null, 404);

        $this->name = $record->name;
        $this->username = $record->username;
        $this->email = $record->email;
    }

    protected function guardDelete($record): ?string
    {
        return 'Akun admin tidak dihapus. Cabut aksesnya saja.';
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return User::identityValidationMessages();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z][a-z0-9._]*$/', Rule::unique(User::class)->ignore($this->editingId)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->editingId)],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', Password::defaults()],
        ];
    }

    private function findAdmin(int $id): User
    {
        $admin = User::query()->whereNull('tenant_id')->find($id);
        abort_if($admin === null, 404);

        return $admin;
    }
}
