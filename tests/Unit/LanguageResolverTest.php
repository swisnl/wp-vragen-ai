<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use VragenAI\LanguageResolver;
use VragenAI\Tests\TestCase;

class LanguageResolverTest extends TestCase
{
    public function testFallsBackToSiteLocaleWithoutPlugins(): void
    {
        Functions\when('get_locale')->justReturn('nl_NL');

        $post = new \WP_Post(['ID' => 7]);

        $this->assertSame('nl_NL', (new LanguageResolver())->getPostLanguage($post));
    }

    public function testCanonicalLanguageFallsBackToPostLanguage(): void
    {
        Functions\when('get_locale')->justReturn('en_US');

        $post = new \WP_Post(['ID' => 7]);

        $this->assertSame('en_US', (new LanguageResolver())->getCanonicalLanguage($post));
    }
}
