<?php

namespace Cla\GenerateAuditReport\Models;

use Cla\GenerateAuditReport\Enums\AuditStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Base AuditSession model.
 *
 * The consuming application MUST extend this class and add the
 * survey(), response(), and initiator() relationships, since those
 * reference application-specific Eloquent models that the package
 * cannot know about.
 *
 * Example in the app:
 *
 *   class AuditSession extends \Cla\GenerateAuditReport\Models\AuditSession
 *   {
 *       public function survey()   { return $this->belongsTo(Survey::class); }
 *       public function response() { return $this->belongsTo(Response::class); }
 *       public function initiator(){ return $this->belongsTo(User::class, 'initiated_by'); }
 *   }
 */
class AuditSession extends Model
{
    protected $fillable = [
        'survey_id',
        'response_id',
        'auditable_type',
        'auditable_id',
        'initiated_by',
        'provider',
        'thread_id',
        'status',
        'current_step',
        'step_label',
        'error_message',
        'started_at',
        'completed_at',
        'context_prompt',
        'context_document_ids',
        'context_language',
    ];

    protected $casts = [
        'status'               => AuditStatus::class,
        'current_step'         => 'integer',
        'started_at'           => 'datetime',
        'completed_at'         => 'datetime',
        'context_document_ids' => 'array',
    ];

    public function report(): HasOne
    {
        return $this->hasOne(AuditReport::class);
    }

    public function stepLogs(): HasMany
    {
        return $this->hasMany(AuditStepLog::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function markRunning(): void
    {
        $this->update(['status' => AuditStatus::Running, 'started_at' => now()]);
    }

    public function markCompleted(): void
    {
        $this->update(['status' => AuditStatus::Completed, 'completed_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status'        => AuditStatus::Failed,
            'error_message' => mb_substr($error, 0, 10000),
        ]);
    }

    public function advanceToStep(int $step, string $label): void
    {
        $this->update(['current_step' => $step, 'step_label' => $label]);
    }

    public function stepOutput(int $step): ?array
    {
        return $this->stepLogs()
            ->where('step', $step)
            ->where('status', 'completed')
            ->value('output');
    }
}
