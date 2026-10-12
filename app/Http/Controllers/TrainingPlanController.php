<?php

namespace App\Http\Controllers;

use App\Actions\TrainingPlans\SaveTrainingPlan;
use App\Http\Requests\StoreTrainingPlanRequest;
use App\Http\Requests\UpdateTrainingPlanRequest;
use App\Models\Exercise;
use App\Models\ScheduledTraining;
use App\Models\TrainingPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TrainingPlanController extends Controller
{
    public function __construct(private SaveTrainingPlan $saveTrainingPlan) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TrainingPlan::class);

        $trainingPlans = $request->user()
            ->trainingPlans()
            ->with([
                'scheduledTraining:id,starts_at,ends_at,location,status',
                'trainee:id,name',
                'trainingGroup:id,name',
            ])
            ->withCount('blocks')
            ->latest()
            ->get()
            ->map($this->toListData(...));

        return Inertia::render('training-plans/index', [
            'trainingPlans' => $trainingPlans,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', TrainingPlan::class);

        $formOptions = $this->formOptions($request);
        $requestedScheduledTrainingId = $request->integer('scheduled_training');

        return Inertia::render('training-plans/create', [
            ...$formOptions,
            'selectedScheduledTrainingId' => $formOptions['scheduledTrainings']
                ->contains('id', $requestedScheduledTrainingId)
                    ? $requestedScheduledTrainingId
                    : null,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTrainingPlanRequest $request): RedirectResponse
    {
        $attributes = $request->validated();
        $scheduledTraining = $request->user()
            ->scheduledTrainings()
            ->findOrFail((int) $attributes['scheduled_training_id']);

        $trainingPlan = $this->saveTrainingPlan->create(
            $request->user(),
            $scheduledTraining,
            $attributes,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Training plan created.')]);

        return to_route('training-plans.show', $trainingPlan);
    }

    /**
     * Display the specified resource.
     */
    public function show(TrainingPlan $trainingPlan): Response
    {
        Gate::authorize('view', $trainingPlan);

        return Inertia::render('training-plans/show', [
            'trainingPlan' => $this->toPageData($this->loadPlan($trainingPlan)),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, TrainingPlan $trainingPlan): Response|RedirectResponse
    {
        Gate::authorize('update', $trainingPlan);

        if ($trainingPlan->status === 'completed' || $trainingPlan->scheduledTraining->status === 'completed') {
            return to_route('training-plans.show', $trainingPlan);
        }

        return Inertia::render('training-plans/edit', [
            'trainingPlan' => $this->toPageData($this->loadPlan($trainingPlan)),
            'exercises' => $this->exerciseOptions($request),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        UpdateTrainingPlanRequest $request,
        TrainingPlan $trainingPlan,
    ): RedirectResponse {
        $this->saveTrainingPlan->update($trainingPlan, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Training plan updated.')]);

        return to_route('training-plans.show', $trainingPlan);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TrainingPlan $trainingPlan): RedirectResponse
    {
        Gate::authorize('delete', $trainingPlan);

        $trainingPlan->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Training plan deleted.')]);

        return to_route('training-plans.index');
    }

    /**
     * @return array{
     *     scheduledTrainings: Collection<int, covariant array<string, mixed>>,
     *     exercises: Collection<int, covariant array<string, mixed>>
     * }
     */
    private function formOptions(Request $request): array
    {
        return [
            'scheduledTrainings' => $request->user()
                ->scheduledTrainings()
                ->where('status', '!=', 'completed')
                ->whereDoesntHave('trainingPlan')
                ->with(['trainee:id,name', 'trainingGroup:id,name'])
                ->orderBy('starts_at')
                ->get()
                ->map(fn (ScheduledTraining $scheduledTraining): array => [
                    'id' => $scheduledTraining->id,
                    'subject_name' => $scheduledTraining->trainee_id !== null
                        ? $scheduledTraining->trainee->name
                        : $scheduledTraining->trainingGroup->name,
                    'starts_at' => $scheduledTraining->starts_at->toIso8601String(),
                    'ends_at' => $scheduledTraining->ends_at->toIso8601String(),
                    'location' => $scheduledTraining->location,
                    'status' => $scheduledTraining->status,
                ]),
            'exercises' => $this->exerciseOptions($request),
        ];
    }

    /** @return Collection<int, covariant array<string, mixed>> */
    private function exerciseOptions(Request $request): Collection
    {
        return Exercise::query()
            ->visibleTo($request->user())
            ->select(['id', 'name', 'description', 'duration_minutes', 'is_system'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Exercise $exercise): array => [
                'id' => $exercise->id,
                'name' => $exercise->name,
                'description' => $exercise->description,
                'duration_minutes' => $exercise->duration_minutes,
                'is_system' => $exercise->is_system,
            ]);
    }

    private function loadPlan(TrainingPlan $trainingPlan): TrainingPlan
    {
        return $trainingPlan->load([
            'scheduledTraining:id,starts_at,ends_at,location,status',
            'trainee:id,name',
            'trainingGroup:id,name',
            'blocks.exercises',
            'trainingNote',
        ]);
    }

    /** @return array<string, mixed> */
    private function toListData(TrainingPlan $trainingPlan): array
    {
        return [
            'id' => $trainingPlan->id,
            'title' => $trainingPlan->title,
            'goal' => $trainingPlan->goal,
            'total_duration_minutes' => $trainingPlan->total_duration_minutes,
            'status' => $trainingPlan->status,
            'source' => $trainingPlan->source,
            'blocks_count' => $trainingPlan->blocks_count,
            'subject_name' => $trainingPlan->trainee_id !== null
                ? $trainingPlan->trainee->name
                : $trainingPlan->trainingGroup->name,
            'scheduled_training' => [
                'id' => $trainingPlan->scheduledTraining->id,
                'starts_at' => $trainingPlan->scheduledTraining->starts_at->toIso8601String(),
                'ends_at' => $trainingPlan->scheduledTraining->ends_at->toIso8601String(),
                'location' => $trainingPlan->scheduledTraining->location,
                'status' => $trainingPlan->scheduledTraining->status,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function toPageData(TrainingPlan $trainingPlan): array
    {
        return [
            ...$trainingPlan->only([
                'id',
                'scheduled_training_id',
                'trainee_id',
                'training_group_id',
                'title',
                'goal',
                'total_duration_minutes',
                'status',
                'source',
                'notes',
                'warnings',
                'ai_reasoning',
            ]),
            'subject_name' => $trainingPlan->trainee_id !== null
                ? $trainingPlan->trainee->name
                : $trainingPlan->trainingGroup->name,
            'scheduled_training' => [
                'id' => $trainingPlan->scheduledTraining->id,
                'starts_at' => $trainingPlan->scheduledTraining->starts_at->toIso8601String(),
                'ends_at' => $trainingPlan->scheduledTraining->ends_at->toIso8601String(),
                'location' => $trainingPlan->scheduledTraining->location,
                'status' => $trainingPlan->scheduledTraining->status,
            ],
            'blocks' => $trainingPlan->blocks->map(fn ($block): array => [
                ...$block->only(['id', 'name', 'duration_minutes', 'position', 'notes']),
                'exercises' => $block->exercises->map(fn ($exercise): array => $exercise->only([
                    'id',
                    'exercise_id',
                    'name',
                    'description',
                    'duration_minutes',
                    'sets',
                    'repetitions',
                    'rest_seconds',
                    'position',
                    'notes',
                ]))->all(),
            ]),
            'training_note' => $trainingPlan->trainingNote?->only([
                'id',
                'intensity',
                'result',
                'tags',
                'note',
            ]),
        ];
    }
}
