<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Unit\Auth\Grant\Synapse;

use Keboola\TableBackendUtils\Auth\Grant\Synapse\RevokeOptions;
use PHPUnit\Framework\TestCase;

class RevokeOptionsTest extends TestCase
{
    public function testOptions(): void
    {
        $optionsDefault = new RevokeOptions();
        $this->assertFalse($optionsDefault->isAllowGrantOption());
        $this->assertFalse($optionsDefault->isCascade());

        $options = new RevokeOptions(
            RevokeOptions::OPTION_DONT_REVOKE_CASCADE,
            RevokeOptions::OPTION_REVOKE_CASCADE
        );
        $this->assertTrue($options->isCascade());
    }

    public function testFailAllowGrantOption(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Revoking grant option is not supported on Synapse.');
        new RevokeOptions(RevokeOptions::OPTION_REVOKE_CASCADE);
    }
}