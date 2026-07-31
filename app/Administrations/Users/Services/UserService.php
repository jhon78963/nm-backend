<?php

namespace App\Administrations\Users\Services;

use App\Shared\Foundation\Services\ModelService;
use App\Administrations\Users\Models\User;

class UserService extends ModelService
{
    public function __construct(User $user)
    {
        parent::__construct($user);
    }

    public function checkUser(string $email, string $username): array
    {
        return [
            'email'    => $this->existsBy('email', $email),
            'username' => $this->existsBy('username', $username),
        ];
    }

    private function existsBy(string $column, string $value): bool
    {
        return $this->model
            ->where($column, $value)
            ->where('is_deleted', false)
            ->exists();
    }
}
