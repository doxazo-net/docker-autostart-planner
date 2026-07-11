<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpunitRuns(): void
    {
        $this->expectNotToPerformAssertions();
    }
}
