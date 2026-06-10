<?php

namespace Cla\GenerateAuditReport\Services\Audit;

use Cla\GenerateAuditReport\Contracts\SurveyDataProviderInterface;
use Cla\GenerateAuditReport\Data\SurveyContextData;

class NullSurveyDataProvider implements SurveyDataProviderInterface
{
    public function getSurveyContext(int $surveyId, ?int $responseId): SurveyContextData
    {
        throw new \LogicException(
            'No SurveyDataProviderInterface implementation is bound. ' .
            'Bind your app-specific SurveyDataProviderInterface implementation in your application service provider to use survey-driven audit sessions.'
        );
    }
}
