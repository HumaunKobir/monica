<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $account = Account::factory()->create();
        $this->vault = Vault::factory()->create(['account_id' => $account->id]);

        // Create a contact for the user in this vault (required for user_vault table)
        $contact = Contact::factory()->create([
            'vault_id' => $this->vault->id,
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        // Attach user to vault with contact_id
        $this->vault->users()->attach($this->user, [
            'permission' => Vault::PERMISSION_EDIT,
            'contact_id' => $contact->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_it_can_create_a_tag()
    {
        $response = $this->postJson('/api/tags', [
            'name' => 'Family',
            'category' => 'Personal',
            'color' => '#FF5733',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Family')
            ->assertJsonPath('data.category', 'Personal')
            ->assertJsonPath('data.color', '#FF5733');

        $this->assertDatabaseHas('tags', [
            'name' => 'Family',
            'category' => 'Personal',
            'color' => '#FF5733',
            'vault_id' => $this->vault->id,
        ]);
    }

    public function test_it_lists_tags_with_usage_counts()
    {
        $tag1 = Tag::factory()->create(['vault_id' => $this->vault->id]);
        $tag2 = Tag::factory()->create(['vault_id' => $this->vault->id]);

        $contact = Contact::factory()->create(['vault_id' => $this->vault->id]);
        $contact->tags()->attach($tag1);

        $response = $this->getJson('/api/tags');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.usage_count', 1)
            ->assertJsonPath('data.1.usage_count', 0);
    }

    public function test_it_can_attach_tags_to_a_contact()
    {
        $contact = Contact::factory()->create(['vault_id' => $this->vault->id]);
        $tag1 = Tag::factory()->create(['vault_id' => $this->vault->id]);
        $tag2 = Tag::factory()->create(['vault_id' => $this->vault->id]);

        $response = $this->postJson("/api/contacts/{$contact->id}/tags", [
            'tag_ids' => [$tag1->id, $tag2->id],
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $contact->fresh()->tags);
    }

    public function test_it_filters_contacts_by_multiple_tags_using_and_logic()
    {
        $contact1 = Contact::factory()->create(['vault_id' => $this->vault->id]);
        $contact2 = Contact::factory()->create(['vault_id' => $this->vault->id]);
        $contact3 = Contact::factory()->create(['vault_id' => $this->vault->id]);

        $tagWork = Tag::factory()->create(['vault_id' => $this->vault->id, 'name' => 'Work']);
        $tagUrgent = Tag::factory()->create(['vault_id' => $this->vault->id, 'name' => 'Urgent']);
        $tagPersonal = Tag::factory()->create(['vault_id' => $this->vault->id, 'name' => 'Personal']);

        $contact1->tags()->attach([$tagWork->id, $tagUrgent->id]);

        $contact2->tags()->attach([$tagWork->id]);

        $contact3->tags()->attach([$tagUrgent->id]);

        $response = $this->getJson("/api/contacts?tags[]={$tagWork->id}&tags[]={$tagUrgent->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $contact1->id);
    }

    public function test_it_deletes_a_tag_and_optionally_reassigns_contacts()
    {
        $tag1 = Tag::factory()->create(['vault_id' => $this->vault->id]);
        $tag2 = Tag::factory()->create(['vault_id' => $this->vault->id]);

        $contact = Contact::factory()->create(['vault_id' => $this->vault->id]);
        $contact->tags()->attach([$tag1->id, $tag2->id]);

        $response = $this->deleteJson("/api/tags/{$tag1->id}", [
            'reassign_to' => $tag2->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('tags', ['id' => $tag1->id]);

        $this->assertEquals(1, $contact->fresh()->tags->count());
        $this->assertEquals($tag2->id, $contact->fresh()->tags->first()->id);
    }

    public function test_it_invalidates_cache_when_tag_is_created()
    {
        Cache::shouldReceive('forget')
            ->once()
            ->with(\Mockery::pattern('/vault_tags_.*_user_.*/'));

        $response = $this->postJson('/api/tags', [
            'name' => 'Test Tag',
        ]);

        $response->assertStatus(201);
    }

    public function test_it_respects_pagination_when_filtering_by_tags()
    {
        $tag = Tag::factory()->create(['vault_id' => $this->vault->id]);

        $contacts = Contact::factory()->count(15)->create(['vault_id' => $this->vault->id]);
        foreach ($contacts as $contact) {
            $contact->tags()->attach($tag);
        }

        $response = $this->getJson("/api/contacts?tags[]={$tag->id}&limit=5");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 15);
    }

    public function test_it_can_detach_a_tag_from_a_contact()
    {
        $contact = Contact::factory()->create(['vault_id' => $this->vault->id]);
        $tag = Tag::factory()->create(['vault_id' => $this->vault->id]);

        $contact->tags()->attach($tag);
        $this->assertCount(1, $contact->fresh()->tags);

        $response = $this->deleteJson("/api/contacts/{$contact->id}/tags/{$tag->id}");

        $response->assertStatus(200);
        $this->assertCount(0, $contact->fresh()->tags);
    }

    public function test_it_updates_a_tag()
    {
        $tag = Tag::factory()->create([
            'vault_id' => $this->vault->id,
            'name' => 'Old Name',
            'category' => 'Old Category',
        ]);

        $response = $this->putJson("/api/tags/{$tag->id}", [
            'name' => 'New Name',
            'category' => 'New Category',
            'color' => '#00FF00',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.category', 'New Category');

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'New Name',
            'category' => 'New Category',
            'color' => '#00FF00',
        ]);
    }
}
