<?php

namespace Cla\GenerateAuditReport\Data;

/**
 * Immutable DTO that carries all survey/response data the audit workflow needs.
 * Decouples the workflow engine from any particular Eloquent model structure.
 *
 * @property-read array{
 *   question_id: int,
 *   question_text: string,
 *   question_type: string,
 *   is_required: bool,
 *   answer_text: string|null,
 *   answer_data: mixed,
 *   score: mixed
 * }[] $qaPairs
 */
readonly class SurveyContextData
{
    /**
     * @param  array<int, array{
     *     question_id: int,
     *     question_text: string,
     *     question_type: string,
     *     is_required: bool,
     *     answer_text: string|null,
     *     answer_data: mixed,
     *     score: mixed
     * }> $qaPairs
     */
    public function __construct(
        public string  $surveyTitle,
        public string  $surveyDescription,
        public string  $surveyLanguage,
        public int     $totalQuestions,
        public int     $answeredCount,
        public string  $respondent,
        public ?string $submittedAt,
        public array   $qaPairs,
    ) {}
}
