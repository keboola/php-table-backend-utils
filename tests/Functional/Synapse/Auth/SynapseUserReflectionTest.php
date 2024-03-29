<?php

declare(strict_types=1);

namespace Tests\Keboola\TableBackendUtils\Functional\Synapse\Auth;

use Doctrine\DBAL\Exception;
use Keboola\TableBackendUtils\Auth\SynapseUserReflection;

class SynapseUserReflectionTest extends BaseAuthTestCase
{
    private const LOGIN_PREFIX = 'UTILS_TEST_AUTH_LOGIN_';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpUser(self::LOGIN_PREFIX);
    }

    public function testEndAllUserSessions(): void
    {
        assert($this->currentLogin !== null);
        $ref = new SynapseUserReflection($this->connection, $this->currentLogin);
        $this->assertCount(0, $ref->getAllSessionIds());

        // connect as user
        $dbUser = $this->getTestLoginConnection();
        $dbUser->connect();

        assert($this->currentLogin !== null);
        $ref = new SynapseUserReflection($this->connection, $this->currentLogin);

        $this->assertGreaterThan(0, count($ref->getAllSessionIds()));

        $dbUser->fetchAllAssociative('SELECT * FROM sys.tables');

        $ref->endAllSessions();

        $this->expectException(Exception::class);
        $dbUser->fetchAllAssociative('SELECT * FROM sys.tables');
    }
}
