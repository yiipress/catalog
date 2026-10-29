<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Catalog\Maintainers;

final class MaintainersTest extends TestCase
{
    public function testReadsUsersOfCodeowners(): void
    {
        $maintainers = Maintainers::fromCodeowners("# Catalog maintainers\n* @samdark\n/extensions/ @samdark @Other-One\n/site/ @yiipress/web\n");

        self::assertTrue($maintainers->includes('samdark'));
        self::assertTrue($maintainers->includes('other-one'));
        self::assertTrue($maintainers->includes('SamDark'));
        self::assertFalse($maintainers->includes('stranger'));
        self::assertFalse($maintainers->includes('yiipress/web'));
        self::assertFalse($maintainers->includes(''));
    }

    public function testCommentsDoNotCount(): void
    {
        self::assertFalse(Maintainers::fromCodeowners("# * @stranger\n* @samdark # @other\n")->includes('stranger'));
        self::assertFalse(Maintainers::fromCodeowners("* @samdark # @other\n")->includes('other'));
    }
}
