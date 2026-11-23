<?php

use App\Http\Middleware\Idempotent;
use App\Models\Brand;
use App\Models\Category;
use App\Models\IdempotencyKey;
use App\Models\Paraphrase;
use App\Models\User;
use App\Services\CategoryTree;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

// 管理 API の言い換え辞書(/api/admin/paraphrases。#316)。
// 旧には保存の画面が無いので、追加・更新・削除と 422 は旧に無い。名前の解決は旧の serachFromWords を移したもので、
// 旧と変えたところは docs/decisions/paraphrase-target.md の「旧との差分」。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * 旧のカテゴリ・ミドルと同じ形の木。
 *
 * リキュール(根)── クレーム・ド・カカオ ── ホワイト
 *                                       └ ブラウン
 *                └ コーヒー・リキュール
 * 蒸留酒(根)── ジン ── ドライ・ジン ── ロンドン・ドライ・ジン
 *                      └ GIN
 *
 * @return array<string, Category>
 */
function paraphraseTree(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name, string $nameEng): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'name_search' => NameNormalizer::normalize($name), 'name_eng' => $nameEng])->getAttributes(),
    );

    $n = [];
    $n['リキュール'] = $add(null, 'リキュール', 'Liqueur');
    $n['クレーム・ド・カカオ'] = $add($n['リキュール'], 'クレーム・ド・カカオ', 'Creme de Cacao');
    $n['ホワイト'] = $add($n['クレーム・ド・カカオ'], 'ホワイト', 'White');
    $n['ブラウン'] = $add($n['クレーム・ド・カカオ'], 'ブラウン', 'Brown');
    $n['コーヒー・リキュール'] = $add($n['リキュール'], 'コーヒー・リキュール', 'Coffee Liqueur');
    $n['蒸留酒'] = $add(null, '蒸留酒', 'Spirits');
    $n['ジン'] = $add($n['蒸留酒'], 'ジン', 'Gin');
    $n['ドライ・ジン'] = $add($n['ジン'], 'ドライ・ジン', 'Dry Gin');
    $n['GIN'] = $add($n['ジン'], 'GIN', 'Gin (Latin)');
    $n['ロンドン・ドライ・ジン'] = $add($n['ドライ・ジン'], 'ロンドン・ドライ・ジン', 'London Dry Gin');

    return $n;
}

function paraphraseBrand(Category $node, string $name, string $nameEng): Brand
{
    return Brand::factory()->create([
        'category_id' => $node->id,
        'name' => $name,
        'name_search' => NameNormalizer::normalize($name),
        'name_eng' => $nameEng,
    ]);
}

/**
 * @return array{id: int, name: string, name_eng: string}
 */
function paraphraseNodeRef(Category $node): array
{
    return ['id' => $node->id, 'name' => $node->name, 'name_eng' => $node->name_eng];
}

/**
 * @param  array<array-key, mixed>  $overrides  項目の名前 → 値(データセットから受けるので、鍵の型は絞らない)
 * @return array<array-key, mixed>
 */
function paraphraseInput(array $overrides = []): array
{
    return [
        'name' => 'カカオ・リキュール',
        'converted' => 'クレーム・ド・カカオ',
        'middle' => null,
        'command' => null,
        'note' => null,
        ...$overrides,
    ];
}

it('TC-316-01: 一覧は ID の順で、解決した先(ノードのパンくず・銘柄)と、解決できない語は resolved: null を返す', function () {
    $n = paraphraseTree();
    $kahlua = paraphraseBrand($n['コーヒー・リキュール'], 'カルーア', 'Kahlua');
    $node = Paraphrase::query()->create(['name' => 'ホワイト・カカオ', 'converted' => 'クレーム・ド・カカオ', 'middle' => 'ホワイト', 'command' => 'hot', 'note' => 'メモ']);
    $brand = Paraphrase::query()->create(['name' => 'Kahlua', 'converted' => 'カルーア']);
    $lost = Paraphrase::query()->create(['name' => '古い名前', 'converted' => '消えたノード']);

    $response = $this->getJson('/api/admin/paraphrases')->assertOk();

    expect($response->json('data'))->toBe([
        [
            'id' => $node->id, 'name' => 'ホワイト・カカオ', 'converted' => 'クレーム・ド・カカオ', 'middle' => 'ホワイト',
            'command' => 'hot', 'note' => 'メモ',
            'resolved' => [
                'path' => [paraphraseNodeRef($n['リキュール']), paraphraseNodeRef($n['クレーム・ド・カカオ']), paraphraseNodeRef($n['ホワイト'])],
                'brand' => null,
            ],
        ],
        [
            'id' => $brand->id, 'name' => 'Kahlua', 'converted' => 'カルーア', 'middle' => null,
            'command' => null, 'note' => null,
            'resolved' => [
                'path' => [paraphraseNodeRef($n['リキュール']), paraphraseNodeRef($n['コーヒー・リキュール'])],
                'brand' => ['id' => $kahlua->id, 'name' => 'カルーア', 'name_eng' => 'Kahlua'],
            ],
        ],
        [
            'id' => $lost->id, 'name' => '古い名前', 'converted' => '消えたノード', 'middle' => null,
            'command' => null, 'note' => null, 'resolved' => null,
        ],
    ])->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.per_page'))->toBe(50);
});

