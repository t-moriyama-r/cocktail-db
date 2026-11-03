<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Models\CocktailIngredient;
use App\Models\Dictionary;
use App\Models\Method;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\RecipeSignature;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

// 管理 API のカクテルの削除と、保存のたびに材料の数(recipe_count)・材料の組のハッシュ(recipe_signature)を
// 作り直すこと(#130)。旧の DetailEditController@delete_cocktail と、旧の保存で材料の数を書いていたところの置き換え。
// 旧と変えたところは #130 の本文の「旧との差分」。
// 削除で画像のファイルも消すこと(#578)。旧は行を消すだけでファイルを残していた(#578 の本文の「旧との差分」)。
// 説明文から参照されているカクテルを 409 で消させないこと(#579。旧との差分は #579 の本文)。
// 木は CategoryTree::addNode だけで組み、閉包表に行を直接入れない(CLAUDE.md)。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * 洋酒(根)── ラム・ジン・ライム の木のノード。
 *
 * @return array<string, Category>
 */
function cocktailDeleteNodes(): array
{
    $tree = app(CategoryTree::class);
    $add = fn (?Category $parent, string $name): Category => $tree->addNode(
        $parent,
        Category::factory()->make(['name' => $name, 'name_search' => NameNormalizer::normalize($name), 'name_eng' => $name.'-eng'])->getAttributes(),
    );

    $root = $add(null, '洋酒');

    return ['ラム' => $add($root, 'ラム'), 'ジン' => $add($root, 'ジン'), 'ライム' => $add($root, 'ライム')];
}

/**
 * $cocktail に材料の行を、配列の順に position を 1 から振って入れる。
 *
 * @param  list<array{0: Category, 1: bool}>  $rows  (ノード, 任意か)
 */
function cocktailDeleteIngredients(Cocktail $cocktail, array $rows): void
{
    foreach ($rows as $i => [$node, $optional]) {
        CocktailIngredient::factory()->create([
            'cocktail_id' => $cocktail->id, 'category_id' => $node->id, 'position' => $i + 1, 'amount' => '30ml', 'is_optional' => $optional,
        ]);
    }
}

it('TC-130-01: ログインしていないと、削除は 401 で、カクテルは残る', function () {
    auth()->logout();
    $cocktail = Cocktail::factory()->create();

    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    expect(Cocktail::query()->whereKey($cocktail->id)->exists())->toBeTrue();
});

it('TC-130-02: 削除は 204 で、カクテルと、その材料・別名を消す(公開・下書きとも)', function (bool $isDraft) {
    $n = cocktailDeleteNodes();
    $cocktail = Cocktail::factory()->create(['is_draft' => $isDraft]);
    cocktailDeleteIngredients($cocktail, [[$n['ラム'], false], [$n['ライム'], false]]);
    CocktailAlias::factory()->create(['cocktail_id' => $cocktail->id]);

    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")->assertNoContent();

    expect(Cocktail::query()->whereKey($cocktail->id)->exists())->toBeFalse()
        ->and(CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->count())->toBe(0)
        ->and(CocktailAlias::query()->where('cocktail_id', $cocktail->id)->count())->toBe(0);
})->with([
    '公開' => [false],
    '下書き' => [true],
]);

it('TC-130-03: 削除は、ほかのカクテルとその材料・別名を残す', function () {
    $n = cocktailDeleteNodes();
    [$target, $other] = Cocktail::factory()->count(2)->create()->all();
    cocktailDeleteIngredients($target, [[$n['ラム'], false], [$n['ライム'], false]]);
    cocktailDeleteIngredients($other, [[$n['ジン'], false], [$n['ライム'], false]]);
    CocktailAlias::factory()->create(['cocktail_id' => $other->id, 'name' => '別名']);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")->assertNoContent();

    expect(Cocktail::query()->pluck('id')->all())->toBe([$other->id])
        ->and(CocktailIngredient::query()->where('cocktail_id', $other->id)->orderBy('position')->pluck('category_id')->all())
        ->toBe([$n['ジン']->id, $n['ライム']->id])
        ->and(CocktailAlias::query()->where('cocktail_id', $other->id)->pluck('name')->all())->toBe(['別名']);
});

it('TC-130-04: 無い ID・消した後のもう一度の削除は 404', function () {
    $cocktail = Cocktail::factory()->create();

    $this->deleteJson('/api/admin/cocktails/'.($cocktail->id + 1000))->assertNotFound();
    expect(Cocktail::query()->whereKey($cocktail->id)->exists())->toBeTrue();

    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")->assertNoContent();
    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")->assertNotFound();
});

