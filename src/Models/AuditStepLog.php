<?php

namespace Cla\GenerateAuditReport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditStepLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'audit_session_id',
        'step',
        'step_name',
        'prompt',
        'status',
        'duration_ms',
        'output',
        'token_usage',
        'messages_snapshot',
        'chunks_retrieved',
        'error',
        'created_at',
    ];

    protected $casts = [
        'output'            => 'array',
        'token_usage'       => 'array',
        'messages_snapshot' => 'array',
        'created_at'        => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AuditSession::class, 'audit_session_id');
    }
}