it('TC-316-02: q は変換前・変換後・ミドルのどこかに含む語に絞り、% と _ は文字として比べる', function (string $q, array $expected) {
    Paraphrase::query()->create(['name' => 'ホワイト・カカオ', 'converted' => 'クレーム・ド・カカオ', 'middle' => 'ホワイト']);
    Paraphrase::query()->create(['name' => 'Kahlua', 'converted' => 'カルーア']);
    Paraphrase::query()->create(['name' => '100%オレンジ', 'converted' => 'オレンジ・ジュース']);
    Paraphrase::query()->create(['name' => 'A_B', 'converted' => 'エー']);

    $names = $this->getJson('/api/admin/paraphrases?q='.urlencode($q))->assertOk()->collect('data')->pluck('name')->all();

    expect($names)->toBe($expected);
})->with([
    '変換前に含む' => ['ahl', ['Kahlua']],
    '変換後に含む' => ['ド・カ', ['ホワイト・カカオ']],
    'ミドルに含む' => ['ホワイ', ['ホワイト・カカオ']],
    '% は文字' => ['0%', ['100%オレンジ']],
    '_ は文字' => ['_', ['A_B']],
    'どれにも無い' => ['ウォッカ', []],
]);

it('TC-316-03: 1 ページは 50 件で、51 件目は 2 ページ目に出る', function () {
    foreach (range(1, 51) as $i) {
        Paraphrase::query()->create(['name' => sprintf('語%02d', $i), 'converted' => 'ジン']);
    }

    $first = $this->getJson('/api/admin/paraphrases')->assertOk();
    $second = $this->getJson('/api/admin/paraphrases?page=2')->assertOk();

    expect($first->json('data'))->toHaveCount(50)
        ->and($first->json('data.49.name'))->toBe('語50')
        ->and($second->json('data'))->toHaveCount(1)
        ->and($second->json('data.0.name'))->toBe('語51');
});

