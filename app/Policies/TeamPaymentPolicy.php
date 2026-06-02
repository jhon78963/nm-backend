<?php

namespace App\Policies;

use App\Administration\User\Models\User;
use App\Directory\Team\Models\TeamPayment;

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
