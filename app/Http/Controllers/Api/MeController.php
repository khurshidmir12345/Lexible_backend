<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Game\RoadMapService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    /** Everything the app needs to decide between onboarding and the map. */
    public function show(Request $request): array
    {
        return ['user' => $this->present($request->user())];
    }

    /** The nine onboarding screens submit here in one go. */
    public function onboard(Request $request, RoadMapService $road): array
    {
        $data = $request->validate([
            'native_lang' => ['required', Rule::in(config('app.supported_locales'))],
            'study_days' => ['required', 'array', 'min:1'],
            'study_days.*' => ['string', Rule::in(['Du', 'Se', 'Cho', 'Pa', 'Ju', 'Sha', 'Ya'])],
            'reminder_at' => ['required', 'date_format:H:i'],
            'cefr_level' => ['required', Rule::in(['A0', 'A1', 'A2', 'B1', 'B2', 'C1'])],
            'daily_goal' => ['required', 'integer', 'min:1', 'max:100'],
            'teacher_code' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $user->fill($data + ['onboarded' => true])->save();

        // The map is spaced by the daily goal, so it is built after onboarding.
        $road->forUser($user);

        return ['user' => $this->present($user->fresh())];
    }

    /** UT-00: the player says whether they are a student or a teacher. */
    public function chooseRole(Request $request): array
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['student', 'teacher'])],
        ]);

        $request->user()->update($data);

        return ['user' => $this->present($request->user()->fresh())];
    }

    /** Profile screen: language, study days, reminder time, dark mode. */
    public function update(Request $request): array
    {
        $data = $request->validate([
            'native_lang' => ['sometimes', Rule::in(config('app.supported_locales'))],
            'study_days' => ['sometimes', 'array', 'min:1'],
            'study_days.*' => ['string', Rule::in(['Du', 'Se', 'Cho', 'Pa', 'Ju', 'Sha', 'Ya'])],
            'reminder_at' => ['sometimes', 'date_format:H:i'],
            'daily_goal' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'dark_mode' => ['sometimes', 'boolean'],
            'reminders_enabled' => ['sometimes', 'boolean'],
        ]);

        $request->user()->fill($data)->save();

        return ['user' => $this->present($request->user()->fresh())];
    }

    protected function present($user): array
    {
        return [
            'id' => $user->id,
            'telegram_id' => $user->telegram_id,
            'name' => $user->full_name,
            'username' => $user->username,
            'initial' => $user->initial,
            'photo' => $user->photo_url,
            'onboarded' => $user->onboarded,
            'role' => $user->role,
            'native_lang' => $user->native_lang,
            'study_days' => $user->study_days ?? [],
            'reminder_at' => $user->reminder_at ? substr((string) $user->reminder_at, 0, 5) : null,
            'cefr_level' => $user->cefr_level,
            'daily_goal' => $user->daily_goal,
            'teacher_code' => $user->teacher_code,
            'dark_mode' => $user->dark_mode,
            'streak_days' => $user->streak_days,
            'best_streak' => $user->best_streak,
            'words_learned' => $user->words_learned,
            'coins' => $user->coins,
            'is_premium' => $user->isPremium(),
            'premium_until' => $user->premium_until?->toDateString(),
        ];
    }
}
