<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;

final readonly class UserDataProvider implements UserDataProviderInterface
{
    /**
     * @return array{name: string, email: string}|null
     */
    public function getUserInfo(string $userId): ?array
    {
        $user = UserModel::find($userId, ['id', 'name', 'email']);

        if ($user === null) {
            return null;
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * @param  array<string>  $userIds
     * @return array<string, array{name: string, email: string}>
     */
    public function getUsersInfo(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $users = UserModel::whereIn('id', $userIds)->get(['id', 'name', 'email']);

        $result = [];
        foreach ($users as $user) {
            $result[$user->id] = [
                'name' => $user->name,
                'email' => $user->email,
            ];
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    public function getUserRoles(string $userId): array
    {
        $user = UserModel::find($userId);

        if ($user === null) {
            return [];
        }

        return $user->roles()->pluck('name')->toArray();
    }

    /**
     * @param  array<string>  $roles
     */
    public function userHasAnyRole(string $userId, array $roles): bool
    {
        if ($roles === []) {
            return true;
        }

        $userRoles = $this->getUserRoles($userId);

        return array_intersect($userRoles, $roles) !== [];
    }
}
