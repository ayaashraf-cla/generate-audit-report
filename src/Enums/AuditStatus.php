<?php

namespace Cla\GenerateAuditReport\Enums;

enum AuditStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
}
