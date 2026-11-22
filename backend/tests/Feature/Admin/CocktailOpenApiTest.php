<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

// カクテルの新規・更新の本文の OpenAPI(#721)。更新では材料(ingredients)を送らなければ今の材料を残す(#720)ので、
// 更新の本文の定義では ingredients を任意に、新規では必須のままにする。旧に OpenAPI は無い。
// OpenAPI は scramble:export が書き出したものを読む(CI の「Check OpenAPI is up to date」と同じコマンド)。
// 書き出しでは、条件検索の画面の API が DB のカテゴリを読むので、DB を使う。

uses(RefreshDatabase::class)->group('db');

/**
 * scramble:export で書き出した OpenAPI の、パスと操作の multipart の本文のスキーマ(参照を解いたもの)から、
 * 材料(ingredients)の定義と必須の項目の名前を返す。
 *
 * @return array{ingredients: mixed, required: list<string>}
 */
function cocktailRequestBodySchema(string $path, string $method): array
{
    $file = tempnam(sys_get_temp_dir(), 'openapi');
    if ($file === false) {
        throw new RuntimeException('一時ファイルを作れない');
    }
    try {
        expect(Artisan::call('scramble:export', ['--path' => $file]))->toBe(0);
        $document = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        @unlink($file);
    }
    $ref = data_get($document, "paths.{$path}.{$method}.requestBody.content.multipart/form-data.schema.\$ref");
    if (! is_string($ref)) {
        throw new RuntimeException("{$method} {$path} の本文のスキーマの参照が無い");
    }
    $schema = data_get($document, 'components.schemas.'.str_replace('#/components/schemas/', '', $ref));
    $required = data_get($schema, 'required');
    if (! is_array($schema) || ! is_array($required)) {
        throw new RuntimeException("{$method} {$path} の本文のスキーマが無い");
    }

    return [
        'ingredients' => data_get($schema, 'properties.ingredients'),
        'required' => array_values(array_filter($required, is_string(...))),
    ];
}

it('TC-721-01: 新規(POST /admin/cocktails)の本文では、ingredients が必須', function () {
    $schema = cocktailRequestBodySchema('/admin/cocktails', 'post');

    expect($schema['ingredients'])->toBeArray()
        ->and($schema['required'])->toContain('ingredients');
});

it('TC-721-02: 更新(PUT /admin/cocktails/{cocktail})の本文では、ingredients は任意で、ほかの必須の項目は新規と同じ', function () {
    $create = cocktailRequestBodySchema('/admin/cocktails', 'post');
    $update = cocktailRequestBodySchema('/admin/cocktails/{cocktail}', 'put');

    expect($update['ingredients'])->toBeArray()
        ->and($update['ingredients'])->toBe($create['ingredients'])
        ->and($update['required'])->not->toContain('ingredients')
        ->and($update['required'])->toBe(array_values(array_diff($create['required'], ['ingredients'])));
});
