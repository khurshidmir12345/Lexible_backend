<?php

namespace Tests\Feature;

use App\Console\Commands\ImportDictionary;
use App\Models\Word;
use App\Services\Dictionary\Contracts\Translator;
use App\Services\Dictionary\Providers\GeminiTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The three steps that turn a Wiktionary dump into a dictionary the app can
 * teach from: distil, import, translate.
 */
class DictionaryPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/dictionary');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** One line of the real dump shape. */
    protected function entry(array $overrides = []): array
    {
        return array_merge([
            'word' => 'home',
            'pos' => 'noun',
            'lang_code' => 'en',
            'senses' => [['glosses' => ['A dwelling.'], 'examples' => [
                ['text' => 'He went home for the holidays.', 'type' => 'example'],
            ]]],
            'sounds' => [['ipa' => '/həʊm/']],
        ], $overrides);
    }

    protected function distil(array $entries): array
    {
        $source = $this->dir.'/dump.jsonl';
        $out = $this->dir.'/words.jsonl';

        File::put($source, collect($entries)->map(fn ($e) => json_encode($e))->implode("\n")."\n");

        $this->artisan("dictionary:distill {$source} --out={$out}")->assertSuccessful();

        return collect(file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->map(fn ($line) => json_decode($line, true))
            ->keyBy('w')
            ->all();
    }

    // ------------------------------------------------------------------ distil

    public function test_it_keeps_the_fields_a_flashcard_needs(): void
    {
        $words = $this->distil([$this->entry()]);

        $this->assertSame('home', $words['home']['w']);
        $this->assertSame('noun', $words['home']['p']);
        $this->assertSame('/həʊm/', $words['home']['ipa']);
        $this->assertSame('A dwelling.', $words['home']['g']);
        $this->assertSame('He went home for the holidays.', $words['home']['ex']);
    }

    public function test_the_dominant_reading_wins_not_the_first_one(): void
    {
        // "free" is a noun only in football, and an adjective everywhere else.
        $words = $this->distil([
            $this->entry([
                'word' => 'free', 'pos' => 'noun',
                'senses' => [['glosses' => ['A free transfer.']]],
            ]),
            $this->entry([
                'word' => 'free', 'pos' => 'adj',
                'senses' => [
                    ['glosses' => ['Unconstrained.']],
                    ['glosses' => ['Obtainable without payment.']],
                    ['glosses' => ['Unobstructed.']],
                ],
            ]),
        ]);

        $this->assertSame('adjective', $words['free']['p']);
        $this->assertSame('Unconstrained.', $words['free']['g']);
        $this->assertEqualsCanonicalizing(['noun', 'adjective'], $words['free']['pos']);
    }

    public function test_a_rare_etymology_cannot_supply_the_definition(): void
    {
        // Wiktionary splits by etymology: the animal "cat" has many senses but
        // they are all topic templates, and the Unix "cat" has one real one.
        $words = $this->distil([
            $this->entry([
                'word' => 'cat', 'pos' => 'noun',
                'senses' => array_fill(0, 12, ['glosses' => ['Terms relating to animals.']]),
            ]),
            $this->entry([
                'word' => 'cat', 'pos' => 'noun',
                'senses' => [['glosses' => ['A program in Unix that reads files.']]],
            ]),
        ]);

        $this->assertSame('noun', $words['cat']['p']);
        $this->assertArrayNotHasKey('g', $words['cat'], 'the Unix sense must not gloss the animal');
    }

    public function test_redirect_senses_are_not_definitions(): void
    {
        $words = $this->distil([
            $this->entry([
                'word' => 'encyclopaedia',
                'senses' => [['glosses' => ['Commonwealth standard spelling of encyclopedia.']]],
            ]),
        ]);

        $this->assertArrayNotHasKey('g', $words['encyclopaedia'] ?? ['g' => null]);
    }

    public function test_a_usage_example_beats_a_book_quotation(): void
    {
        $words = $this->distil([
            $this->entry([
                'word' => 'word',
                'senses' => [[
                    'glosses' => ['A unit of language.'],
                    'examples' => [
                        ['text' => 'Then all was ſilent ſave the voice of the prieſt.', 'type' => 'quotation'],
                        ['text' => 'Tell me in your own words.', 'type' => 'example'],
                    ],
                ]],
            ]),
        ]);

        $this->assertSame('Tell me in your own words.', $words['word']['ex']);
    }

    public function test_it_drops_what_a_learner_would_never_meet(): void
    {
        $words = $this->distil([
            $this->entry(['word' => 'London', 'pos' => 'name']),
            $this->entry(['word' => 'ice cream']),
            $this->entry(['word' => 'chien', 'lang_code' => 'fr']),
        ]);

        $this->assertSame([], array_keys($words), 'proper nouns, phrases and other languages');
    }

    public function test_an_archaic_word_keeps_its_place_but_loses_its_meaning(): void
    {
        // The distiller cannot know whether "thou" is worth keeping — that
        // depends on the frequency list, which only the importer has. So the
        // archaic sense is stripped and the decision is deferred.
        $words = $this->distil([
            $this->entry([
                'word' => 'thou',
                'senses' => [['glosses' => ['You.'], 'tags' => ['archaic']]],
            ]),
        ]);

        $this->assertArrayHasKey('thou', $words);
        $this->assertArrayNotHasKey('g', $words['thou']);
    }

    public function test_the_importer_is_where_an_archaic_word_is_dropped(): void
    {
        $this->import([
            $this->entry([
                'word' => 'thou',
                'senses' => [['glosses' => ['You.'], 'tags' => ['archaic']]],
            ]),
            $this->entry(),
        ], ['home']);

        // No usable definition and not common enough to be looked up.
        $this->assertNull(Word::where('normalized', 'thou')->first());
        $this->assertNotNull(Word::where('normalized', 'home')->first());
    }

    public function test_wiktionarys_own_uzbek_is_taken_and_cyrillic_is_not(): void
    {
        $words = $this->distil([
            $this->entry([
                'word' => 'dictionary',
                'translations' => [
                    ['code' => 'uz', 'word' => 'lugʻat'],
                    ['code' => 'uz', 'word' => 'луғат', 'tags' => ['Cyrillic']],
                    ['code' => 'ru', 'word' => 'словарь'],
                ],
            ]),
        ]);

        $this->assertSame(['lugʻat'], $words['dictionary']['uz']);
    }

    // ------------------------------------------------------------------ import

    protected function import(array $entries, array $frequency): void
    {
        $this->distil($entries);

        File::put($this->dir.'/freq.txt', implode("\n", $frequency));

        $this->artisan('dictionary:import', [
            '--file' => $this->dir.'/words.jsonl',
            '--frequency' => $this->dir.'/freq.txt',
        ])->assertSuccessful();
    }

    public function test_it_ranks_and_levels_words_from_the_frequency_list(): void
    {
        $this->import(
            [$this->entry(), $this->entry(['word' => 'aardvark'])],
            ['home', 'aardvark'],
        );

        $home = Word::where('normalized', 'home')->first();

        $this->assertSame(1, $home->frequency_rank);
        $this->assertSame('A1', $home->cefr_level);
        $this->assertSame('/həʊm/', $home->transcription);
        $this->assertSame('A dwelling.', $home->definition['en']);
        $this->assertTrue($home->is_teachable);
        $this->assertSame('pending', $home->translation_status);
    }

    public function test_grammar_words_stay_searchable_but_are_never_dealt_out(): void
    {
        $this->import([
            $this->entry(['word' => 'the', 'pos' => 'adv',
                'senses' => [['glosses' => ['With a comparative.']]]]),
            $this->entry(),
        ], ['the', 'home']);

        $the = Word::where('normalized', 'the')->first();

        $this->assertNotNull($the, 'a teacher typing "the" must still find it');
        $this->assertTrue($the->is_active);
        $this->assertFalse($the->is_teachable);

        // A stage is filled from the teachable set, so it never sees it.
        $this->assertSame(0, Word::teachable()->where('normalized', 'the')->count());
    }

    public function test_the_long_tail_is_left_out_entirely(): void
    {
        // No definition and nobody uses it — nothing to teach, nothing to find.
        $this->import([
            $this->entry(['word' => 'abaciscus', 'senses' => [
                ['glosses' => ['Terms relating to architecture.']],
            ]]),
            $this->entry(),
        ], ['home']);

        $this->assertNull(Word::where('normalized', 'abaciscus')->first());
        $this->assertNotNull(Word::where('normalized', 'home')->first());
    }

    public function test_wiktionary_uzbek_arrives_already_translated(): void
    {
        $this->import([
            $this->entry([
                'word' => 'dictionary',
                'translations' => [['code' => 'uz', 'word' => 'lugʻat']],
            ]),
        ], ['dictionary']);

        $word = Word::where('normalized', 'dictionary')->first();

        $this->assertSame(['lugʻat'], $word->translations['uz']);
        $this->assertSame('done', $word->translation_status);
        $this->assertSame('wiktionary', $word->translation_source);
    }

    public function test_re_running_the_import_does_not_duplicate_or_clobber(): void
    {
        $this->import([$this->entry()], ['home']);

        Word::where('normalized', 'home')->update([
            'translations' => json_encode(['uz' => ['uy']]),
            'translation_status' => 'done',
        ]);

        $this->import([$this->entry()], ['home']);

        $this->assertSame(1, Word::where('normalized', 'home')->count());
        // A translation already in place survives a re-import.
        $this->assertSame(['uy'], Word::where('normalized', 'home')->first()->translations['uz']);
    }

    // --------------------------------------------------------------- translate

    public function test_the_prompt_carries_the_sense_not_just_the_word(): void
    {
        $prompt = app(GeminiTranslator::class)->payload([
            ['word' => 'home', 'pos' => 'noun', 'gloss' => 'A dwelling.', 'example' => 'He went home.'],
        ]);

        // Without these three, "home" comes back as a navigation label.
        $this->assertStringContainsString('home (noun)', $prompt);
        $this->assertStringContainsString('A dwelling.', $prompt);
        $this->assertStringContainsString('He went home.', $prompt);
    }

    public function test_it_only_accepts_answers_for_words_it_asked_about(): void
    {
        $asked = [['word' => 'home', 'pos' => 'noun', 'gloss' => null, 'example' => null]];

        $result = app(GeminiTranslator::class)->parse(
            '{"home":{"uz":["uy","turar joy"]},"invented":{"uz":["yoʻq"]}}',
            $asked,
        );

        $this->assertSame(['home' => ['uz' => ['uy', 'turar joy']]], $result);
    }

    public function test_it_fills_several_languages_in_one_call(): void
    {
        $asked = [['word' => 'home', 'pos' => 'noun', 'gloss' => null, 'example' => null]];

        $result = app(GeminiTranslator::class)->parse(
            '{"home":{"uz":["uy"],"ru":["дом"]}}',
            $asked,
            ['uz', 'ru'],
        );

        $this->assertSame(['home' => ['uz' => ['uy'], 'ru' => ['дом']]], $result);
    }

    public function test_each_language_is_held_to_its_own_script(): void
    {
        $asked = [['word' => 'home', 'pos' => null, 'gloss' => null, 'example' => null]];

        // Cyrillic is right for Russian and wrong for Uzbek; Latin the reverse.
        $result = app(GeminiTranslator::class)->parse(
            '{"home":{"uz":["уй","uy"],"ru":["dom","дом"]}}',
            $asked,
            ['uz', 'ru'],
        );

        $this->assertSame(['home' => ['uz' => ['uy'], 'ru' => ['дом']]], $result);
    }

    public function test_it_refuses_cyrillic_and_prose(): void
    {
        $asked = [
            ['word' => 'home', 'pos' => null, 'gloss' => null, 'example' => null],
            ['word' => 'dwelling', 'pos' => null, 'gloss' => null, 'example' => null],
        ];

        $result = app(GeminiTranslator::class)->parse(
            '{"home":{"uz":["уй","uy"]},"dwelling":{"uz":["odam yashaydigan va turadigan joy haqidagi umumiy tushuncha soʻzi"]}}',
            $asked,
        );

        // The Cyrillic form and the sentence-length "translation" both go.
        $this->assertSame(['home' => ['uz' => ['uy']]], $result);
    }

    public function test_it_survives_a_fenced_reply(): void
    {
        $asked = [['word' => 'home', 'pos' => null, 'gloss' => null, 'example' => null]];

        $result = app(GeminiTranslator::class)->parse(
            "```json\n{\"home\":{\"uz\":[\"uy\"]}}\n```",
            $asked,
        );

        $this->assertSame(['home' => ['uz' => ['uy']]], $result);
    }

    public function test_the_queue_starts_with_the_words_learners_meet(): void
    {
        $this->import([
            $this->entry(['word' => 'the', 'pos' => 'adv',
                'senses' => [['glosses' => ['With a comparative.']]]]),
            $this->entry(['word' => 'water', 'senses' => [['glosses' => ['A clear liquid.']]]]),
            $this->entry(['word' => 'quixotic', 'pos' => 'adj',
                'senses' => [['glosses' => ['Impractically idealistic.']]]]),
        ], ['the', 'water', 'quixotic']);

        $this->artisan('dictionary:translate --dry --limit=3')
            ->expectsOutputToContain('water')
            ->assertSuccessful();

        // Teachable first, then by how common the word is.
        $order = Word::where('translation_status', 'pending')
            ->orderByRaw('is_teachable desc, frequency_rank is null, frequency_rank')
            ->pluck('normalized')
            ->all();

        $this->assertSame(['water', 'quixotic', 'the'], $order);
    }

    public function test_a_translation_run_records_what_it_did(): void
    {
        $this->import([$this->entry()], ['home']);

        $this->swap(Translator::class, new class implements Translator
        {
            public function name(): string { return 'gemini'; }

            public function translate(array $words, array $locales = ['uz']): array
            {
                return ['home' => ['uz' => ['uy', 'turar joy']]];
            }
        });

        $this->artisan('dictionary:translate --limit=10')->assertSuccessful();

        $word = Word::where('normalized', 'home')->first();

        $this->assertSame(['uy', 'turar joy'], $word->translations['uz']);
        $this->assertSame('done', $word->translation_status);
        $this->assertSame('gemini', $word->translation_source);
        $this->assertFalse($word->needs_review);
        $this->assertNotNull($word->translated_at);
    }

    public function test_a_word_claude_cannot_translate_is_parked_not_retried_forever(): void
    {
        $this->import([$this->entry()], ['home']);

        $this->swap(Translator::class, new class implements Translator
        {
            public function name(): string { return 'gemini'; }

            public function translate(array $words, array $locales = ['uz']): array
            {
                return [];
            }
        });

        $this->artisan('dictionary:translate --limit=10')->assertSuccessful();

        $word = Word::where('normalized', 'home')->first();

        $this->assertSame('blank', $word->translation_status);
        $this->assertSame(1, $word->translation_attempts);

        // And it does not come back round on the next run.
        $this->assertSame(0, Word::where('translation_status', 'pending')->count());
    }

    public function test_a_failed_request_is_retryable_but_bounded(): void
    {
        $this->import([$this->entry()], ['home']);

        $this->swap(Translator::class, new class implements Translator
        {
            public function name(): string { return 'gemini'; }

            public function translate(array $words, array $locales = ['uz']): array
            {
                throw new \RuntimeException('rate limited');
            }
        });

        $this->artisan('dictionary:translate --limit=10')->assertSuccessful();
        $this->assertSame('failed', Word::where('normalized', 'home')->first()->translation_status);

        // `--retry` picks it up again...
        $this->artisan('dictionary:translate --limit=10 --retry')->assertSuccessful();
        $this->assertSame(2, Word::where('normalized', 'home')->first()->translation_attempts);

        // ...until the attempt ceiling, so a permanently broken word cannot
        // burn the budget on every run.
        $this->artisan('dictionary:translate --limit=10 --retry')->assertSuccessful();
        $this->artisan('dictionary:translate --limit=10 --retry')
            ->expectsOutputToContain('Tarjima kutayotgan soʼz yoʼq')
            ->assertSuccessful();
    }
}
