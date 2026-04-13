<?php

namespace App\Enum;

enum AiSessionStatus: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Closed = 'closed';
}
