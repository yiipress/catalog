<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Entry;

/**
 * Listing state of a catalog entry. Approval is the merge of the entry file; there is no pending state.
 */
enum EntryStatus: string
{
    case Listed = 'listed';
    /** Still listed, with a banner: nobody maintains it. */
    case Abandoned = 'abandoned';
    /** A tombstone: not listed, not fetched, and the ID is never given to another package. */
    case Withdrawn = 'withdrawn';
}
