<?php

namespace App\Services\Telegram;

use Illuminate\Support\Arr;

/**
 * Verifies the `initData` string a Mini App receives from Telegram.
 *
 * Telegram signs it with HMAC-SHA256 using a key derived from the bot token,
 * so a valid signature proves the payload really describes the current user
 * and was not tampered with in the WebView.
 *
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class InitDataValidator
{
    public function __construct(
        protected ?string $token = null,
        protected ?int $ttl = null,
    ) {
        $this->token ??= config('telegram.token');
        $this->ttl ??= config('telegram.mini_app.init_data_ttl');
    }

    /**
     * @return array<string, mixed> the parsed, verified payload
     *
     * @throws InvalidInitDataException
     */
    public function validate(?string $initData): array
    {
        if (blank($initData)) {
            throw new InvalidInitDataException('initData is missing.');
        }

        parse_str($initData, $params);

        $hash = Arr::pull($params, 'hash');
        if (blank($hash)) {
            throw new InvalidInitDataException('initData has no hash.');
        }

        // Signature is computed over "key=value" pairs sorted by key, joined by \n.
        ksort($params);
        $checkString = collect($params)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode("\n");

        $secretKey = hash_hmac('sha256', $this->token, 'WebAppData', true);
        $expected = hash_hmac('sha256', $checkString, $secretKey);

        if (! hash_equals($expected, $hash)) {
            throw new InvalidInitDataException('initData signature mismatch.');
        }

        $authDate = (int) ($params['auth_date'] ?? 0);
        if ($this->ttl > 0 && (time() - $authDate) > $this->ttl) {
            throw new InvalidInitDataException('initData has expired.');
        }

        // `user`, `chat` and `receiver` arrive as JSON strings.
        foreach (['user', 'chat', 'receiver'] as $jsonKey) {
            if (isset($params[$jsonKey])) {
                $params[$jsonKey] = json_decode($params[$jsonKey], true) ?: [];
            }
        }

        if (empty($params['user']['id'])) {
            throw new InvalidInitDataException('initData carries no user.');
        }

        return $params;
    }
}
