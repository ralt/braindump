<?php

namespace App\Enum;

enum ClaudeSessionStatus: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Closed = 'closed';
}
