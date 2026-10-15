<?php

namespace App\Models;

use Database\Factories\TrainingPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['title', 'goal', 'total_duration_minutes', 'status', 'notes'])]
class TrainingPlan extends Model
{
    /** @use HasFactory<TrainingPlanFactory> */
    use HasFactory;

    /** @var list<string> */
    public const STATUSES = ['draft', 'approved', 'completed', 'generating', 'failed'];

    /** @var list<string> */
    public const MANUAL_STATUSES = ['draft', 'approved', 'completed'];

    /** @var list<string> */
    public const EDITABLE_STATUSES = ['draft', 'approved'];

    /** @var list<string> */
    public const SOURCES = ['manual', 'ai'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'source' => 'manual',
        'warnings' => '[]',
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

    /** @return HasMany<TrainingPlanBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(TrainingPlanBlock::class)->orderBy('position');
    }

    /** @return HasOne<TrainingNote, $this> */
    public function trainingNote(): HasOne
    {
        return $this->hasOne(TrainingNote::class);
    }

    /** @return HasOne<TrainingSession, $this> */
    public function trainingSession(): HasOne
    {
        return $this->hasOne(TrainingSession::class);
    }

    /**
     * @param  Builder<TrainingPlan>  $query
     * @return Builder<TrainingPlan>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /**
     * @param  Builder<TrainingPlan>  $query
     * @return Builder<TrainingPlan>
     */
    public function scopeLatestScheduled(Builder $query): Builder
    {
        $scheduledTraining = new ScheduledTraining;

        return $query->orderByDesc(
            $scheduledTraining->newQuery()
                ->select('starts_at')
                ->whereColumn(
                    $scheduledTraining->qualifyColumn($scheduledTraining->getKeyName()),
                    $query->getModel()->qualifyColumn('scheduled_training_id'),
                ),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_duration_minutes' => 'integer',
            'warnings' => 'array',
        ];
    }
}