it('TC-130-05: 消したカクテルは、公開の詳細の API で 404 になる', function () {
    $cocktail = Cocktail::factory()->create(['is_draft' => false]);
    $this->getJson("/api/cocktails/{$cocktail->id}")->assertOk();

    $this->deleteJson("/api/admin/cocktails/{$cocktail->id}")->assertNoContent();

    $this->getJson("/api/cocktails/{$cocktail->id}")->assertNotFound();
});

it('TC-130-06: 更新の保存のたびに、保存した材料の行から材料の数と材料の組のハッシュを作り直す', function () {
    $n = cocktailDeleteNodes();
    $method = Method::factory()->create();
    // 同じ材料の組の、公開しているカクテル。保存した後に同じレシピとして引けるかで、ハッシュが作り直されたことを見る
    $twin = Cocktail::factory()->create(['is_draft' => false]);
    cocktailDeleteIngredients($twin, [[$n['ラム'], false], [$n['ライム'], false], [$n['ジン'], true]]);
    $cocktail = Cocktail::factory()->create(['method_id' => $method->id]);
    cocktailDeleteIngredients($cocktail, [[$n['ラム'], false], [$n['ライム'], false], [$n['ジン'], true]]);
    app(RecipeSignature::class)->rewrite([$twin->id]);
    // 列の値を、材料の行と合わない古い値にしておく
    Cocktail::query()->whereKey($cocktail->id)->toBase()->update(['recipe_count' => 9, 'recipe_signature' => str_repeat('0', 64)]);

    $this->putJson("/api/admin/cocktails/{$cocktail->id}", [
        'base_category_id' => $n['ラム']->id, 'name' => 'ダイキリ', 'name_eng' => 'Daiquiri', 'method_id' => $method->id,
        'description' => '説明', 'is_major' => false, 'is_draft' => false,
        'ingredients' => [
            ['category_id' => $n['ラム']->id, 'brand_id' => null, 'amount' => '30ml', 'is_optional' => false],
            ['category_id' => $n['ライム']->id, 'brand_id' => null, 'amount' => '30ml', 'is_optional' => false],
            ['category_id' => $n['ジン']->id, 'brand_id' => null, 'amount' => '30ml', 'is_optional' => true],
        ],
    ])->assertOk();

    $cocktail->refresh();
    expect($cocktail->recipe_count)->toBe(2)
        ->and($cocktail->sameRecipeCocktails()->pluck('id')->all())->toBe([$twin->id]);
});

/** public ディスクに画像のファイルを置く。中身は見ないので、名前だけで足りる */
function cocktailDeletePutImages(string ...$names): void
{
    foreach ($names as $name) {
        Storage::disk('public')->put($name, 'image');
    }
}

it('TC-578-01: 削除すると、そのカクテルの画像のファイルも消え、ほかのファイルは残る', function () {
    Storage::fake('public');
    cocktailDeletePutImages('target.png', 'other.png');
    $target = Cocktail::factory()->create(['image' => 'target.png']);
    $other = Cocktail::factory()->create(['image' => 'other.png']);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")->assertNoContent();

    expect(Cocktail::query()->pluck('id')->all())->toBe([$other->id]);
    Storage::disk('public')->assertMissing('target.png');
    Storage::disk('public')->assertExists('other.png');
});

it('TC-578-02: ほかのカクテルが同じファイルを指しているときは、ファイルを残す', function () {
    Storage::fake('public');
    cocktailDeletePutImages('shared.png');
    $target = Cocktail::factory()->create(['image' => 'shared.png']);
    $other = Cocktail::factory()->create(['image' => 'shared.png']);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")->assertNoContent();

    expect(Cocktail::query()->pluck('id')->all())->toBe([$other->id]);
    Storage::disk('public')->assertExists('shared.png');

    // 最後に指していた行を消すと、ファイルも消える
    $this->deleteJson("/api/admin/cocktails/{$other->id}")->assertNoContent();
    Storage::disk('public')->assertMissing('shared.png');
});

