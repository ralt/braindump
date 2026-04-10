<?php

namespace App\Enum;

enum RecordingStatus: string
{
    case Pending = 'pending';
    case Transcribing = 'transcribing';
    case Completed = 'completed';
    case Failed = 'failed';
}
