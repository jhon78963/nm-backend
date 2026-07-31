<?php

namespace App\Directories\Teams\Policies;

use App\Administrations\Users\Models\User;
use App\Directories\Teams\Models\TeamPayment;

class TeamPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('team.getPaymentByMonth');
    }

    public function store(User $user): bool
    {
        return $user->can('team.storePayment');
    }

    public function update(User $user, TeamPayment $payment): bool
    {
        return $user->can('team.storePayment');
    }

    public function delete(User $user, TeamPayment $payment): bool
    {
        return $user->can('team.storePayment');
    }
}
