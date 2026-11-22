<?php

use App\Models\LegacyIdMap;
use App\Services\LegacyImport\LegacyCategoryNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;

// LegacyCategoryNodes(#489)が、旧の行のカテゴリ・ミドルの列から移した先のノードを引くかを、legacy_id_maps を入れた
// MariaDB で確かめる。列の名前が表ごとに違う(銘柄・材料は category / middle_category、アフィリエイトは
// category_id / middle_id)ので、列の名前を渡して引けるかも確かめる。

uses(RefreshDatabase::class)->group('db');

beforeEach(function () {
    LegacyIdMap::factory()->create(['legacy_table' => 'm_category', 'legacy_id' => 2, 'new_table' => 'categories', 'new_id' => 20]);
    LegacyIdMap::factory()->create(['legacy_table' => 'm_middle', 'legacy_id' => 3, 'new_table' => 'categories', 'new_id' => 30]);
    // カテゴリ木のノードでない対応(銘柄)は引かない
    LegacyIdMap::factory()->create(['legacy_table' => 'm_category', 'legacy_id' => 4, 'new_table' => 'brands', 'new_id' => 40]);
});

it('TC-489-01: 既定の列(category / middle_category)で、ミドルがあればミドルのノードを引く', function () {
    expect(LegacyCategoryNodes::load()->forRow(['category' => 2, 'middle_category' => 3], 't_recipies', '行'))->toBe(30);
});

it('TC-489-02: 既定の列で、ミドルが無い(0 か null の)ときはカテゴリのノードを引く', function (mixed $middle) {
    expect(LegacyCategoryNodes::load()->forRow(['category' => 2, 'middle_category' => $middle], 'm_brand', '行'))->toBe(20);
})->with(['0' => [0], 'null' => [null]]);

it('TC-489-03: 列の名前を渡すと、その列のミドルからノードを引く', function () {
    expect(LegacyCategoryNodes::load()->forRow(['category_id' => 2, 'middle_id' => 3], 'affiliates', '行', 'category_id', 'middle_id'))->toBe(30);
});

it('TC-489-04: 列の名前を渡すと、ミドルが 0 のときはその列のカテゴリからノードを引く', function () {
    expect(LegacyCategoryNodes::load()->forRow(['category_id' => 2, 'middle_id' => 0], 'affiliates', '行', 'category_id', 'middle_id'))->toBe(20);
});

it('TC-489-05: 移した先のノードが無いときは、どの行か・どの旧の行を指したかを添えて止める', function (array $row, string $message) {
    expect(fn () => LegacyCategoryNodes::load()->forRow($row, 'affiliates', 'affiliates の(例)の行', 'category_id', 'middle_id'))
        ->toThrow(UnexpectedValueException::class, $message);
})->with([
    'ミドル' => [['category_id' => 2, 'middle_id' => 9], 'affiliates の(例)の行 が指す m_middle の id 9 を移した先のノードが無い(カテゴリ木を先に移す)'],
    'カテゴリの対応が銘柄だけ' => [['category_id' => 4, 'middle_id' => 0], 'affiliates の(例)の行 が指す m_category の id 4 を移した先のノードが無い(カテゴリ木を先に移す)'],
]);
