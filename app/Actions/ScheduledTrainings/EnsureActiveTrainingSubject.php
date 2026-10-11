<?php

namespace App\Actions\ScheduledTrainings;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class EnsureActiveTrainingSubject
{
    /**
     * Call inside the write transaction to serialize creation with archiving.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(User $user, array $attributes): void
    {
        $isTrainee = ($attributes['trainee_id'] ?? null) !== null;
        $column = $isTrainee ? 'trainee_id' : 'training_group_id';
        $query = $isTrainee ? $user->trainees() : $user->trainingGroups();
        $subject = $query->lockForUpdate()->find((int) ($attributes[$column] ?? 0));

        if ($subject === null || $subject->getAttribute('archived_at') !== null) {
            throw ValidationException::withMessages([
                $column => 'Клиент или группа недоступны. Архивную запись нужно сначала восстановить.',
            ]);
        }
    }
}
