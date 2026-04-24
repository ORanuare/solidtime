<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

enum ProjectBillingType: string
{
    use LaravelEnumHelper;

    case Hourly = 'hourly';
    case Fixed = 'fixed';
}
