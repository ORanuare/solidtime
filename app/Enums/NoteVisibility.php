<?php

declare(strict_types=1);

namespace App\Enums;

enum NoteVisibility: string
{
    case Private = 'private';
    case Shared = 'shared';
}
