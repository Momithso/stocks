<?php

namespace Modules\Loans\Support;

use Illuminate\Support\Collection;
use Modules\Loans\Models\LoanItem;

/**
 * Rows of a loan in the order of the material list: every position followed by the contents of its
 * box, with their depth. Used by the pages of issue, return and inspection.
 */
class RowTree
{
    /**
     * @param  Collection<int, LoanItem>  $rows  rows of one loan (all or a part of them)
     * @param  callable(LoanItem): array<string, mixed>  $extra  data of the page per row
     * @return list<array<string, mixed>>
     */
    public static function flatten(Collection $rows, callable $extra): array
    {
        $byParent = $rows->groupBy(fn (LoanItem $row) => $row->parent_id ?? 0);
        $ids = $rows->modelKeys();
        // rows whose parent is not part of the list start a branch of their own
        $roots = $rows->filter(fn (LoanItem $row) => $row->parent_id === null || ! in_array($row->parent_id, $ids, true));

        $flat = [];
        $walk = function (LoanItem $row, int $depth) use (&$walk, &$flat, $byParent, $extra) {
            $item = $row->physicalItem();
            $flat[] = [
                'id' => $row->id,
                'parent_id' => $row->parent_id,
                'depth' => $depth,
                'number' => $item->number,
                'name' => $row->item->name,
                'kind' => $row->item->kind->value,
                'unit' => $row->item->unit,
                'is_container' => $row->item->is_container,
                'quantity' => $row->quantity,
                ...$extra($row),
            ];
            foreach ($byParent->get($row->id, collect()) as $child) {
                $walk($child, $depth + 1);
            }
        };
        foreach ($roots as $root) {
            $walk($root, 0);
        }

        return $flat;
    }
}