it('TC-316-04: 1 語を取得でき、無い ID は 404', function () {
    $n = paraphraseTree();
    $p = Paraphrase::query()->create(['name' => 'ジン(ドライ)', 'converted' => 'ドライ・ジン']);

    $this->getJson("/api/admin/paraphrases/{$p->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'ジン(ドライ)')
        ->assertJsonPath('data.resolved.path', [paraphraseNodeRef($n['蒸留酒']), paraphraseNodeRef($n['ジン']), paraphraseNodeRef($n['ドライ・ジン'])]);
    $this->getJson('/api/admin/paraphrases/'.($p->id + 1))->assertNotFound();
});

it('TC-316-05: 追加すると 201 で保存した語を返し、行が残る', function () {
    $n = paraphraseTree();

    $this->postJson('/api/admin/paraphrases', paraphraseInput(['command' => 'hot', 'note' => 'ホットで']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'カカオ・リキュール')
        ->assertJsonPath('data.command', 'hot')
        ->assertJsonPath('data.resolved.path', [paraphraseNodeRef($n['リキュール']), paraphraseNodeRef($n['クレーム・ド・カカオ'])]);

    expect(Paraphrase::query()->sole()->only(['name', 'converted', 'middle', 'command', 'note']))->toBe([
        'name' => 'カカオ・リキュール', 'converted' => 'クレーム・ド・カカオ', 'middle' => null, 'command' => 'hot', 'note' => 'ホットで',
    ]);
});

it('TC-316-06: 変換後は、ノードの名前・銘柄の名前・深いノードの名前で解決できる', function (string $converted, string $path, ?string $brand) {
    $n = paraphraseTree();
    $kahlua = paraphraseBrand($n['コーヒー・リキュール'], 'カルーア', 'Kahlua');

    $this->postJson('/api/admin/paraphrases', paraphraseInput(['converted' => $converted]))
        ->assertCreated()
        ->assertJsonPath('data.resolved', [
            'path' => array_map(fn (string $name) => paraphraseNodeRef($n[$name]), explode(' > ', $path)),
            'brand' => $brand === null ? null : ['id' => $kahlua->id, 'name' => 'カルーア', 'name_eng' => 'Kahlua'],
        ]);
})->with([
    '根のすぐ下のノード' => ['ジン', '蒸留酒 > ジン', null],
    '銘柄' => ['カルーア', 'リキュール > コーヒー・リキュール', 'カルーア'],
    'それより深いノード' => ['ブラウン', 'リキュール > クレーム・ド・カカオ > ブラウン', null],
    '区切りの揺れ' => ['ドライジン', '蒸留酒 > ジン > ドライ・ジン', null],
    '全角英数の揺れ' => ['ＧＩＮ', '蒸留酒 > ジン > GIN', null],
]);

it('TC-316-07: 同じ名前のノードと銘柄があれば、根のすぐ下のノード → 銘柄 → 深いノードの順に選ぶ', function () {
    $n = paraphraseTree();
    // 根のすぐ下のノード「ジン」と同じ名前の銘柄、深いノード「ホワイト」と同じ名前の銘柄
    paraphraseBrand($n['ドライ・ジン'], 'ジン', 'Gin Brand');
    $white = paraphraseBrand($n['ドライ・ジン'], 'ホワイト', 'White Brand');

    $this->postJson('/api/admin/paraphrases', paraphraseInput(['name' => 'a', 'converted' => 'ジン']))
        ->assertCreated()
        ->assertJsonPath('data.resolved.path', [paraphraseNodeRef($n['蒸留酒']), paraphraseNodeRef($n['ジン'])])
        ->assertJsonPath('data.resolved.brand', null);
    $this->postJson('/api/admin/paraphrases', paraphraseInput(['name' => 'b', 'converted' => 'ホワイト']))
        ->assertCreated()
        ->assertJsonPath('data.resolved.brand.id', $white->id);
});

it('TC-316-08: ミドルがあれば、変換後のノードの配下(子・孫)から選ぶ', function (string $converted, string $middle, string $path) {
    $n = paraphraseTree();

    $this->postJson('/api/admin/paraphrases', paraphraseInput(['converted' => $converted, 'middle' => $middle]))
        ->assertCreated()
        ->assertJsonPath('data.middle', $middle)
        ->assertJsonPath('data.resolved', [
            'path' => array_map(fn (string $name) => paraphraseNodeRef($n[$name]), explode(' > ', $path)),
            'brand' => null,
        ]);
})->with([
    '子' => ['クレーム・ド・カカオ', 'ブラウン', 'リキュール > クレーム・ド・カカオ > ブラウン'],
    '孫' => ['ジン', 'ロンドン・ドライ・ジン', '蒸留酒 > ジン > ドライ・ジン > ロンドン・ドライ・ジン'],
]);

it('TC-316-09: 解決できない名前は 422 で、どの項目かを返す', function (string $converted, ?string $middle, string $field) {
    $n = paraphraseTree();
    paraphraseBrand($n['コーヒー・リキュール'], 'カルーア', 'Kahlua');

    $this->postJson('/api/admin/paraphrases', paraphraseInput(['converted' => $converted, 'middle' => $middle]))
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$field]);

    expect(Paraphrase::query()->count())->toBe(0);
})->with([
    '変換後がノード・銘柄のどれでもない' => ['ウォッカ', null, 'converted'],
    '変換後が根(種別)の名前' => ['リキュール', null, 'converted'],
    'ミドルが変換後の配下に無い(別の木にある)' => ['クレーム・ド・カカオ', 'ドライ・ジン', 'middle'],
    'ミドルが変換後の配下に無い(どこにも無い)' => ['クレーム・ド・カカオ', 'ゴールド', 'middle'],
    'ミドルがあるのに変換後が銘柄' => ['カルーア', 'ホワイト', 'converted'],
]);

it('TC-316-10: 入力の形が違えば 422', function (string $field, string $value) {
    paraphraseTree();

    $this->postJson('/api/admin/paraphrases', paraphraseInput([$field => $value]))
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$field]);
})->with([
    '変換前が空' => ['name', ''],
    '変換前が 256 文字' => ['name', str_repeat('あ', 256)],
    '変換後が空' => ['converted', ''],
    'command が hot 以外' => ['command', 'cold'],
]);

