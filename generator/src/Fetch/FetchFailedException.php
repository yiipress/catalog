<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use RuntimeException;

/**
 * GitHub could not be read for an entry and the cache has nothing for it.
 */
final class FetchFailedException extends RuntimeException {}
