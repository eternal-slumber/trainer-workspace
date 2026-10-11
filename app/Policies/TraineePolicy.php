<?php

namespace App\Policies;

use App\Models\Trainee;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TraineePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Trainee $trainee): Response
    {
        return $this->owns($user, $trainee);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Trainee $trainee): Response
    {
        return $this->owns($user, $trainee);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Trainee $trainee): Response
    {
        return $this->owns($user, $trainee);
    }

    public function restore(User $user, Trainee $trainee): Response
    {
        return $this->owns($user, $trainee);
    }

    private function owns(User $user, Trainee $trainee): Response
    {
        return $trainee->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
