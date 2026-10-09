<?php

namespace App\Http\Requests;

use App\Models\ScheduledTraining;
use App\Models\Trainee;
use App\Models\TrainingGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateScheduledTrainingRequest extends FormRequest
{
    /** @var list<int|string> */
    private array $submittedFields = [];

    /** @return array<string, mixed> */
    public function validatedForUpdate(ScheduledTraining $scheduledTraining): array
    {
        $attributes = Arr::only($this->validated(), $this->submittedFields);
        $rules = $this->rules();

        Validator::make(
            array_replace($scheduledTraining->only(array_keys($rules)), $attributes),
            $rules,
            $this->messages(),
            $this->attributes(),
        )->validate();

        return $attributes;
    }

    protected function prepareForValidation(): void
    {
        $this->submittedFields = array_keys($this->all());

        $scheduledTraining = $this->route('scheduled_training');

        if (! $scheduledTraining instanceof ScheduledTraining) {
            return;
        }

        $this->mergeIfMissing([
            'trainee_id' => $scheduledTraining->trainee_id,
            'training_group_id' => $scheduledTraining->training_group_id,
            'starts_at' => $scheduledTraining->starts_at,
            'ends_at' => $scheduledTraining->ends_at,
            'location' => $scheduledTraining->location,
            'status' => $scheduledTraining->status,
            'color' => $scheduledTraining->color,
            'notes' => $scheduledTraining->notes,
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $scheduledTraining = $this->route('scheduled_training');

        return $scheduledTraining instanceof ScheduledTraining
            && ($this->user()?->can('update', $scheduledTraining) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getAuthIdentifier();

        return [
            'trainee_id' => [
                'nullable',
                'integer',
                'required_without:training_group_id',
                'prohibits:training_group_id',
                Rule::exists(Trainee::class, 'id')
                    ->where(fn (Builder $query) => $query->where('user_id', $userId)),
            ],
            'training_group_id' => [
                'nullable',
                'integer',
                'required_without:trainee_id',
                'prohibits:trainee_id',
                Rule::exists(TrainingGroup::class, 'id')
                    ->where(fn (Builder $query) => $query->where('user_id', $userId)),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(ScheduledTraining::STATUSES)],
            'color' => ['required', Rule::in(ScheduledTraining::COLORS)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
