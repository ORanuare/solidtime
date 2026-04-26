<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\TimeEntry\TimeEntryResource;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class UserTimeEntryController extends Controller
{
    /**
     * Get the active time entry of the current user
     *
     * This endpoint is independent of organization.
     *
     * @operationId getMyActiveTimeEntry
     */
    public function myActive(): JsonResource|JsonResponse
    {
        $user = $this->user();

        $activeTimeEntriesOfUser = TimeEntry::query()
            ->whereBelongsTo($user, 'user')
            ->whereNull('end')
            ->orderBy('start', 'desc')
            ->get();

        if ($activeTimeEntriesOfUser->count() > 1) {
            Log::warning('User has more than one active time entry.', [
                'user' => $user->getKey(),
            ]);
        }

        $activeTimeEntry = $activeTimeEntriesOfUser->first();

        if ($activeTimeEntry !== null) {
            return new TimeEntryResource($activeTimeEntry);
        }

        return response()->json(['data' => null]);
    }
}
