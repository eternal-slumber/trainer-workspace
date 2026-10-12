<?php

namespace App\Services\Agent;

use App\Models\ScheduledTraining;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\User;
use App\Services\Agent\DTO\ValidatedTrainingPlan;
use App\Services\Agent\DTO\ValidatedTrainingPlanBlock;
use App\Services\AI\AiClientInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TrainingAgentService
{
    public function __construct(
        private TrainingContextBuilder $contextBuilder,
        private TrainingPromptBuilder $promptBuilder,
        private AiClientInterface $aiClient,
        private TrainingPlanValidator $planValidator,
    ) {}

    public function generatePlanForScheduledTraining(
        User $user,
        ScheduledTraining $scheduledTraining,
    ): TrainingPlan {
        Gate::forUser($user)->authorize('generateTrainingPlan', $scheduledTraining);
        $scheduledTraining = $user->scheduledTrainings()->findOrFail($scheduledTraining->id);
        $this->ensurePlanCanBeCreated($scheduledTraining);

        $context = $this->contextBuilder->buildForScheduledTraining($user, $scheduledTraining);
        $messages = $this->promptBuilder->buildTrainingPlanPrompt($context);
        $aiResponse = $this->aiClient->chat($messages, [
            'user_id' => $user->id,
            'max_tokens' => (int) config('ai.max_tokens'),
            'response_format' => $this->responseFormat(),
        ]);
        $validatedPlan = $this->planValidator->validate($aiResponse->content, $context);

        return DB::transaction(
            function () use ($user, $scheduledTraining, $validatedPlan): TrainingPlan {
                $lockedScheduledTraining = ScheduledTraining::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->findOrFail($scheduledTraining->id);

                $this->ensurePlanCanBeCreated($lockedScheduledTraining);

                return $this->storePlan($user, $lockedScheduledTraining, $validatedPlan);
            },
            attempts: 3,
        );
    }

    private function ensurePlanCanBeCreated(ScheduledTraining $scheduledTraining): void
    {
        if ($scheduledTraining->status === 'completed') {
            throw ValidationException::withMessages([
                'scheduled_training_id' => 'Нельзя создать план для завершённого занятия.',
            ]);
        }

        if ($scheduledTraining->trainingPlan()->exists()) {
            throw new TrainingPlanAlreadyExistsException(
                'Для этой тренировки план уже создан.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFormat(): array
    {
        $provider = (string) config('ai.provider');
        $responseFormat = config("ai.{$provider}.response_format", ['type' => 'json_object']);

        return is_array($responseFormat) ? $responseFormat : ['type' => 'json_object'];
    }

    private function storePlan(
        User $user,
        ScheduledTraining $scheduledTraining,
        ValidatedTrainingPlan $validatedPlan,
    ): TrainingPlan {
        $trainingPlan = new TrainingPlan;
        $trainingPlan->forceFill([
            'scheduled_training_id' => $scheduledTraining->id,
            'trainee_id' => $scheduledTraining->trainee_id,
            'training_group_id' => $scheduledTraining->training_group_id,
            'title' => $validatedPlan->title,
            'goal' => $validatedPlan->goal,
            'total_duration_minutes' => $validatedPlan->totalDurationMinutes,
            'status' => 'draft',
            'source' => 'ai',
            'notes' => null,
            'warnings' => $validatedPlan->warnings,
            'ai_reasoning' => $validatedPlan->aiReasoning,
        ]);

        $user->trainingPlans()->save($trainingPlan);

        foreach ($validatedPlan->blocks as $validatedBlock) {
            $this->storeBlock($trainingPlan, $validatedBlock);
        }

        return $trainingPlan;
    }

    private function storeBlock(
        TrainingPlan $trainingPlan,
        ValidatedTrainingPlanBlock $validatedBlock,
    ): void {
        $block = $trainingPlan->blocks()->create([
            'name' => $validatedBlock->name,
            'duration_minutes' => $validatedBlock->durationMinutes,
            'position' => $validatedBlock->position,
            'notes' => $validatedBlock->notes,
        ]);

        $this->storeExercises($block, $validatedBlock);
    }

    private function storeExercises(
        TrainingPlanBlock $block,
        ValidatedTrainingPlanBlock $validatedBlock,
    ): void {
        foreach ($validatedBlock->exercises as $validatedExercise) {
            $block->exercises()->create([
                'exercise_id' => $validatedExercise->exerciseId,
                'name' => $validatedExercise->name,
                'description' => $validatedExercise->description,
                'duration_minutes' => $validatedExercise->durationMinutes,
                'sets' => $validatedExercise->sets,
                'repetitions' => $validatedExercise->repetitions,
                'rest_seconds' => $validatedExercise->restSeconds,
                'position' => $validatedExercise->position,
                'notes' => $validatedExercise->notes,
            ]);
        }
    }
}
