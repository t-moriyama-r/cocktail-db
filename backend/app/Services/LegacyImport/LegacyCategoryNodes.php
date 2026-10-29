<?php

namespace App\Services\LegacyImport;

use App\Models\LegacyIdMap;
use UnexpectedValueException;

/**
 * 旧のカテゴリ・ミドルの ID から、カテゴリ木を移した(#29)先のノードの ID を引く。
 *
 * 旧の銘柄・材料の行はカテゴリとミドルの両方の列を持ち、ミドルの親とカテゴリが合わない行がある
 * (docs/migration-anomalies.md の 3・4)。#28 でミドルの親に合わせると決めたので、ミドルがあればカテゴリの列は見ない。
 */
final class LegacyCategoryNodes
{
    /** カテゴリ木のノードを書いた新しい表 */
    private const NODE_TABLE = 'categories';

    public const LEGACY_CATEGORY = 'm_category';

    public const LEGACY_MIDDLE = 'm_middle';

    /**
     * @param  array<string, array<int, int>>  $nodeIds  旧の表 => (旧 ID => ノードの ID)
     */
    private function __construct(private readonly array $nodeIds) {}

    /**
     * 旧の行のカテゴリ・ミドルの列から、移した先のノードを引く。ミドルが無い(null か 0)ときはカテゴリのノード。
     *
     * @param  array<mixed>  $row
     * @param  string  $context  見つからないときの例外に添える、どの行から引いたか
     *
     * @throws UnexpectedValueException 移した先のノードが無いとき
     */
    public function forRow(array $row, string $legacyTable, string $context): int
    {
        $middleId = LegacyRows::nullableId($row, 'middle_category', $legacyTable);

        return $middleId !== null
            ? $this->nodeId(self::LEGACY_MIDDLE, $middleId, $context)
            : $this->nodeId(self::LEGACY_CATEGORY, LegacyRows::int($row, 'category', $legacyTable), $context);
    }

    /**
     * カテゴリ木を移した後の legacy_id_maps から、旧のカテゴリ・ミドルとノードの対応を読む。
     */
    public static function load(): self
    {
        return new self([
            self::LEGACY_CATEGORY => LegacyIdMap::newIdsByLegacyId(self::LEGACY_CATEGORY, self::NODE_TABLE),
            self::LEGACY_MIDDLE => LegacyIdMap::newIdsByLegacyId(self::LEGACY_MIDDLE, self::NODE_TABLE),
        ]);
    }

    /**
     * 旧のカテゴリ・ミドルの ID から、移した先のノードの ID を引く。カテゴリ木をまだ移していなければ見つからない。
     *
     * @param  self::LEGACY_CATEGORY|self::LEGACY_MIDDLE  $legacyTable
     * @param  string  $context  見つからないときの例外に添える、どの行から引いたか
     *
     * @throws UnexpectedValueException 移した先のノードが無いとき
     */
    public function nodeId(string $legacyTable, int $legacyId, string $context): int
    {
        return $this->find($legacyTable, $legacyId)
            ?? throw new UnexpectedValueException("{$context} が指す {$legacyTable} の id {$legacyId} を移した先のノードが無い(カテゴリ木を先に移す)");
    }

    /**
     * 旧のカテゴリ・ミドルの ID から、移した先のノードの ID を引く。対応が無ければ null(旧で消えた行を指していたときなど)。
     *
     * @param  self::LEGACY_CATEGORY|self::LEGACY_MIDDLE  $legacyTable
     */
    public function find(string $legacyTable, int $legacyId): ?int
    {
        return $this->nodeIds[$legacyTable][$legacyId] ?? null;
    }
}
