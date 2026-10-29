<?php

namespace App\Services;

use App\Enums\CategoryRole;
use App\Models\Category;

/**
 * CategoryTree::forest() が返す木のノード 1 つ。ノードと、その根の役割と、子(兄弟の並び順)を持つ。
 */
final readonly class CategoryTreeNode
{
    /**
     * @param  CategoryRole|null  $rootRole  ノードの根の役割(CategoryTree::rootRole と同じ値)
     * @param  list<CategoryTreeNode>  $children  すぐ下の子。兄弟の並び順
     */
    public function __construct(
        public Category $category,
        public ?CategoryRole $rootRole,
        public array $children,
    ) {}
}
