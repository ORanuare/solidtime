<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationProfilePhotoController extends Controller
{
    public function destroy(Request $request, Organization $team): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('update', $team);

        $team->deleteProfilePhoto();

        return back(303);
    }
}
