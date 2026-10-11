<?php

namespace App\Policies;

use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TrainingGroupPolicy
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
    public function view(User $user, TrainingGroup $trainingGroup): Response
    {
        return $this->owns($user, $trainingGroup);
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
    public function update(User $user, TrainingGroup $trainingGroup): Response
    {
        return $this->owns($user, $trainingGroup);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TrainingGroup $trainingGroup): Response
    {
        return $this->owns($user, $trainingGroup);
    }

    public function restore(User $user, TrainingGroup $trainingGroup): Response
    {
        return $this->owns($user, $trainingGroup);
    }

    private function owns(User $user, TrainingGroup $trainingGroup): Response
    {
        return $trainingGroup->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
