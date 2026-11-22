<?php

// 検証のメッセージを日本語にする(#651。決めたことは docs/decisions/validation-messages.md)。
// lang/ja を置き、APP_LOCALE=ja(サーバーの .env と .env.example)で、管理 API・公開の API の 422 を日本語にする。

/**
 * Laravel の英語のメッセージのキー(入れ子は「between.string」のように「.」でつなぐ)。
 *
 * @param  array<mixed>  $lines
 * @return list<string>
 */
function validationMessageKeys(array $lines, string $prefix = ''): array
{
    $keys = [];
    foreach ($lines as $key => $line) {
        if (in_array($prefix.$key, ['custom', 'attributes'], true)) {
            continue;
        }
        $keys = [...$keys, ...(is_array($line) ? validationMessageKeys($line, $prefix.$key.'.') : [$prefix.$key])];
    }

    return $keys;
}

it('TC-651-01: ロケールは ja になる(CI と同じく .env.example から作った .env で)', function () {
    expect(app()->getLocale())->toBe('ja')
        ->and(__('validation.required', ['attribute' => 'name']))->toBe('nameを入力してください。');
});

it('TC-651-02: Laravel の英語のメッセージのキーが、lang/ja/validation.php にすべてある', function () {
    $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $ja = require lang_path('ja/validation.php');
    if (! is_array($en) || ! is_array($ja)) {
        throw new RuntimeException('validation.php が配列を返さない');
    }

    expect(array_values(array_diff(validationMessageKeys($en), validationMessageKeys($ja))))->toBe([]);
});
