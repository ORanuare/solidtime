<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Client;

use App\Http\Resources\V1\BaseResource;
use App\Models\Client;
use Illuminate\Http\Request;

/**
 * @property Client $resource
 */
class ClientResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, string|bool|int|null|array<int, array{label: string, value: string}>>
     */
    public function toArray(Request $request): array
    {
        $contacts = $this->resource->contacts ?? [];
        $normalized = [];
        foreach ($contacts as $row) {
            if (! is_array($row) || ! isset($row['label'], $row['value'])) {
                continue;
            }
            $normalized[] = [
                'label' => (string) $row['label'],
                'value' => (string) $row['value'],
            ];
        }

        return [
            /** @var string $id ID */
            'id' => $this->resource->id,
            /** @var string $name Name */
            'name' => $this->resource->name,
            /** @var string|null $description Optional notes */
            'description' => $this->resource->description,
            /** @var array<int, array{label: string, value: string}> Contact label/value pairs */
            'contacts' => $normalized,
            /** @var bool $is_archived Whether the client is archived */
            'is_archived' => $this->resource->is_archived,
            /** @var string $created_at When the tag was created */
            'created_at' => $this->formatDateTime($this->resource->created_at),
            /** @var string $updated_at When the tag was last updated */
            'updated_at' => $this->formatDateTime($this->resource->updated_at),
        ];
    }
}
