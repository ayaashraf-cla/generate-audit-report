<?php

namespace Cla\GenerateAuditReport\Enums;

enum AuditStep: int
{
    case AnalyzeAnswers   = 1;
    case RetrieveEvidence = 2;
    case CompareEvidence  = 3;
    case GenerateFindings = 4;
    case GenerateReport   = 5;

    public function label(): string
    {
        return match($this) {
            self::AnalyzeAnswers   => 'Analyzing survey answers...',
            self::RetrieveEvidence => 'Searching policy documents...',
            self::CompareEvidence  => 'Comparing evidence against answers...',
            self::GenerateFindings => 'Generating audit findings...',
            self::GenerateReport   => 'Generating final audit report...',
        };
    }
}
