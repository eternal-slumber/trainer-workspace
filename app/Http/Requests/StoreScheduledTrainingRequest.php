<?php

namespace App\Http\Requests;

use App\Models\ScheduledTraining;
use App\Models\Trainee;
use App\Models\TrainingGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduledTrainingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ScheduledTraining::class) ?? false;
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
                    ->where(fn (Builder $query) => $query->where('user_id', $userId)->whereNull('archived_at')),
            ],
            'training_group_id' => [
                'nullable',
                'integer',
                'required_without:trainee_id',
                'prohibits:trainee_id',
                Rule::exists(TrainingGroup::class, 'id')
                    ->where(fn (Builder $query) => $query->where('user_id', $userId)->whereNull('archived_at')),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(ScheduledTraining::STATUSES)],
            'color' => ['sometimes', 'required', Rule::in(ScheduledTraining::COLORS)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
