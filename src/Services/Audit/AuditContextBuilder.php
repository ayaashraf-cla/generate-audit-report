<?php

namespace Cla\GenerateAuditReport\Services\Audit;

use Cla\GenerateAuditReport\Data\SurveyContextData;

class AuditContextBuilder
{
    /**
     * Convert a SurveyContextData DTO into the context array consumed by the workflow steps.
     */
    public function build(SurveyContextData $data): array
    {
        return [
            'survey_title'       => $data->surveyTitle,
            'survey_description' => $data->surveyDescription,
            'survey_language'    => $data->surveyLanguage,
            'total_questions'    => $data->totalQuestions,
            'answered_count'     => $data->answeredCount,
            'respondent'         => $data->respondent,
            'submitted_at'       => $data->submittedAt,
            'qa_pairs'           => $data->qaPairs,
        ];
    }

    /**
     * Format the context array into a compact prompt string, capped at maxChars.
     */
    public function formatForPrompt(array $context, int $maxChars = 8000, array $fileNames = []): string
    {
        $lines = [
            "Survey: {$context['survey_title']}",
            "Respondent: {$context['respondent']}",
            "Language: {$context['survey_language']}",
            "Questions answered: {$context['answered_count']} of {$context['total_questions']}",
        ];

        if (! empty($fileNames)) {
            $lines[] = 'Uploaded files: ' . implode(', ', $fileNames);
        }

        $lines[] = '';
        $lines[] = '--- SURVEY RESPONSES ---';
        $lines[] = '';

        foreach ($context['qa_pairs'] as $i => $qa) {
            $num    = $i + 1;
            $rawAnswer = $qa['answer_text']
                ?? ($qa['answer_data'] ? json_encode($qa['answer_data'], JSON_UNESCAPED_UNICODE) : null);

            if ($rawAnswer !== null) {
                $sanitized = str_replace(['[ANSWER_START]', '[ANSWER_END]'], '', $rawAnswer);
                $answer    = "[ANSWER_START] {$sanitized} [ANSWER_END]";
            } else {
                $answer = '[No answer provided]';
            }

            $lines[] = "Q{$num}: {$qa['question_text']}";
            $lines[] = "A{$num}: {$answer}";
            $lines[] = '';
        }

        $text = implode("\n", $lines);

        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars) . "\n[...truncated for token budget...]";
        }

        return $text;
    }

    /**
     * Extract representative search queries from answers for semantic retrieval.
     */
    public function extractSearchQueries(array $context): array
    {
        $queries = [];

        foreach ($context['qa_pairs'] as $qa) {
            $answerText = trim((string) ($qa['answer_text'] ?? ''));
            if (mb_strlen($answerText) > 10) {
                $queries[] = $qa['question_text'] . ' ' . $answerText;
            }
        }

        return array_slice($queries, 0, 5);
    }
}
