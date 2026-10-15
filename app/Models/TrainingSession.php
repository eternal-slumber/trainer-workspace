<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status', 'started_at', 'completed_at', 'comment'])]
class TrainingSession extends Model
{
    /** @var list<string> */
    public const STATUSES = ['in_progress', 'completed'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'in_progress',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ScheduledTraining, $this> */
    public function scheduledTraining(): BelongsTo
    {
        return $this->belongsTo(ScheduledTraining::class);
    }

    /** @return BelongsTo<TrainingPlan, $this> */
    public function trainingPlan(): BelongsTo
    {
        return $this->belongsTo(TrainingPlan::class);
    }

    /** @return BelongsTo<Trainee, $this> */
    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /** @return BelongsTo<TrainingGroup, $this> */
    public function trainingGroup(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class);
    }

    /** @return HasMany<TrainingSessionExercise, $this> */
    public function exercises(): HasMany
    {
        return $this->hasMany(TrainingSessionExercise::class)
            ->orderBy('block_position')->orderBy('position');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'snapshot' => 'array',
        ];
    }
}
