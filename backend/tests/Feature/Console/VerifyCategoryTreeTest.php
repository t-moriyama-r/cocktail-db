<?php

use App\Models\Category;
use App\Models\CategoryClosure;
use App\Services\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

// tree:verify(カテゴリ木の閉包表が木として正しいか)を、MariaDB の閉包表で確かめる。
// 正しい木は CategoryTree だけで組む。壊れた木は CategoryTree では作れない(作れないようにしてある)ので、
// 組んだ後で、下の breakClosure* だけが閉包表を直接書き換えて壊す。壊すことがテストの目的で、アプリのコードの
// 書き換えの決まり(CLAUDE.md の「カテゴリ木」)を外れるのはここだけ。

uses(RefreshDatabase::class)->group('db');

/**
 * 根から 1 本につながる鎖を作る。返すのは根から順のノード($depth + 1 個)。
 *
 * @return list<Category>
 */
function verifyTreeChain(int $depth, ?Category $root = null): array
{
    $tree = app(CategoryTree::class);
    $nodes = [$root ?? $tree->addNode(null, verifyTreeAttrs())];
    for ($i = 1; $i <= $depth; $i++) {
        $nodes[] = $tree->addNode($nodes[$i - 1], verifyTreeAttrs());
    }

    return $nodes;
}

/**
 * addNode に渡す、保存していないノードの属性。
 *
 * @return array<string, mixed>
 */
function verifyTreeAttrs(): array
{
    return Category::factory()->make()->getAttributes();
}

/**
 * tree:verify を、出力と終了コードを確かめられる形で呼ぶ。
 */
function verifyTree(TestCase $test): PendingCommand
{
    $command = $test->artisan('tree:verify');

    return $command instanceof PendingCommand
        ? $command
        : throw new RuntimeException('コンソールの出力を差し替えずにコマンドが動いた');
}

/**
 * tree:verify を流し、終了コードと出力を返す。一覧の中身を部分一致で確かめるときに使う。
 *
 * @return array{int, string}
 */
function runVerifyTree(): array
{
    $code = Artisan::call('tree:verify');

    return [$code, Artisan::output()];
}

/**
 * 閉包表のすべての行を [祖先の ID, 子孫の ID, depth] で返す。
 *
 * @return list<array{int, int, int}>
 */
function verifyTreeClosureRows(): array
{
    return array_values(CategoryClosure::query()
        ->orderBy('ancestor_id')
        ->orderBy('descendant_id')
        ->get()
        ->map(fn (CategoryClosure $row): array => [$row->ancestor_id, $row->descendant_id, $row->depth])
        ->all());
}

/**
 * 壊すため: 閉包表の行を 1 行消す。
 */
function breakClosureDelete(Category $ancestor, Category $descendant): void
{
    $deleted = DB::table('category_closures')
        ->where('ancestor_id', $ancestor->id)
        ->where('descendant_id', $descendant->id)
        ->delete();
    expect($deleted)->toBe(1);
}

/**
 * 壊すため: 閉包表に行を 1 行足す。
 */
function breakClosureInsert(Category $ancestor, Category $descendant, int $depth): void
{
    DB::table('category_closures')->insert([
        'ancestor_id' => $ancestor->id,
        'descendant_id' => $descendant->id,
        'depth' => $depth,
    ]);
}

/**
 * 壊すため: 閉包表の行の depth を書き換える。
 */
function breakClosureDepth(Category $ancestor, Category $descendant, int $depth): void
{
    $updated = DB::table('category_closures')
        ->where('ancestor_id', $ancestor->id)
        ->where('descendant_id', $descendant->id)
        ->update(['depth' => $depth]);
    expect($updated)->toBe(1);
}

it('TC-25-01: ノードの無い木では、問題なしで 0 で終わる', function () {
    verifyTree($this)
        ->expectsOutputToContain('問題なし')
        ->assertExitCode(0);
});

it('TC-25-02: 6 段の鎖・枝分かれ・複数の根の木では、問題なしで 0 で終わる', function () {
    $tree = app(CategoryTree::class);
    $chain = verifyTreeChain(5);
    $tree->addNode($chain[2], verifyTreeAttrs());
    $tree->addNode($chain[4], verifyTreeAttrs());
    verifyTreeChain(2);
    $tree->addNode(null, verifyTreeAttrs());

    verifyTree($this)
        ->expectsOutputToContain('問題なし')
        ->assertExitCode(0);
});

