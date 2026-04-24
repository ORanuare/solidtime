<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Note;

use App\Http\Resources\PaginatedResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;

class NoteCollection extends ResourceCollection implements PaginatedResourceCollection
{
    /**
     * @var string
     */
    public $collects = NoteResource::class;
}
