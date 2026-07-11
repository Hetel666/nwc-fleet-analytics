<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::query()->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('users.form', [
            'user' => new User(['role' => User::ROLE_VIEWER, 'active' => true]),
            'roles' => $this->roles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        User::create($this->validated($request));

        return redirect()->route('users.index')->with('status', __('app.saved'));
    }

    public function edit(User $user): View
    {
        return view('users.form', [
            'user' => $user,
            'roles' => $this->roles(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if ($request->user()?->is($user)) {
            $data['role'] = User::ROLE_ADMIN;
            $data['active'] = true;
        }

        if (($user->role === User::ROLE_ADMIN || $user->active) && ! $this->keepsActiveAdmin($user, $data)) {
            return back()
                ->withErrors(['role' => 'Ən azı bir aktiv admin qalmalıdır.'])
                ->withInput();
        }

        $user->update($data);

        return redirect()->route('users.index')->with('status', __('app.saved'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->is($user)) {
            return back()->withErrors(['user' => 'Öz istifadəçinizi silmək olmaz.']);
        }

        if ($user->role === User::ROLE_ADMIN && ! $this->hasAnotherActiveAdmin($user)) {
            return back()->withErrors(['user' => 'Son aktiv admini silmək olmaz.']);
        }

        $user->delete();

        return redirect()->route('users.index')->with('status', __('app.deleted'));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $passwordRules = $user?->exists
            ? ['nullable', 'confirmed', Password::min(8)]
            : ['required', 'confirmed', Password::min(8)];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'role' => ['required', Rule::in(array_keys($this->roles()))],
            'active' => ['nullable', 'boolean'],
            'password' => $passwordRules,
        ]);

        $data['active'] = $request->boolean('active');

        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        return $data;
    }

    private function roles(): array
    {
        return [
            User::ROLE_ADMIN => 'Admin',
            User::ROLE_VIEWER => 'Viewer',
        ];
    }

    private function keepsActiveAdmin(User $user, array $data): bool
    {
        if (($data['role'] ?? $user->role) === User::ROLE_ADMIN && ($data['active'] ?? $user->active)) {
            return true;
        }

        return $this->hasAnotherActiveAdmin($user);
    }

    private function hasAnotherActiveAdmin(User $user): bool
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->where('role', User::ROLE_ADMIN)
            ->where('active', true)
            ->exists();
    }
}
