<?php

namespace App\Enum;

enum AiMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
