<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use VragenAI\LanguageResolver;
use VragenAI\Tests\TestCase;

class LanguageResolverTest extends TestCase
{
    public function test_falls_back_to_site_locale_without_plugins(): void
    {
        Functions\when('get_locale')->justReturn('nl_NL');

        $post = new \WP_Post(['ID' => 7]);

        $this->assertSame('nl_NL', (new LanguageResolver)->getPostLanguage($post));
    }

    public function test_canonical_language_falls_back_to_post_language(): void
    {
        Functions\when('get_locale')->justReturn('en_US');

        $post = new \WP_Post(['ID' => 7]);

        $this->assertSame('en_US', (new LanguageResolver)->getCanonicalLanguage($post));
    }
}
