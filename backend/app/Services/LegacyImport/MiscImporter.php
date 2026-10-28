<?php

namespace App\Services\LegacyImport;

use Illuminate\Support\Facades\DB;

/**
 * 技法・氷・用語辞典(と分類)・言い換え・スキップ語・ユーザーを、旧 DB から新しい DB に移す(#33)。
 *
 * 技法・氷・用語辞典・分類・ユーザーは、カクテル(method_id・ice_id・glass_dictionary_id)などが旧 ID のまま
 * 指すので ID を変えずに入れ、legacy_id_maps にも同じ値の対応を入れる(docs/schema.md の ID の方針)。
 * 言い換え・スキップ語は旧に ID が無いので、一意な name で同じ行を見分ける。
 *
 * 2 回目は消して入れ直さずに上書きする。カクテルを移した後は、外部キー(RESTRICT)で技法などを消せないため。
 * Eloquent を通さずに書くのは、User の password の hashed のキャストが旧のハッシュに手を加えないようにするため。
 */
final class MiscImporter implements LegacyImporter
{
    public function import(): array
    {
        $tables = [];

        DB::transaction(function () use (&$tables) {
            $tables[] = $this->copyKeepingIds('m_method', 'methods', fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'name_eng' => $row['name_eng'],
                'description' => $row['description'],
            ]);
            $tables[] = $this->copyKeepingIds('m_ice', 'ices', fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'name_eng' => $row['name_eng'],
            ]);
            // 用語辞典が分類を外部キーで指すので、分類を先に入れる
            $tables[] = $this->copyKeepingIds('m_dictionary_category', 'dictionary_categories', fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'name_eng' => $row['name_eng'],
            ]);
            $tables[] = $this->copyKeepingIds('t_dictionary', 'dictionaries', fn (array $row) => [
                'id' => $row['id'],
                'dictionary_category_id' => $row['category'],
                'name' => $row['name'],
                'name_eng' => $row['name_eng'],
                'name_search' => $row['name_search'],
                'description' => $row['description'],
                'youtube' => $row['youtube'],
                'youtube_start_seconds' => YoutubeStartSeconds::fromLegacy($row['y_hour'], $row['y_minute'], $row['y_second']),
                'image' => $row['image'],
                'image_txt' => $row['image_txt'],
                'image_url' => $row['image_url'],
            ]);
            $this->linkMethodsToDictionaries();
            $tables[] = $this->copyByName('paraphrases', 'paraphrases', fn (array $row) => [
                'name' => $row['name'],
                'converted' => $row['converted'],
                'middle' => $row['middle'],
                'note' => $row['note'],
                'command' => $row['command'],
            ]);
            $tables[] = $this->copyByName('skip_db', 'skip_words', fn (array $row) => [
                'name' => $row['name'],
            ]);
            // twitter_id は移さない(X でのログインはやめた。docs/schema.md の「users」)
            $tables[] = $this->copyKeepingIds('users', 'users', fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'password' => $row['password'],
                'remember_token' => $row['remember_token'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        });

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  callable(array<mixed>): array<string, mixed>  $toNewRow
     * @return string 書いた新しい表
     */
    private function copyKeepingIds(string $legacyTable, string $newTable, callable $toNewRow): string
    {
        KeptIdCopier::copy($legacyTable, $newTable, $toNewRow);

        return $newTable;
    }

    /**
     * 技法に、その技法を説明する用語(methods.dictionary_id)を入れる。
     *
     * 旧は対応を持たず、技法の ID と用語の ID がたまたま同じ(1〜6)ことに頼って用語へリンクしていた。旧の本番の
     * データでは技法と同じ名前の用語が 1 つずつあるので、ID ではなく名前の一致で結び付ける。同じ名前の用語が
     * 無ければ null、2 つ以上あれば ID の小さい方にする。
     */
    private function linkMethodsToDictionaries(): void
    {
        DB::table('methods')->update([
            'dictionary_id' => DB::raw('(SELECT MIN(dictionaries.id) FROM dictionaries WHERE dictionaries.name = methods.name)'),
        ]);
    }

    /**
     * @param  callable(array<mixed>): array<string, mixed>  $toNewRow
     * @return string 書いた新しい表
     */
    private function copyByName(string $legacyTable, string $newTable, callable $toNewRow): string
    {
        KeptIdCopier::upsert($newTable, $this->legacyRows($legacyTable, 'name', $toNewRow), 'name');

        return $newTable;
    }

    /**
     * @param  callable(array<mixed>): array<string, mixed>  $toNewRow
     * @return list<array<string, mixed>>
     */
    private function legacyRows(string $table, string $orderBy, callable $toNewRow): array
    {
        return array_map($toNewRow(...), LegacyRows::ordered($table, $orderBy));
    }
}