it('TC-316-11: 変換前は 255 文字まで、command は null か hot で通る', function (string $field, ?string $value) {
    paraphraseTree();

    $this->postJson('/api/admin/paraphrases', paraphraseInput([$field => $value]))->assertCreated();
})->with([
    '変換前が 255 文字' => ['name', str_repeat('あ', 255)],
    'command が null' => ['command', null],
    'command が hot' => ['command', 'hot'],
]);

it('TC-316-12: 変換前がほかの語と重なると 422、更新で自分の名前のままなら通る', function () {
    paraphraseTree();
    $other = Paraphrase::query()->create(['name' => 'ジン', 'converted' => 'ジン']);
    $self = Paraphrase::query()->create(['name' => 'ドライジン', 'converted' => 'ドライ・ジン']);

    $this->postJson('/api/admin/paraphrases', paraphraseInput(['name' => 'ジン']))
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['name']);
    $this->putJson("/api/admin/paraphrases/{$self->id}", paraphraseInput(['name' => 'ジン']))
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['name']);
    $this->putJson("/api/admin/paraphrases/{$self->id}", paraphraseInput(['name' => 'ドライジン', 'converted' => 'ジン']))
        ->assertOk();

    expect($other->fresh()?->name)->toBe('ジン');
});

it('TC-316-13: 更新すると 200 で書き換えた語を返し、行が書き換わる', function () {
    $n = paraphraseTree();
    $p = Paraphrase::query()->create(['name' => 'カカオ', 'converted' => 'クレーム・ド・カカオ', 'command' => 'hot', 'note' => '前']);

    $this->putJson("/api/admin/paraphrases/{$p->id}", paraphraseInput(['name' => 'カカオ(白)', 'middle' => 'ホワイト']))
        ->assertOk()
        ->assertJsonPath('data.id', $p->id)
        ->assertJsonPath('data.resolved.path.2', paraphraseNodeRef($n['ホワイト']));

    expect($p->fresh()?->only(['name', 'converted', 'middle', 'command', 'note']))->toBe([
        'name' => 'カカオ(白)', 'converted' => 'クレーム・ド・カカオ', 'middle' => 'ホワイト', 'command' => null, 'note' => null,
    ]);
});

it('TC-316-23: 更新でキーを省くと、既存の middle・command・note は null になる', function () {
    paraphraseTree();
    $p = Paraphrase::query()->create(['name' => 'カカオ', 'converted' => 'クレーム・ド・カカオ', 'middle' => 'ホワイト', 'command' => 'hot', 'note' => '前']);

    $this->putJson("/api/admin/paraphrases/{$p->id}", ['name' => 'カカオ', 'converted' => 'クレーム・ド・カカオ'])->assertOk();

    expect($p->fresh()?->only(['middle', 'command', 'note']))->toBe(['middle' => null, 'command' => null, 'note' => null]);
});

