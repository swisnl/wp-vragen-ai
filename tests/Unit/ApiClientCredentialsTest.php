<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use VragenAI\ApiClient;
use VragenAI\Tests\TestCase;

class ApiClientCredentialsTest extends TestCase
{
    public function test_falls_back_to_stored_option(): void
    {
        Functions\when('get_option')->justReturn([
            'customer' => 'acme',
            'token' => 'stored-token',
        ]);

        $this->assertSame(
            ['customer' => 'acme', 'token' => 'stored-token'],
            ApiClient::credentials()
        );
    }

    public function test_missing_option_yields_empty_strings(): void
    {
        Functions\when('get_option')->justReturn([]);

        $this->assertSame(
            ['customer' => '', 'token' => ''],
            ApiClient::credentials()
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_constants_override_stored_option(): void
    {
        define('VRAGENAI_CUSTOMER', 'const-customer');
        define('VRAGENAI_TOKEN', 'const-token');

        Functions\when('get_option')->justReturn([
            'customer' => 'db-customer',
            'token' => 'db-token',
        ]);

        $this->assertSame(
            ['customer' => 'const-customer', 'token' => 'const-token'],
            ApiClient::credentials()
        );
    }
}
