<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class MiniAppController extends Controller
{
    /**
     * Serves the Mini App shell. No auth here on purpose: the page itself is
     * public, and every API call it makes carries the signed initData.
     *
     * The shell must never be cached: it is the only thing that names the
     * hashed asset files, so a cached copy would keep serving yesterday's
     * build long after a deploy. The assets themselves are immutable and
     * cached hard by nginx.
     */
    public function __invoke(): Response
    {
        return response()->view('miniapp', [
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
        ])->withHeaders([
            'Cache-Control' => 'no-store, must-revalidate',
            'Pragma' => 'no-cache',
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
