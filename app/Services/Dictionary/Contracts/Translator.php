<?php

namespace App\Services\Dictionary\Contracts;

/**
 * Turns English dictionary entries into translations.
 *
 * A word on its own cannot be translated — "home" comes back as the label from
 * a software menu, and "light" is a coin flip. Every implementation is handed
 * the part of speech, the English definition and an example, and is expected
 * to translate the word *as used in that sense*.
 *
 * Several languages are asked for in one call: the entries dominate the input
 * cost, so a second language is nearly free while a second pass is not.
 */
interface Translator
{
    /**
     * @param  list<array{word: string, pos: ?string, gloss: ?string, example: ?string}>  $words
     * @param  list<string>  $locales  e.g. ['uz'] or ['uz', 'ru']
     * @return array<string, array<string, list<string>>>  word => locale => forms, best first
     */
    public function translate(array $words, array $locales = ['uz']): array;

    /** Which model or service produced these, for `words.translation_source`. */
    public function name(): string;
}