it('TC-316-14:削除すると 204 で行が消え、無い ID は 404', function () {
    $p = Paraphrase::query()->create(['name' => 'カカオ', 'converted' => 'クレーム・ド・カカオ']);
    $kept = Paraphrase::query()->create(['name' => 'ジン', 'converted' => 'ジン']);

    $this->deleteJson("/api/admin/paraphrases/{$p->id}")->assertNoContent();
    $this->deleteJson("/api/admin/paraphrases/{$p->id}")->assertNotFound();
    $this->putJson('/api/admin/paraphrases/'.($kept->id + 1), paraphraseInput())->assertNotFound();

    expect(Paraphrase::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('TC-316-15: ログインしていなければ、言い換え辞書の API はどれも 401', function (string $method, string $uri) {
    $p = Paraphrase::query()->create(['name' => 'カカオ', 'converted' => 'クレーム・ド・カカオ']);
    auth()->logout();
    $this->app['auth']->forgetGuards();

    $this->json($method, str_replace('{id}', (string) $p->id, $uri), paraphraseInput(['name' => '別']))->assertUnauthorized();

    expect(Paraphrase::query()->pluck('name')->all())->toBe(['カカオ']);
})->with([
    '一覧' => ['GET', '/api/admin/paraphrases'],
    '取得' => ['GET', '/api/admin/paraphrases/{id}'],
    '追加' => ['POST', '/api/admin/paraphrases'],
    '更新' => ['PUT', '/api/admin/paraphrases/{id}'],
    '削除' => ['DELETE', '/api/admin/paraphrases/{id}'],
]);

// 言い換えの新規の冪等キー(#762。決まりは docs/decisions/admin-idempotency.md)。仕組み(ミドルウェア idempotent)そのものは
// IdempotencyApiTest(#657)で確かめているので、ここでは言い換えの新規につないだことを確かめる。旧に冪等キーは無い(旧を動かして確かめてはいない)。

const PARAPHRASE_IDEMPOTENCY_KEY = '3b9e6c1a-7d2f-4e8b-a5c0-91f4d6e2b7a8';

it('TC-762-01: Idempotency-Key を付けなければ、同じ本文の 2 回目は今までどおり変換前の重なりの 422 で断り、記録を残さない', function () {
    paraphraseTree();

    $this->postJson('/api/admin/paraphrases', paraphraseInput())->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);
    $this->postJson('/api/admin/paraphrases', paraphraseInput())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(Paraphrase::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('TC-762-02: 同じ Idempotency-Key で再送すると、言い換えを 1 件だけ作り、1 回目と同じ応答を返す', function () {
    paraphraseTree();
    $headers = [Idempotent::HEADER => PARAPHRASE_IDEMPOTENCY_KEY];

    $first = $this->postJson('/api/admin/paraphrases', paraphraseInput(['command' => 'hot', 'note' => 'ホットで']), $headers)
        ->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);
    $second = $this->postJson('/api/admin/paraphrases', paraphraseInput(['command' => 'hot', 'note' => 'ホットで']), $headers)
        ->assertCreated()
        ->assertHeader(Idempotent::REPLAYED_HEADER, 'true');

    $paraphrase = Paraphrase::query()->sole();
    expect($second->json())->toBe($first->json())
        ->and($second->json('data.id'))->toBe($paraphrase->id)
        ->and($paraphrase->name)->toBe('カカオ・リキュール')
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('TC-762-03: 同じ Idempotency-Key で中身(変換前・変換後・command)の違う送信は 422 で、作らず、1 回目の応答も返さない', function (array $overrides) {
    paraphraseTree();
    $headers = [Idempotent::HEADER => PARAPHRASE_IDEMPOTENCY_KEY];
    $this->postJson('/api/admin/paraphrases', paraphraseInput(), $headers)->assertCreated();

    $this->postJson('/api/admin/paraphrases', paraphraseInput($overrides), $headers)
        ->assertUnprocessable()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER)
        ->assertExactJson([
            'message' => '冪等キー(Idempotency-Key)は、別の内容の送信に使われています。',
            'errors' => ['idempotency_key' => ['冪等キー(Idempotency-Key)は、別の内容の送信に使われています。']],
        ]);

    expect(Paraphrase::query()->pluck('name')->all())->toBe(['カカオ・リキュール']);
})->with([
    '変換前' => [['name' => 'カカオ']],
    '変換後' => [['converted' => 'コーヒー・リキュール']],
    'command' => [['command' => 'hot']],
]);

it('TC-762-04: 検証に落ちた新規(変換後を解決できない)は記録を残さず、直して同じ Idempotency-Key で送ると作る', function () {
    paraphraseTree();
    $headers = [Idempotent::HEADER => PARAPHRASE_IDEMPOTENCY_KEY];

    $this->postJson('/api/admin/paraphrases', paraphraseInput(['converted' => '無いノード']), $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('converted');
    expect(IdempotencyKey::query()->count())->toBe(0)
        ->and(Paraphrase::query()->count())->toBe(0);

    $this->postJson('/api/admin/paraphrases', paraphraseInput(), $headers)
        ->assertCreated()
        ->assertHeaderMissing(Idempotent::REPLAYED_HEADER);

    expect(Paraphrase::query()->pluck('name')->all())->toBe(['カカオ・リキュール'])
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('TC-762-09: OpenAPI では、言い換え・スキップ語の新規に任意の Idempotency-Key ヘッダ(UUID)があり、更新には無い', function () {
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
    $headers = function (string $path, string $method) use ($document): array {
        $parameters = data_get($document, "paths.{$path}.{$method}.parameters", []);

        return is_array($parameters)
            ? array_values(array_filter($parameters, fn (mixed $parameter): bool => data_get($parameter, 'in') === 'header'))
            : [];
    };

    foreach (['/admin/paraphrases', '/admin/skip-words'] as $path) {
        $store = $headers($path, 'post');
        expect($store)->toHaveCount(1)
            ->and(data_get($store[0], 'name'))->toBe('Idempotency-Key')
            ->and(data_get($store[0], 'required', false))->toBeFalse()
            ->and(data_get($store[0], 'schema'))->toBe(['type' => 'string', 'format' => 'uuid']);
    }
    expect($headers('/admin/paraphrases/{paraphrase}', 'put'))->toBe([])
        ->and($headers('/admin/skip-words/{skipWord}', 'put'))->toBe([]);
});
