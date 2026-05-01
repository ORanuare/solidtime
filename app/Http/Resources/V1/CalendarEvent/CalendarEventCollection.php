<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\CalendarEvent;

use App\Http\Resources\PaginatedResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CalendarEventCollection extends ResourceCollection implements PaginatedResourceCollection
{
    /**
     * @var string
     */
    public $collects = CalendarEventResource::class;
}
