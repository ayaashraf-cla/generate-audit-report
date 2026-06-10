<?php

namespace Cla\GenerateAuditReport\Facades;

use Cla\GenerateAuditReport\AuditReportBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static AuditReportBuilder for(\Illuminate\Database\Eloquent\Model $model)
 *
 * @see \Cla\GenerateAuditReport\AuditReportBuilder
 */
class GenerateAuditReport extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditReportBuilder::class;
    }
}
