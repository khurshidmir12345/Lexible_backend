<?php

namespace Tests\Unit;

use App\Models\Word;
use App\Services\Dictionary\DictionaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationCleaningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_drops_english_words_offered_as_uzbek_translations(): void
    {
        // MyMemory is built from software localisation files and answers "make"
        // with "bluetooth". Our own dictionary is what exposes that.
        Word::create(['word' => 'bluetooth', 'translations' => ['uz' => ['bluetuz']]]);
        Word::create(['word' => 'watch', 'translations' => ['uz' => ['soat']]]);

        $cleaned = app(DictionaryService::class)->rejectEnglish([
            'uz' => ['bluetooth', 'yasamoq'],
            'ru' => ['делать'],
        ]);

        $this->assertSame(['yasamoq'], $cleaned['uz']);
        $this->assertSame(['делать'], $cleaned['ru'], 'cyrillic targets are left alone');
    }

    public function test_a_language_disappears_when_every_variant_was_junk(): void
    {
        Word::create(['word' => 'bluetooth', 'translations' => ['uz' => ['bluetuz']]]);

        $cleaned = app(DictionaryService::class)->rejectEnglish(['uz' => ['bluetooth']]);

        $this->assertArrayNotHasKey('uz', $cleaned);
    }

    public function test_it_leaves_real_translations_untouched(): void
    {
        Word::create(['word' => 'water', 'translations' => ['uz' => ['suv']]]);

        $input = ['uz' => ['suv', 'ichimlik'], 'kk' => ['су']];

        $this->assertSame($input, app(DictionaryService::class)->rejectEnglish($input));
    }
}