it('TC-578-03: ノード・銘柄・用語が同じファイルを指しているときは、ファイルを残す', function (Closure $share) {
    Storage::fake('public');
    cocktailDeletePutImages('shared.png');
    $share(cocktailDeleteNodes()['ラム'], 'shared.png');
    $target = Cocktail::factory()->create(['image' => 'shared.png']);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")->assertNoContent();

    expect(Cocktail::query()->whereKey($target->id)->exists())->toBeFalse();
    Storage::disk('public')->assertExists('shared.png');
})->with([
    'ノード' => [fn (Category $node, string $image) => $node->forceFill(['image' => $image])->save()],
    '銘柄' => [fn (Category $node, string $image) => Brand::factory()->create(['category_id' => $node->id, 'image' => $image])],
    '用語' => [fn (Category $node, string $image) => Dictionary::factory()->create(['image' => $image])],
]);

it('TC-578-04: 画像の列がサーバーで付けた名前の形でない(外部の URL・パスを含む)ときは、ディスクのファイルを消さない', function (string $image, string $file) {
    Storage::fake('public');
    cocktailDeletePutImages($file);
    $target = Cocktail::factory()->create(['image' => $image]);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")->assertNoContent();

    expect(Cocktail::query()->whereKey($target->id)->exists())->toBeFalse();
    Storage::disk('public')->assertExists($file);
})->with([
    '外部の URL' => ['https://example.com/a.png', 'a.png'],
    'パスを含む値' => ['dir/a.png', 'dir/a.png'],
]);

it('TC-578-05: 画像の無いカクテルも 204 で消え、ディスクのファイルは残る', function () {
    Storage::fake('public');
    cocktailDeletePutImages('other.png');
    $target = Cocktail::factory()->create(['image' => null]);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")->assertNoContent();

    expect(Cocktail::query()->whereKey($target->id)->exists())->toBeFalse();
    Storage::disk('public')->assertExists('other.png');
});

it('TC-578-06: 無い ID の削除は 404 で、画像のファイルは残る', function () {
    Storage::fake('public');
    cocktailDeletePutImages('target.png');
    $target = Cocktail::factory()->create(['image' => 'target.png']);

    $this->deleteJson('/api/admin/cocktails/'.($target->id + 1000))->assertNotFound();

    expect(Cocktail::query()->whereKey($target->id)->exists())->toBeTrue();
    Storage::disk('public')->assertExists('target.png');
});

/**
 * 説明文から参照される側のカクテル(材料・別名つき)。409 のときに、材料・別名も残ることを見る。
 */
function cocktailDeleteReferenced(): Cocktail
{
    $n = cocktailDeleteNodes();
    $cocktail = Cocktail::factory()->create(['name' => 'ダイキリ']);
    cocktailDeleteIngredients($cocktail, [[$n['ラム'], false], [$n['ライム'], false]]);
    CocktailAlias::factory()->create(['cocktail_id' => $cocktail->id]);

    return $cocktail;
}

/**
 * $cocktail と、その材料・別名が残っているか。
 */
function cocktailDeleteRemains(Cocktail $cocktail): void
{
    expect(Cocktail::query()->whereKey($cocktail->id)->exists())->toBeTrue()
        ->and(CocktailIngredient::query()->where('cocktail_id', $cocktail->id)->count())->toBe(2)
        ->and(CocktailAlias::query()->where('cocktail_id', $cocktail->id)->count())->toBe(1);
}

it('TC-579-01: ほかのカクテルの説明・補足から参照されているカクテルは消せず、409 で理由と参照しているカクテルを返す', function (string $column, string $format, bool $draft) {
    $target = cocktailDeleteReferenced();
    $referrer = Cocktail::factory()->create([
        'name' => 'フローズン・ダイキリ',
        'is_draft' => $draft,
        $column => sprintf($format, $target->id),
    ]);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")
        ->assertConflict()
        ->assertExactJson(['message' => 'このカクテルは消せません', 'reasons' => [
            ['code' => 'cocktail', 'message' => 'ほかのカクテルの説明から参照されています', 'referrers' => [
                ['id' => $referrer->id, 'name' => 'フローズン・ダイキリ'],
            ]],
        ]]);

    cocktailDeleteRemains($target);
})->with([
    '説明' => ['description', '@c%d@を凍らせたもの', false],
    '補足' => ['note', '元は@c%d@', false],
    '大文字の記法' => ['description', '@C%d@を凍らせたもの', false],
    '下書きのカクテルから' => ['description', '@c%d@を凍らせたもの', true],
]);

