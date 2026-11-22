<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\SoftwareAuthenticator;

// パスキーのログイン・登録の完了の本文の OpenAPI(#608)。credential.response を string[] ではなく、
// ブラウザが送るオブジェクト(PublicKeyCredential.toJSON() の response)として出す。旧にパスキーは無い。
// OpenAPI は scramble:export が書き出したものを読む(CI の「Check OpenAPI is up to date」と同じコマンド)。
// 書き出しでは、条件検索の画面の API が DB のカテゴリを読むので、DB を使う。

uses(RefreshDatabase::class)->group('db');

/**
 * scramble:export で書き出した OpenAPI の、本文のスキーマの credential.response。
 *
 * @return array<mixed>
 */
function passkeyResponseSchema(string $schemaName): array
{
    $path = tempnam(sys_get_temp_dir(), 'openapi');
    expect($path)->toBeString();
    try {
        expect(Artisan::call('scramble:export', ['--path' => $path]))->toBe(0);
        $document = json_decode((string) file_get_contents((string) $path), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        @unlink((string) $path);
    }
    $response = data_get($document, "components.schemas.{$schemaName}.properties.credential.properties.response");

    return is_array($response) ? $response : throw new RuntimeException("{$schemaName} に credential.response が無い");
}

it('TC-608-01: ログインの完了の credential.response は、署名を持つオブジェクトとして出る', function () {
    expect(passkeyResponseSchema('PasskeyVerificationRequest'))->toBe([
        'type' => 'object',
        'properties' => [
            'clientDataJSON' => ['type' => 'string'],
            'authenticatorData' => ['type' => 'string'],
            'signature' => ['type' => 'string'],
            'userHandle' => ['type' => ['string', 'null']],
        ],
        'required' => ['clientDataJSON', 'authenticatorData', 'signature'],
    ]);
});

it('TC-608-02: 登録の完了の credential.response は、attestationObject を持つオブジェクトとして出る', function () {
    expect(passkeyResponseSchema('PasskeyRegistrationRequest'))->toBe([
        'type' => 'object',
        'properties' => [
            'clientDataJSON' => ['type' => 'string'],
            'attestationObject' => ['type' => 'string'],
            'transports' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['clientDataJSON', 'attestationObject'],
    ]);
});

it('TC-608-03: ブラウザと同じ形で作った応答は、OpenAPI の必須のキーをすべて持ち、OpenAPI に無いキーを持たない', function () {
    $authenticator = new SoftwareAuthenticator;
    $options = ['challenge' => SoftwareAuthenticator::b64(random_bytes(32))];
    $origin = config()->string('app.url');

    $cases = [
        'PasskeyVerificationRequest' => $authenticator->login($options, $origin, 'user-handle')['response'],
        'PasskeyRegistrationRequest' => $authenticator->register($options, $origin)['response'],
    ];
    foreach ($cases as $schemaName => $response) {
        $schema = passkeyResponseSchema($schemaName);
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        expect($required)->not->toBeEmpty()
            ->and(array_keys($response))->toContain(...$required)
            ->and(array_keys($properties))->toContain(...array_keys($response));
    }
});
