<?php

namespace Cla\GenerateAuditReport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditReport extends Model
{
    protected $fillable = [
        'audit_session_id',
        'risk_level',
        'compliance_score',
        'document_stats',
        'document_summary',
        'executive_summary',
        'findings',
        'recommendations',
        'evidence_references',
        'raw_report',
        'generated_at',
    ];

    protected $casts = [
        'document_stats'      => 'array',
        'findings'            => 'array',
        'recommendations'     => 'array',
        'evidence_references' => 'array',
        'generated_at'        => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AuditSession::class, 'audit_session_id');
    }
}
