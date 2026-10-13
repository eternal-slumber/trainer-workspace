<?php

namespace App\Http\Requests;

use App\Models\ScheduledTraining;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReserveTrainingPlanGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $training = $this->route('scheduled_training');

        return $training instanceof ScheduledTraining
            && Gate::authorize('generateTrainingPlan', $training)->allowed();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['request_key' => ['required', 'string', 'uuid']];
    }
}
