<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['status', 'actual_weight', 'actual_repetitions', 'comment'])]
class TrainingSessionExercise extends Model
{
    /** @var list<string> */
    public const STATUSES = ['pending', 'completed', 'skipped'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @return BelongsTo<TrainingSession, $this> */
    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'block_position' => 'integer',
            'position' => 'integer',
            'snapshot' => 'array',
            'actual_weight' => 'decimal:2',
        ];
    }
}
