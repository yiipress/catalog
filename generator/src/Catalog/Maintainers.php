<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Catalog;

use function explode;
use function preg_match_all;
use function strtolower;

/**
 * GitHub users named in `CODEOWNERS`: the only people who may set maintainer-only fields.
 *
 * Teams (`@org/team`) are ignored: a check without an API token cannot read their members.
 */
final readonly class Maintainers
{
    public const string FILE = '.github/CODEOWNERS';

    /**
     * @param array<string, true> $logins Lowercase logins.
     */
    private function __construct(private array $logins) {}

    public static function fromCodeowners(string $content): self
    {
        $logins = [];
        foreach (explode("\n", $content) as $line) {
            $line = explode('#', $line, 2)[0];
            preg_match_all('~(?<=\s)@([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))(?![A-Za-z0-9/-])~', ' ' . $line, $matches);
            foreach ($matches[1] as $login) {
                $logins[strtolower($login)] = true;
            }
        }

        return new self($logins);
    }

    public function includes(string $login): bool
    {
        return isset($this->logins[strtolower($login)]);
    }
}
