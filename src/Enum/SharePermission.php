<?php

namespace App\Enum;

enum SharePermission: string
{
    case View = 'view';
    case Edit = 'edit';
}
