<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    public function test_default_language_falls_back_to_locale(): void
    {
        Functions\when('get_locale')->justReturn('en_US');

        $this->assertSame('en_US', (new LanguageResolver)->getDefaultLanguage());
    }

    public function test_translations_fall_back_to_single_entry(): void
    {
        Functions\when('get_locale')->justReturn('nl_NL');

        $resolver = new LanguageResolver;
        $post = new \WP_Post(['ID' => 7]);

        $this->assertSame(['nl_NL' => 7], $resolver->getTranslations($post));
        $this->assertSame(7, $resolver->getCanonicalPostId($post));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_resolves_polylang_translation_group(): void
    {
        Functions\when('pll_get_post_translations')->justReturn(['nl' => 10, 'en' => 20]);
        Functions\when('pll_default_language')->justReturn('nl');

        $resolver = new LanguageResolver;
        $post = new \WP_Post(['ID' => 20]);

        $this->assertSame(['nl' => 10, 'en' => 20], $resolver->getTranslations($post));
        // Canonical post is the default-language (nl) translation, not the
        // post that triggered resolution.
        $this->assertSame(10, $resolver->getCanonicalPostId($post));
    }
}
