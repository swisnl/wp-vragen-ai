<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use VragenAI\ApiClient;
use VragenAI\Tests\TestCase;

class ApiClientCredentialsTest extends TestCase
{
    public function testFallsBackToStoredOption(): void
    {
        Functions\when('get_option')->justReturn([
            'customer' => 'acme',
            'token'    => 'stored-token',
        ]);

        $this->assertSame(
            ['customer' => 'acme', 'token' => 'stored-token'],
            ApiClient::credentials()
        );
    }

    public function testMissingOptionYieldsEmptyStrings(): void
    {
        Functions\when('get_option')->justReturn([]);

        $this->assertSame(
            ['customer' => '', 'token' => ''],
            ApiClient::credentials()
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstantsOverrideStoredOption(): void
    {
        define('VRAGENAI_CUSTOMER', 'const-customer');
        define('VRAGENAI_TOKEN', 'const-token');

        Functions\when('get_option')->justReturn([
            'customer' => 'db-customer',
            'token'    => 'db-token',
        ]);

        $this->assertSame(
            ['customer' => 'const-customer', 'token' => 'const-token'],
            ApiClient::credentials()
        );
    }
}
