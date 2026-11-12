<?php

namespace App\Support\Extensions;

use App\Models\Item;

/**
 * A section of a module on the public page of a box (/s/{code}), e.g. the pack pin of the loan
 * module. See App\Support\Extensions\ItemExtensions::addPublicBoxSection().
 */
interface PublicBoxSection
{
    /**
     * Props of the Vue component for this box, null to leave the section out. Shown without
     * login: only what a guest may see.
     *
     * @return array<string, mixed>|null
     */
    public function props(Item $box): ?array;
}
