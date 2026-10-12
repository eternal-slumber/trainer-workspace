<?php

namespace App\Actions\TrainingPlans;

use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CompleteTrainingPlanAction
{
    public function execute(User $user, TrainingPlan $trainingPlan): void
    {
        Gate::forUser($user)->authorize('complete', $trainingPlan);

        DB::transaction(function () use ($trainingPlan): void {
            $scheduledTraining = $trainingPlan->scheduledTraining()->lockForUpdate()->firstOrFail();
            $lockedTrainingPlan = $scheduledTraining->trainingPlan()->lockForUpdate()->findOrFail($trainingPlan->id);

            if ($scheduledTraining->status === 'completed' && $lockedTrainingPlan->status === 'completed') {
                return;
            }

            if ($scheduledTraining->status !== 'planned'
                || ! in_array($lockedTrainingPlan->status, TrainingPlan::EDITABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Завершить можно только запланированное занятие с черновым или утверждённым планом. Проверьте статусы занятия и плана.',
                ]);
            }

            $lockedTrainingPlan->update(['status' => 'completed']);
            $scheduledTraining->update(['status' => 'completed']);
        });
    }
}
