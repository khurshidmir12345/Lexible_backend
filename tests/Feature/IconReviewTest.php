<?php

namespace Tests\Feature;

use App\Filament\Pages\IconReview;
use App\Models\Admin;
use App\Models\Icon;
use App\Models\IconCandidate;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IconReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected Word $hello;

    protected Word $apple;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Test Admin', 'email' => 'admin@lexible.test', 'password' => 'secret123',
            'role' => 'admin', 'is_active' => true,
        ]);

        foreach (['handshake', 'hand-wave', 'apple', 'mill'] as $slug) {
            Icon::create(['slug' => $slug, 'title' => ucfirst($slug), 'category' => 'Test', 'tags' => [], 'path' => Icon::pathFor($slug)]);
        }

        $mill = Icon::where('slug', 'mill')->first();

        $this->hello = Word::create([
            'word' => 'hello', 'part_of_speech' => 'interjection', 'frequency_rank' => 203,
            'translations' => ['uz' => ['salom']],
            'icon_id' => $mill->id, 'icon_path' => Icon::pathFor('mill'), 'icon_source' => 'llm', 'icon_confidence' => 95,
        ]);
        $this->apple = Word::create([
            'word' => 'apple', 'part_of_speech' => 'noun', 'frequency_rank' => 900,
            'translations' => ['uz' => ['olma']],
        ]);

        IconCandidate::create(['normalized' => 'hello', 'slugs' => ['hand-wave', 'handshake']]);
    }

    public function test_page_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin, 'admin')->get('/admin/icons')->assertSuccessful()->assertSee('hello');
    }

    public function test_picking_a_suggestion_stores_a_manual_icon_and_moves_on(): void
    {
        $this->actingAs($this->admin, 'admin');

        Livewire::test(IconReview::class)
            ->call('select', $this->hello->id)
            ->assertSee('Hand-wave')
            ->call('pick', 'hand-wave')
            ->assertSet('selectedId', $this->apple->id)   // the next word is up
            ->assertSee('hello')                           // …but the decided one stays in its row
            ->assertSee('✓ admin');

        $this->hello->refresh();
        $this->assertSame('hand-wave', $this->hello->icon->slug);
        $this->assertSame('manual', $this->hello->icon_source);
        $this->assertSame('icons/256/hand-wave.webp', $this->hello->icon_path);
    }

    public function test_rejecting_clears_the_icon_but_keeps_the_verdict(): void
    {
        $this->actingAs($this->admin, 'admin');

        Livewire::test(IconReview::class)
            ->call('select', $this->hello->id)
            ->call('reject');

        $this->hello->refresh();
        $this->assertNull($this->hello->icon_id);
        $this->assertSame('manual', $this->hello->icon_source);
    }

    public function test_approve_freezes_the_current_icon_and_assign_respects_it(): void
    {
        $this->actingAs($this->admin, 'admin');

        Livewire::test(IconReview::class)
            ->call('select', $this->hello->id)
            ->call('approve');

        $this->assertSame('manual', $this->hello->fresh()->icon_source);

        $file = storage_path('app/private/icons/test-mapping.json');
        file_put_contents($file, json_encode([['word' => 'hello', 'slug' => 'handshake', 'confidence' => 90]]));
        $this->artisan('icons:assign', ['file' => $file, '--reset' => true])->assertSuccessful();
        unlink($file);

        $this->assertSame('mill', $this->hello->fresh()->icon->slug);
    }

    public function test_library_search_replaces_the_suggestions(): void
    {
        $this->actingAs($this->admin, 'admin');

        Livewire::test(IconReview::class)
            ->call('select', $this->apple->id)
            ->set('iconQuery', 'app')
            ->assertSee('Apple')
            ->assertDontSee('Handshake');
    }

    public function test_keyboard_index_picks_the_nth_suggestion(): void
    {
        $this->actingAs($this->admin, 'admin');

        Livewire::test(IconReview::class)
            ->call('select', $this->hello->id)
            ->call('pickIndex', 1);   // 0 = current (mill) pinned first, 1 = hand-wave

        $this->assertSame('hand-wave', $this->hello->fresh()->icon->slug);
    }
}
