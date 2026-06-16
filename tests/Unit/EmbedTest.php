<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use VragenAI\Embed;
use VragenAI\Tests\TestCase;

class EmbedTest extends TestCase
{
    public function test_block_is_not_registered_without_a_customer(): void
    {
        Functions\when('get_option')->justReturn([]); // no credentials → no customer
        Functions\expect('register_block_type')->never();
        Functions\expect('wp_register_script')->never();

        (new Embed)->registerBlock();
    }
}