it('TC-25-03: moveSubtree で部分木を別の根の下へ・根へ移した後の木では、0 で終わる', function () {
    $tree = app(CategoryTree::class);
    $a = verifyTreeChain(4);
    $b = verifyTreeChain(1);
    $tree->moveSubtree($a[2], $b[1]);
    $tree->moveSubtree($a[1], null);

    verifyTree($this)->assertExitCode(0);
});

it('TC-25-04: ノードの自分の行(depth 0)を 1 行消すと、そのノードを挙げて 0 以外で終わる', function () {
    $chain = verifyTreeChain(2);
    breakClosureDelete($chain[1], $chain[1]);

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('自分の行')
        ->and($output)->toContain("#{$chain[1]->id} ");
});

it('TC-25-05: 閉包表に行の無いノード(CategoryTree を通さずに作った)も、自分の行が無いと挙げる', function () {
    verifyTreeChain(1);
    $orphan = Category::factory()->create();

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('自分の行')
        ->and($output)->toContain("#{$orphan->id} ");
});

it('TC-25-06: 深さ 2 のノードの親の行(depth 1)を 1 行消すと、親が無いと挙げて 0 以外で終わる', function () {
    $chain = verifyTreeChain(3);
    breakClosureDelete($chain[1], $chain[2]);

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('親(depth 1 の行)')
        ->and($output)->toContain("#{$chain[2]->id} ");
});

it('TC-25-07: 子のあるノードの親の行を消して根に化けさせると、子の祖先の行が食い違うと挙げる', function () {
    $chain = verifyTreeChain(2);
    breakClosureDelete($chain[0], $chain[1]);

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('祖先の行')
        ->and($output)->toContain("#{$chain[2]->id} ")
        ->and($output)->toContain("余分な行 #{$chain[0]->id}");
});

it('TC-25-08: 祖先の行(depth 2)を 1 行消すと、祖先の行が足りないと挙げて 0 以外で終わる', function () {
    $chain = verifyTreeChain(3);
    breakClosureDelete($chain[0], $chain[2]);

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('祖先の行')
        ->and($output)->toContain("足りない行 #{$chain[0]->id}");
});

it('TC-25-09: 親の行(depth 1)が 2 つあるノードは、親が 2 つあると挙げて 0 以外で終わる', function () {
    $chain = verifyTreeChain(1);
    $other = verifyTreeChain(0)[0];
    breakClosureInsert($other, $chain[1], 1);

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('親(depth 1 の行)')
        ->and($output)->toContain('2 つある');
});

it('TC-25-10: 祖先の行の depth が親をたどった段数と違うと、祖先の行が食い違うと挙げる', function () {
    $chain = verifyTreeChain(3);
    breakClosureDepth($chain[0], $chain[3], 2);

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('祖先の行')
        ->and($output)->toContain("#{$chain[3]->id} ")
        ->and($output)->toContain('depth が違う');
});

it('TC-25-11: 親どうしが輪になったノードは、循環と挙げて 0 以外で終わる(止まらずに終わる)', function () {
    $tree = app(CategoryTree::class);
    $a = $tree->addNode(null, verifyTreeAttrs());
    $b = $tree->addNode($a, verifyTreeAttrs());
    breakClosureInsert($b, $a, 1);

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('循環')
        ->and($output)->toContain("#{$a->id} ")
        ->and($output)->toContain("#{$b->id} ");
});

it('TC-25-12: 破れが複数あると、すべてを一覧に出す', function () {
    $chain = verifyTreeChain(3);
    $other = verifyTreeChain(1);
    breakClosureDelete($chain[3], $chain[3]);
    breakClosureDelete($chain[0], $chain[2]);
    breakClosureInsert($chain[0], $other[1], 1);

    [$code, $output] = runVerifyTree();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('自分の行')
        ->and($output)->toContain('祖先の行')
        ->and($output)->toContain('2 つある');
});

it('TC-25-13: 深さ 1 の葉の親の行を消すと、別の根と見分けられないので 0 で終わる(検出しない境界)', function () {
    $chain = verifyTreeChain(1);
    breakClosureDelete($chain[0], $chain[1]);

    verifyTree($this)->assertExitCode(0);
});

it('TC-25-14: 壊れた木でも、コマンドは閉包表を書き換えない', function () {
    $chain = verifyTreeChain(3);
    breakClosureDelete($chain[0], $chain[2]);
    $before = verifyTreeClosureRows();

    runVerifyTree();

    expect(verifyTreeClosureRows())->toBe($before);
});