it('TC-579-02: ノード・技法・銘柄・用語の説明から参照されているカクテルは消せず、409 で理由と参照している行を返す', function (string $kind, string $code, string $message) {
    $target = cocktailDeleteReferenced();
    $description = "定番は@c{$target->id}@";
    $tree = app(CategoryTree::class);
    $referrer = match ($kind) {
        'node' => $tree->addNode(null, Category::factory()->make(['name' => 'ラム酒', 'name_search' => 'ラム酒', 'description' => $description])->getAttributes()),
        'method' => Method::factory()->create(['name' => 'ラム酒', 'description' => $description]),
        'brand' => Brand::factory()->create([
            'category_id' => $tree->addNode(null, Category::factory()->make(['name' => '洋酒2', 'name_search' => '洋酒2'])->getAttributes())->id,
            'name' => 'ラム酒',
            'description' => $description,
        ]),
        'dictionary' => Dictionary::factory()->create(['name' => 'ラム酒', 'description' => $description]),
        default => throw new LogicException("知らない種類: {$kind}"),
    };

    $this->deleteJson("/api/admin/cocktails/{$target->id}")
        ->assertConflict()
        ->assertExactJson(['message' => 'このカクテルは消せません', 'reasons' => [
            ['code' => $code, 'message' => $message, 'referrers' => [['id' => $referrer->id, 'name' => 'ラム酒']]],
        ]]);

    cocktailDeleteRemains($target);
})->with([
    'ノード' => ['node', 'node', 'ノードの説明から参照されています'],
    '技法' => ['method', 'method', '技法の説明から参照されています'],
    '銘柄' => ['brand', 'brand', '銘柄の説明から参照されています'],
    '用語' => ['dictionary', 'dictionary', '用語の説明から参照されています'],
]);

it('TC-579-03: いくつもの種類・行から参照されているときは、理由を cocktail・node・method・brand・dictionary の順に、行を ID の順にすべて返す', function () {
    $target = cocktailDeleteReferenced();
    $ref = "@c{$target->id}@";
    $second = Cocktail::factory()->create(['name' => 'B', 'note' => $ref]);
    $first = Cocktail::factory()->create(['name' => 'A', 'description' => $ref]);
    $term = Dictionary::factory()->create(['name' => '用語', 'description' => $ref]);
    $method = Method::factory()->create(['name' => '技法', 'description' => $ref]);
    // 名前の順ではなく ID の順に並ぶことを見るため、B を先に作る
    expect($first->id)->toBeGreaterThan($second->id);

    $this->deleteJson("/api/admin/cocktails/{$target->id}")
        ->assertConflict()
        ->assertExactJson(['message' => 'このカクテルは消せません', 'reasons' => [
            ['code' => 'cocktail', 'message' => 'ほかのカクテルの説明から参照されています', 'referrers' => [
                ['id' => $second->id, 'name' => 'B'],
                ['id' => $first->id, 'name' => 'A'],
            ]],
            ['code' => 'method', 'message' => '技法の説明から参照されています', 'referrers' => [['id' => $method->id, 'name' => '技法']]],
            ['code' => 'dictionary', 'message' => '用語の説明から参照されています', 'referrers' => [['id' => $term->id, 'name' => '用語']]],
        ]]);

    cocktailDeleteRemains($target);
});

it('TC-579-04: 説明文から参照されていないカクテルは 204 で消せる(自分の説明・補足からの参照と、参照にならない似た文字は数えない)', function (string $column, string $format) {
    $target = cocktailDeleteReferenced();
    $other = Cocktail::factory()->create(['name' => 'モヒート']);
    if ($column === 'self') {
        $target->update(['description' => sprintf($format, $target->id), 'note' => sprintf($format, $target->id)]);
    } else {
        Cocktail::factory()->create([$column => sprintf($format, $target->id, $other->id)]);
    }

    $this->deleteJson("/api/admin/cocktails/{$target->id}")->assertNoContent();

    expect(Cocktail::query()->whereKey($target->id)->exists())->toBeFalse()
        ->and(CocktailIngredient::query()->where('cocktail_id', $target->id)->count())->toBe(0)
        ->and(CocktailAlias::query()->where('cocktail_id', $target->id)->count())->toBe(0);
})->with([
    '参照が無い' => ['description', 'ラムベースの定番'],
    '自分の説明・補足からの参照だけ' => ['self', '@c%d@の作り方'],
    'ほかのカクテルへの参照' => ['description', '@c%2$d@に近い'],
    'ID の桁が違う' => ['description', '@c%d0@に近い'],
    'ほかの種類の記法' => ['description', '@n%d@・@b%1$d@・@d%1$d@'],
    '前の記法と @ を分け合い、参照にならない' => ['description', '@c%2$d@c%1$d@'],
]);
