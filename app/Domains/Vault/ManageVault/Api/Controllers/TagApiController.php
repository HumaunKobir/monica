<?php

namespace App\Domains\Vault\ManageVault\Api\Controllers;

use App\Http\Controllers\ApiController;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Vault;
use App\Traits\JsonRespondController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TagApiController extends ApiController
{
    use JsonRespondController;

    protected function getTagsCacheKey(Vault $vault): string
    {
        return "vault_tags_{$vault->id}_user_".auth()->id();
    }

    public function index(Request $request)
    {
        $vault = $request->user()->vaults()->first();

        if (! $vault) {
            return response()->json(['message' => 'Vault not found'], 404);
        }

        $cacheKey = $this->getTagsCacheKey($vault);

        $tags = Cache::remember($cacheKey, 600, function () use ($vault) {
            $tags = Tag::where('vault_id', $vault->id)
                ->withCount('contacts')
                ->orderBy('name')
                ->get();

            return $tags->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'category' => $tag->category,
                    'color' => $tag->color,
                    'usage_count' => $tag->contacts_count,
                    'created_at' => $tag->created_at,
                    'updated_at' => $tag->updated_at,
                ];
            });
        });

        return response()->json([
            'data' => $tags,
            'meta' => ['total' => $tags->count()],
        ], 200);
    }

    public function store(Request $request)
    {
        $vault = $request->user()->vaults()->first();

        if (! $vault) {
            return $this->respondNotFound();
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
        ]);

        $tag = Tag::create([
            'vault_id' => $vault->id,
            'name' => $validated['name'],
            'category' => $validated['category'] ?? null,
            'color' => $validated['color'] ?? null,
        ]);

        $this->invalidateTagsCache($vault);

        return response()->json([
            'data' => $tag,
            'message' => 'Tag created successfully',
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $vault = $request->user()->vaults()->first();

        if (! $vault) {
            return $this->respondNotFound();
        }

        $tag = Tag::where('vault_id', $vault->id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'category' => 'nullable|string|max:100',
            'color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
        ]);

        $tag->update($validated);

        $this->invalidateTagsCache($vault);

        return response()->json([
            'data' => $tag,
            'message' => 'Tag updated successfully',
        ], 200);
    }

    public function destroy(Request $request, string $id)
    {
        try {
            $vault = $request->user()->vaults()->first();

            if (! $vault) {
                return response()->json(['message' => 'Vault not found'], 404);
            }

            $tag = Tag::where('vault_id', $vault->id)->findOrFail($id);

            $reassignToId = $request->input('reassign_to');

            DB::transaction(function () use ($tag, $reassignToId, $vault) {
                if ($reassignToId) {
                    $targetTag = Tag::where('vault_id', $vault->id)->findOrFail($reassignToId);

                    $contacts = $tag->contacts;

                    foreach ($contacts as $contact) {
                        if (! $contact->tags->contains($targetTag->id)) {
                            $contact->tags()->attach($targetTag->id);
                        }
                        $contact->tags()->detach($tag->id);
                    }
                }

                $tag->delete();
            });

            $this->invalidateTagsCache($vault);

            return response()->json([
                'message' => 'Tag deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Tag deletion error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function attachTags(Request $request, string $contactId)
    {
        $vault = $request->user()->vaults()->first();

        if (! $vault) {
            return $this->respondNotFound();
        }

        $contact = Contact::where('vault_id', $vault->id)->findOrFail($contactId);

        $validated = $request->validate([
            'tag_ids' => 'required|array|min:1',
            'tag_ids.*' => 'exists:tags,id',
        ]);

        $tags = Tag::whereIn('id', $validated['tag_ids'])
            ->where('vault_id', $vault->id)
            ->get();

        $contact->tags()->syncWithoutDetaching($tags->pluck('id'));

        $this->invalidateTagsCache($vault);

        return $this->respond([
            'data' => $contact->tags()->get(),
            'message' => 'Tags attached successfully',
        ]);
    }

    public function detachTag(Request $request, string $contactId, string $tagId)
    {
        $vault = $request->user()->vaults()->first();

        if (! $vault) {
            return $this->respondNotFound();
        }

        $contact = Contact::where('vault_id', $vault->id)->findOrFail($contactId);
        $tag = Tag::where('vault_id', $vault->id)->findOrFail($tagId);

        $contact->tags()->detach($tag->id);

        $this->invalidateTagsCache($vault);

        return $this->respond([
            'message' => 'Tag detached successfully',
        ]);
    }

    public function getContactsWithTags(Request $request)
    {
        $vault = $request->user()->vaults()->first();

        if (! $vault) {
            return $this->respondNotFound();
        }

        $validated = $request->validate([
            'tags' => 'nullable|array',
            'tags.*' => 'integer|exists:tags,id',
            'sort' => 'nullable|string|in:name,created_at,updated_at',
            'direction' => 'nullable|string|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:'.config('api.max_limit_per_page', 100),
        ]);

        $query = Contact::where('vault_id', $vault->id);

        if (! empty($validated['tags'])) {
            $tagIds = $validated['tags'];

            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            }, '=', count($tagIds));
        }

        $sortField = $validated['sort'] ?? 'created_at';
        $sortDirection = $validated['direction'] ?? 'desc';

        if ($sortField === 'name') {
            $query->orderByRaw('CONCAT(first_name, " ", last_name) '.$sortDirection);
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        $limit = $validated['limit'] ?? $this->getLimitPerPage();
        $contacts = $query->paginate($limit);

        return $this->respond([
            'data' => $contacts->items(),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
            ],
            'filters' => [
                'tags' => $validated['tags'] ?? [],
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    protected function invalidateTagsCache(Vault $vault): void
    {
        Cache::forget($this->getTagsCacheKey($vault));
    }
}
