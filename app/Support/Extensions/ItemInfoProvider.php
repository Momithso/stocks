<?php

namespace App\Support\Extensions;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Notices of a module about items, e.g. "ausgegeben an … bis …" from the loan module. See
 * App\Support\Extensions\ItemExtensions::addInfo().
 */
interface ItemInfoProvider
{
    /**
     * Notices for the given items, by item id. Items without notice are left out.
     *
     * A notice: "text" (sentence for the item page and the scan card), "badge" (short text for
     * lists, null for none), "variant" (info, warning, danger), "href" (link to more, e.g. the
     * loan), "lock" (true: moving, splitting and retiring the item is not allowed while the notice
     * holds, the text says why) and "actions" (buttons on the scan card: title, href, icon).
     *
     * @param  Collection<int, Item>  $items
     * @param  User|null  $user  the viewer, null when only locks are asked for
     * @return array<int, list<array{text: string, badge: string|null, variant: string, href: string|null, lock: bool, actions: list<array{title: string, href: string, icon: string}>}>>
     */
    public function notices(Collection $items, ?User $user): array;

    /**
     * Bookings that count on the item later, as sentences (e.g. "B-2026-0042 ab 14.11., 16:00
     * Uhr."). Retiring, using up, splitting the item or setting a defect condition asks first.
     *
     * @return list<string>
     */
    public function reservations(Item $item): array;
}
