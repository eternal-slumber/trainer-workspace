<?php

namespace App\Services\Agent\DTO;

final readonly class ValidatedTrainingPlan
{
    /**
     * @param  list<string>  $warnings
     * @param  list<ValidatedTrainingPlanBlock>  $blocks
     */
    public function __construct(
        public string $title,
        public string $goal,
        public int $totalDurationMinutes,
        public ?string $aiReasoning,
        public array $warnings,
        public array $blocks,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'title' => $this->title, 'goal' => $this->goal, 'total_duration_minutes' => $this->totalDurationMinutes,
            'ai_reasoning' => $this->aiReasoning, 'warnings' => $this->warnings,
            'blocks' => array_map(fn (ValidatedTrainingPlanBlock $block): array => [
                'name' => $block->name, 'duration_minutes' => $block->durationMinutes,
                'position' => $block->position, 'notes' => $block->notes,
                'exercises' => array_map(fn (ValidatedTrainingPlanExercise $exercise): array => [
                    'exercise_id' => $exercise->exerciseId, 'duration_minutes' => $exercise->durationMinutes,
                    'position' => $exercise->position, 'sets' => $exercise->sets, 'repetitions' => $exercise->repetitions,
                    'rest_seconds' => $exercise->restSeconds, 'notes' => $exercise->notes,
                ], $block->exercises),
            ], $this->blocks),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
