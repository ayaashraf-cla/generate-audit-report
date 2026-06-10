<?php

namespace Cla\GenerateAuditReport\Contracts;

use Cla\GenerateAuditReport\Data\SurveyContextData;

interface SurveyDataProviderInterface
{
    /**
     * Load and return structured survey/response data for the audit workflow.
     * The implementing class is responsible for fetching questions, answers,
     * translations, and respondent metadata from the application's data source.
     */
    public function getSurveyContext(int $surveyId, ?int $responseId): SurveyContextData;
}
