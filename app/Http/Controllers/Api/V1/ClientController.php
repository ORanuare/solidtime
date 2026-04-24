<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\EntityStillInUseApiException;
use App\Http\Requests\V1\Client\ClientIndexRequest;
use App\Http\Requests\V1\Client\ClientStoreRequest;
use App\Http\Requests\V1\Client\ClientUpdateRequest;
use App\Http\Resources\V1\Client\ClientCollection;
use App\Http\Resources\V1\Client\ClientResource;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ClientController extends Controller
{
    protected function checkPermission(Organization $organization, string $permission, ?Client $client = null): void
    {
        parent::checkPermission($organization, $permission);
        if ($client !== null && $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Tag does not belong to organization');
        }
    }

    /**
     * Get clients
     *
     * @return ClientCollection<ClientResource>
     *
     * @throws AuthorizationException
     *
     * @operationId getClients
     */
    public function index(Organization $organization, ClientIndexRequest $request): ClientCollection
    {
        $this->checkPermission($organization, 'clients:view');
        $canViewAllClients = $this->hasPermission($organization, 'clients:view:all');
        $user = $this->user();

        $clientsQuery = Client::query()
            ->whereBelongsTo($organization, 'organization')
            ->orderBy('created_at', 'desc');

        if (! $canViewAllClients) {
            $clientsQuery->visibleByEmployee($user);
        }

        $filterArchived = $request->getFilterArchived();
        if ($filterArchived === 'true') {
            $clientsQuery->whereNotNull('archived_at');
        } elseif ($filterArchived === 'false') {
            $clientsQuery->whereNull('archived_at');
        }

        $clients = $clientsQuery->paginate(config('app.pagination_per_page_default'));

        return new ClientCollection($clients);
    }

    /**
     * Create client
     *
     * @throws AuthorizationException
     *
     * @operationId createClient
     */
    public function store(Organization $organization, ClientStoreRequest $request): ClientResource
    {
        $this->checkPermission($organization, 'clients:create');

        $client = new Client;
        $client->name = $request->input('name');
        $client->description = $request->input('description');
        $client->contacts = $this->normalizeContacts($request->input('contacts'));
        $client->organization()->associate($organization);
        $client->save();

        return new ClientResource($client);
    }

    /**
     * Update client
     *
     * @throws AuthorizationException
     *
     * @operationId updateClient
     */
    public function update(Organization $organization, Client $client, ClientUpdateRequest $request): ClientResource
    {
        $this->checkPermission($organization, 'clients:update', $client);

        $client->name = $request->input('name');
        if ($request->has('description')) {
            $client->description = $request->input('description');
        }
        if ($request->has('contacts')) {
            $client->contacts = $this->normalizeContacts($request->input('contacts'));
        }
        if ($request->has('is_archived')) {
            $client->archived_at = $request->getIsArchived() ? Carbon::now() : null;
        }
        $client->save();

        return new ClientResource($client);
    }

    /**
     * Delete client
     *
     * @throws AuthorizationException|EntityStillInUseApiException
     *
     * @operationId deleteClient
     */
    public function destroy(Organization $organization, Client $client): JsonResponse
    {
        $this->checkPermission($organization, 'clients:delete', $client);

        if ($client->projects()->exists()) {
            throw new EntityStillInUseApiException('client', 'project');
        }

        $client->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  list<array<string, mixed>>|null  $contacts
     * @return list<array{label: string, value: string}>|null
     */
    private function normalizeContacts(?array $contacts): ?array
    {
        if ($contacts === null || $contacts === []) {
            return null;
        }
        $out = [];
        foreach ($contacts as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! array_key_exists('label', $row) || ! array_key_exists('value', $row)) {
                continue;
            }
            $out[] = [
                'label' => (string) $row['label'],
                'value' => (string) $row['value'],
            ];
        }

        return $out === [] ? null : $out;
    }
}
