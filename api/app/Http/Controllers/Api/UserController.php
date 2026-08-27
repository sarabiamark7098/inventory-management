<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only account management.
 *
 * Accounts are provisioned here rather than self-registered; a new user simply
 * receives a sign-in code the first time they enter their address.
 *
 * Every change here lands in the activity log through the model's
 * `RecordsActivity` trait. Provisioning an account, changing someone's role and
 * deactivating a colleague are the most consequential actions in the system,
 * and a history that covered laptops but not those would be the wrong way
 * round.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($search = $request->query('search')) {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }
        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        return response()->json([
            'data' => $query->orderBy('name')->get()->map->toClientArray()->all(),
            'roles' => collect(Role::ALL)
                ->map(fn ($r) => ['value' => $r, 'label' => Role::label($r)])
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', new AllowedEmailDomain],
            'role' => ['required', Rule::in(Role::ALL)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'role' => $data['role'],
            'is_active' => true,
        ]);

        return response()->json(['user' => $user->toClientArray()], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
                new AllowedEmailDomain,
            ],
            'role' => ['sometimes', 'required', Rule::in(Role::ALL)],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $this->guardLastAdmin($request, $user, $data);

        $user->fill(array_filter([
            'name' => $data['name'] ?? null,
            'email' => isset($data['email']) ? strtolower(trim($data['email'])) : null,
            'role' => $data['role'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('isActive', $data)) {
            $user->is_active = $data['isActive'];
        }

        $user->save();

        // A deactivated or demoted account must lose its existing sessions.
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json(['user' => $user->fresh()->toClientArray()]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $this->guardLastAdmin($request, $user, ['isActive' => false]);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Account deleted.']);
    }

    /**
     * Locking every admin out of the system is unrecoverable without database
     * access, so the last active admin cannot be demoted, deactivated, or deleted.
     */
    protected function guardLastAdmin(Request $request, User $user, array $data): void
    {
        if (! $user->isAdmin()) {
            return;
        }

        $losingAdmin = (isset($data['role']) && $data['role'] !== Role::ADMIN)
            || (array_key_exists('isActive', $data) && $data['isActive'] === false);

        if (! $losingAdmin) {
            return;
        }

        $remaining = User::where('role', Role::ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'role' => 'This is the last active admin. Promote another admin first.',
            ]);
        }
    }
}
