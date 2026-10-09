<?php

namespace App\Actions\TrainingPlans;

use App\Models\ScheduledTraining;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveTrainingPlan
{
    /** @param array<string, mixed> $attributes */
    public function create(
        User $user,
        ScheduledTraining $scheduledTraining,
        array $attributes,
    ): TrainingPlan {
        return DB::transaction(function () use ($user, $scheduledTraining, $attributes): TrainingPlan {
            $scheduledTraining = $user->scheduledTrainings()
                ->lockForUpdate()
                ->findOrFail($scheduledTraining->id);

            $trainingPlan = new TrainingPlan;
            $trainingPlan->forceFill([
                ...$this->planAttributes($attributes),
                'scheduled_training_id' => $scheduledTraining->id,
                'trainee_id' => $scheduledTraining->trainee_id,
                'training_group_id' => $scheduledTraining->training_group_id,
                'source' => 'manual',
                'warnings' => [],
                'ai_reasoning' => null,
            ]);

            $user->trainingPlans()->save($trainingPlan);
            $this->replaceBlocks($trainingPlan, $attributes['blocks']);

            return $trainingPlan;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(TrainingPlan $trainingPlan, array $attributes): TrainingPlan
    {
        return DB::transaction(function () use ($trainingPlan, $attributes): TrainingPlan {
            $trainingPlan->update($this->planAttributes($attributes));
            $this->replaceBlocks($trainingPlan, $attributes['blocks']);

            return $trainingPlan;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function planAttributes(array $attributes): array
    {
        return Arr::only($attributes, [
            'title',
            'goal',
            'total_duration_minutes',
            'status',
            'notes',
        ]);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function replaceBlocks(TrainingPlan $trainingPlan, array $blocks): void
    {
        $trainingPlan->blocks()->delete();

        foreach ($blocks as $blockIndex => $blockAttributes) {
            $block = $trainingPlan->blocks()->create([
                ...Arr::only($blockAttributes, ['name', 'duration_minutes', 'notes']),
                'position' => $blockIndex + 1,
            ]);

            $this->createExercises($block, $blockAttributes['exercises']);
        }
    }

    /** @param list<array<string, mixed>> $exercises */
    private function createExercises(TrainingPlanBlock $block, array $exercises): void
    {
        foreach ($exercises as $exerciseIndex => $exerciseAttributes) {
            $block->exercises()->create([
                ...Arr::only($exerciseAttributes, [
                    'exercise_id',
                    'name',
                    'description',
                    'duration_minutes',
                    'sets',
                    'repetitions',
                    'rest_seconds',
                    'notes',
                ]),
                'position' => $exerciseIndex + 1,
            ]);
        }
    }
}
