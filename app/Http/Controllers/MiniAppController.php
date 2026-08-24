<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class MiniAppController extends Controller
{
    /**
     * Serves the Mini App shell. No auth here on purpose: the page itself is
     * public, and every API call it makes carries the signed initData.
     */
    public function __invoke(): View
    {
        return view('miniapp', [
            'assets' => $this->assets(),
            'config' => [
                'apiUrl' => url('/api'),
                'botUsername' => ltrim((string) config('telegram.username'), '@'),
                'miniAppShortName' => config('telegram.mini_app.short_name'),
                'languages' => config('app.supported_locales'),
                'testTypes' => config('game.test_types'),
                'mastery' => config('game.mastery'),
                'duel' => config('game.duel'),
            ],
        ]);
    }

    /**
     * The interface is a separate repository, built and deployed on its own.
     * Laravel reads only the Vite manifest it leaves behind, so a frontend
     * release never requires touching the backend.
     *
     * @return array{js: ?string, css: list<string>}
     */
    protected function assets(): array
    {
        $manifestPath = config('miniapp.manifest');

        if (! is_file($manifestPath)) {
            return ['js' => null, 'css' => []];
        }

        $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
        $entry = $manifest[config('miniapp.entry')] ?? null;

        if (! $entry) {
            return ['js' => null, 'css' => []];
        }

        $base = rtrim(config('miniapp.asset_base'), '/');

        return [
            'js' => "{$base}/{$entry['file']}",
            'css' => array_map(fn (string $file) => "{$base}/{$file}", $entry['css'] ?? []),
        ];
    }
}
