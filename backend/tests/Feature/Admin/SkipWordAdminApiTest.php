<?php

use App\Models\SkipWord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// 管理 API のスキップ語(/api/admin/skip-words。#316)。
// 旧は自動取り込みの画面から足すだけ(GET /addSkipDB/{name})で、一覧・編集・削除と 422 は旧に無い。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('TC-316-16: 一覧は、すべての語を ID の順で返す', function () {
    $b = SkipWord::query()->create(['name' => 'Ice']);
    $a = SkipWord::query()->create(['name' => 'Garnish']);

    $this->getJson('/api/admin/skip-words')
        ->assertOk()
        ->assertExactJson(['data' => [['id' => $b->id, 'name' => 'Ice'], ['id' => $a->id, 'name' => 'Garnish']]]);
});

it('TC-316-17: 追加すると 201 で保存した語を返し、行が残る', function () {
    $response = $this->postJson('/api/admin/skip-words', ['name' => 'Ice'])->assertCreated();

    $word = SkipWord::query()->sole();
    $response->assertExactJson(['data' => ['id' => $word->id, 'name' => 'Ice']]);
});

it('TC-316-18: 更新すると 200 で書き換えた語を返し、自分の名前のままでも通る', function () {
    $word = SkipWord::query()->create(['name' => 'Ice']);

    $this->putJson("/api/admin/skip-words/{$word->id}", ['name' => 'Crushed Ice'])
        ->assertOk()
        ->assertExactJson(['data' => ['id' => $word->id, 'name' => 'Crushed Ice']]);
    $this->putJson("/api/admin/skip-words/{$word->id}", ['name' => 'Crushed Ice'])->assertOk();

    expect($word->fresh()?->name)->toBe('Crushed Ice');
});

it('TC-316-19: 削除すると 204 で行が消え、無い ID は 404', function () {
    $word = SkipWord::query()->create(['name' => 'Ice']);
    $kept = SkipWord::query()->create(['name' => 'Garnish']);

    $this->deleteJson("/api/admin/skip-words/{$word->id}")->assertNoContent();
    $this->deleteJson("/api/admin/skip-words/{$word->id}")->assertNotFound();
    $this->putJson('/api/admin/skip-words/'.($kept->id + 1), ['name' => 'x'])->assertNotFound();

    expect(SkipWord::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('TC-316-20: ほかの語と重なると 422', function () {
    SkipWord::query()->create(['name' => 'Ice']);
    $other = SkipWord::query()->create(['name' => 'Garnish']);

    $this->postJson('/api/admin/skip-words', ['name' => 'Ice'])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['name']);
    $this->putJson("/api/admin/skip-words/{$other->id}", ['name' => 'Ice'])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['name']);
});

it('TC-316-21: 名前は必須で 256 文字まで', function (string $name, int $status) {
    $this->postJson('/api/admin/skip-words', ['name' => $name])->assertStatus($status);
})->with([
    '空' => ['', 422],
    '256 文字' => [str_repeat('あ', 256), 201],
    '257 文字' => [str_repeat('あ', 257), 422],
]);

it('TC-316-22: ログインしていなければ、スキップ語の API はどれも 401', function (string $method, string $uri) {
    $word = SkipWord::query()->create(['name' => 'Ice']);
    auth()->logout();
    $this->app['auth']->forgetGuards();

    $this->json($method, str_replace('{id}', (string) $word->id, $uri), ['name' => 'x'])->assertUnauthorized();

    expect(SkipWord::query()->pluck('name')->all())->toBe(['Ice']);
})->with([
    '一覧' => ['GET', '/api/admin/skip-words'],
    '追加' => ['POST', '/api/admin/skip-words'],
    '更新' => ['PUT', '/api/admin/skip-words/{id}'],
    '削除' => ['DELETE', '/api/admin/skip-words/{id}'],
]);
