<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

interface UserDataProviderInterface
{
    /**
     * @return array{name: string, email: string}|null
     */
    public function getUserInfo(string $userId): ?array;

    /**
     * @param  array<string>  $userIds
     * @return array<string, array{name: string, email: string}>
     */
    public function getUsersInfo(array $userIds): array;

    /**
     * @return array<string>
     */
    public function getUserRoles(string $userId): array;

    /**
     * @param  array<string>  $roles
     */
    public function userHasAnyRole(string $userId, array $roles): bool;

    /**
     * The account using the email, compared without case.
     *
     * @return array{id: string, email_verified: bool}|null
     */
    public function findAccountByEmail(string $email): ?array;
}
