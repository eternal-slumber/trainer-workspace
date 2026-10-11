<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTraineeRequest;
use App\Http\Requests\UpdateTraineeRequest;
use App\Models\Trainee;
use App\Models\TrainingPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TraineeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Trainee::class);

        $archived = $request->boolean('archived');

        return Inertia::render('trainees/index', [
            'trainees' => $request->user()
                ->trainees()
                ->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->active())
                ->select(['id', 'name', 'age', 'level', 'goal'])
                ->latest()
                ->get(),
            'archived' => $archived,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Trainee::class);

        return Inertia::render('trainees/create');
    }

    public function store(StoreTraineeRequest $request): RedirectResponse
    {
        $trainee = $request->user()->trainees()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Trainee created.')]);

        return to_route('trainees.show', $trainee);
    }

    public function show(Trainee $trainee): Response
    {
        Gate::authorize('view', $trainee);

        return Inertia::render('trainees/show', [
            'trainee' => $trainee->only([
                'id',
                'name',
                'age',
                'level',
                'goal',
                'restrictions',
                'notes',
                'archived_at',
            ]),
            'trainingHistory' => $this->trainingHistory($trainee),
            'agentMemories' => $trainee->agentMemories()
                ->where('user_id', $trainee->user_id)
                ->select(['id', 'type', 'content', 'importance', 'is_active'])
                ->orderByDesc('is_active')
                ->orderByDesc('importance')
                ->latest()
                ->get(),
        ]);
    }

    public function edit(Trainee $trainee): Response
    {
        Gate::authorize('update', $trainee);

        return Inertia::render('trainees/edit', [
            'trainee' => $trainee->only([
                'id',
                'name',
                'age',
                'level',
                'goal',
                'restrictions',
                'notes',
                'archived_at',
            ]),
        ]);
    }

    public function update(UpdateTraineeRequest $request, Trainee $trainee): RedirectResponse
    {
        $trainee->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Trainee updated.')]);

        return to_route('trainees.show', $trainee);
    }

    public function destroy(Trainee $trainee): RedirectResponse
    {
        Gate::authorize('delete', $trainee);

        $trainee->archive();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Клиент перемещён в архив.']);

        return to_route('trainees.index');
    }

    public function restore(Trainee $trainee): RedirectResponse
    {
        Gate::authorize('restore', $trainee);

        $trainee->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Клиент восстановлен.']);

        return to_route('trainees.show', $trainee);
    }

    /** @return Collection<int, covariant array<string, mixed>> */
    private function trainingHistory(Trainee $trainee): Collection
    {
        return $trainee->trainingPlans()
            ->completed()
            ->latestScheduled()
            ->with([
                'scheduledTraining:id,starts_at',
                'trainingNote:id,training_plan_id,intensity,result,tags,note',
            ])
            ->limit(10)
            ->get([
                'id',
                'scheduled_training_id',
                'title',
                'goal',
                'total_duration_minutes',
                'status',
            ])
            ->map(fn (TrainingPlan $trainingPlan): array => [
                'id' => $trainingPlan->id,
                'title' => $trainingPlan->title,
                'goal' => $trainingPlan->goal,
                'total_duration_minutes' => $trainingPlan->total_duration_minutes,
                'status' => $trainingPlan->status,
                'starts_at' => $trainingPlan->scheduledTraining->starts_at->toIso8601String(),
                'training_note' => $trainingPlan->trainingNote?->only([
                    'intensity',
                    'result',
                    'tags',
                    'note',
                ]),
            ]);
    }
}
