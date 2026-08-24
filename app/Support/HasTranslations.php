<?php

namespace App\Support;

/**
 * Minimal JSON translation support: a column holds {"uz": "...", "ru": "..."}
 * and `$model->trans('name')` resolves it against the current locale with a
 * fallback chain. Adding a language never requires a migration.
 */
trait HasTranslations
{
    public function trans(string $attribute, ?string $locale = null): ?string
    {
        $value = $this->getAttribute($attribute);

        if (! is_array($value)) {
            return $value;
        }

        foreach ([$locale ?? app()->getLocale(), config('app.fallback_locale'), 'uz', 'en', 'ru'] as $candidate) {
            if (! empty($value[$candidate])) {
                return $value[$candidate];
            }
        }

        return collect($value)->filter()->first();
    }
}
