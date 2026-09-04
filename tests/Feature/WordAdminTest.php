<?php

namespace Tests\Feature;

use App\Filament\Resources\Words\Pages\CreateWord;
use App\Filament\Resources\Words\Pages\EditWord;
use App\Filament\Resources\Words\Pages\ListWords;
use App\Models\Admin;
use App\Models\Icon;
use App\Models\Word;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WordAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Admin::create([
            'name' => 'Test Admin', 'email' => 'admin@lexible.test', 'password' => 'secret123',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        foreach (['apple', 'hand-wave', 'walking'] as $slug) {
            Icon::create(['slug' => $slug, 'title' => ucwords(str_replace('-', ' ', $slug)), 'category' => 'Test', 'tags' => ['t'], 'path' => Icon::pathFor($slug)]);
        }
    }

    protected function word(string $word, int $rank, array $extra = []): Word
    {
        return Word::create(['word' => $word, 'frequency_rank' => $rank, 'translations' => ['uz' => ['x']]] + $extra);
    }

    public function test_search_puts_the_exact_word_first_and_matches_by_prefix(): void
    {
        $gone = $this->word('gone', 50);
        $goal = $this->word('goal', 60);
        $go = $this->word('go', 70);
        $ago = $this->word('ago', 40);

        Livewire::test(ListWords::class, ['activeTab' => 'all'])
            ->searchTable('go')
            ->assertCanSeeTableRecords([$go, $gone, $goal], inOrder: true)
            ->assertCanNotSeeTableRecords([$ago]);
    }

    public function test_search_also_finds_a_word_by_its_translation(): void
    {
        $hello = Word::create(['word' => 'hello', 'frequency_rank' => 10, 'translations' => ['uz' => ['salom']]]);
        $this->word('help', 20);

        Livewire::test(ListWords::class, ['activeTab' => 'all'])
            ->searchTable('salom')
            ->assertCanSeeTableRecords([$hello])
            ->assertCountTableRecords(1);
    }

    public function test_editing_picks_an_icon_and_writes_translations(): void
    {
        $hello = $this->word('hello', 10);
        $wave = Icon::where('slug', 'hand-wave')->first();

        Livewire::test(EditWord::class, ['record' => $hello->id])
            ->fillForm(['icon_id' => $wave->id, 'translations' => ['uz' => ['salom', 'assalomu alaykum'], 'ru' => ['привет']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $hello->refresh();
        $this->assertSame($wave->id, $hello->icon_id);
        $this->assertSame('icons/256/hand-wave.webp', $hello->icon_path);
        $this->assertSame('manual', $hello->icon_source);
        $this->assertSame(['salom', 'assalomu alaykum'], $hello->acceptedAnswers('uz'));
        $this->assertSame('привет', $hello->translation('ru'));
    }

    public function test_removing_the_icon_is_a_manual_decision(): void
    {
        $apple = Icon::where('slug', 'apple')->first();
        $word = $this->word('apple', 5, ['icon_id' => $apple->id, 'icon_path' => Icon::pathFor('apple'), 'icon_source' => 'llm', 'icon_confidence' => 90]);

        Livewire::test(EditWord::class, ['record' => $word->id])
            ->fillForm(['icon_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $word->refresh();
        $this->assertNull($word->icon_id);
        $this->assertNull($word->icon_path);
        $this->assertSame('manual', $word->icon_source);
    }

    public function test_saving_without_touching_the_icon_keeps_the_automatic_pick(): void
    {
        $apple = Icon::where('slug', 'apple')->first();
        $word = $this->word('apple', 5, ['icon_id' => $apple->id, 'icon_path' => Icon::pathFor('apple'), 'icon_source' => 'llm', 'icon_confidence' => 90]);

        Livewire::test(EditWord::class, ['record' => $word->id])
            ->fillForm(['transcription' => '/ˈæpl/'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('llm', $word->fresh()->icon_source);
    }

    public function test_creating_a_word_with_icon_and_translation(): void
    {
        $walk = Icon::where('slug', 'walking')->first();

        Livewire::test(CreateWord::class)
            ->fillForm([
                'word' => 'Walk',
                'part_of_speech' => 'verb',
                'translations' => ['uz' => ['yurmoq']],
                'icon_id' => $walk->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $word = Word::where('normalized', 'walk')->first();
        $this->assertNotNull($word);
        $this->assertSame('manual', $word->icon_source);
        $this->assertSame('icons/256/walking.webp', $word->icon_path);
        $this->assertSame('done', $word->translation_status);
        $this->assertSame('yurmoq', $word->translation('uz'));
    }

    public function test_a_word_can_be_deleted_from_its_edit_page(): void
    {
        $word = $this->word('junk', 999);

        Livewire::test(EditWord::class, ['record' => $word->id])
            ->callAction(DeleteAction::class);

        $this->assertDatabaseMissing('words', ['id' => $word->id]);
    }

    public function test_list_renders_icons_and_the_edit_page_renders_the_picker(): void
    {
        $apple = Icon::where('slug', 'apple')->first();
        $word = $this->word('apple', 5, ['icon_id' => $apple->id, 'icon_path' => Icon::pathFor('apple'), 'icon_source' => 'llm', 'icon_confidence' => 90]);

        $this->get('/admin/words')->assertSuccessful()->assertSee('icons/256/apple.webp');
        $this->get("/admin/words/{$word->id}/edit")->assertSuccessful()->assertSee('Takliflar')->assertSee('icons/512/apple.webp');
    }
}
