<?php
namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Providers\ProviderFactory;
use Plume\Providers\ClaudeProvider;
use Plume\Providers\OpenAIProvider;
use Plume\Providers\GeminiProvider;
use Plume\Providers\ProviderException;
use Plume\Settings\ProviderSettings;
use PHPUnit\Framework\TestCase;

class ProviderFactoryTest extends TestCase {

    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function make_settings(): ProviderSettings {
        // ProviderSettings reads from wp_options — mock get_option to return empty (no key).
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
        return new ProviderSettings();
    }


    /**
     * Build a factory over a fake ProviderSettings and a stubbed catalogue.
     *
     * @param string[]    $keys        Providers the site has its own key for.
     * @param string      $tier        Site tier.
     * @param string[]    $enabled     Providers the Worker reports as enabled.
     * @param string|bool $default     Stored plume_default_provider (false = unset).
     * @param string|bool $image       Stored plume_image_provider (false = unset).
     */
    private function make_factory( array $keys, string $tier, array $enabled, $default = 'claude', $image = false ): ProviderFactory {
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = null ) => match ( $k ) {
                'plume_default_provider' => $default,
                'plume_image_provider'   => $image,
                'plume_site_tier'        => $tier,
                default                  => ( is_array( $d ) ? $d : '' ),
            }
        );
        Functions\when( 'get_transient' )->justReturn(
            [
                'enabled_providers' => $enabled,
                'models'            => [],
                'default_models'    => [],
            ]
        );
        $settings = new class( $keys ) extends ProviderSettings {
            /** @param string[] $keys */
            public function __construct( private array $keys ) {}
            public function has_key( string $provider ): bool {
                return in_array( $provider, $this->keys, true );
            }
            public function has_any_key(): bool {
                return array() !== $this->keys;
            }
            public function get_api_key( string $provider ): string {
                return $this->has_key( $provider ) ? 'key' : '';
            }
        };
        return new ProviderFactory( $settings );
    }

    public function test_make_returns_claude_provider(): void {
        $factory = new ProviderFactory( $this->make_settings() );
        $this->assertInstanceOf( ClaudeProvider::class, $factory->make( 'claude' ) );
    }

    public function test_make_returns_openai_provider(): void {
        $factory = new ProviderFactory( $this->make_settings() );
        $this->assertInstanceOf( OpenAIProvider::class, $factory->make( 'openai' ) );
    }

    public function test_make_returns_gemini_provider(): void {
        $factory = new ProviderFactory( $this->make_settings() );
        $this->assertInstanceOf( GeminiProvider::class, $factory->make( 'gemini' ) );
    }

    public function test_make_throws_for_unknown_provider(): void {
        $factory = new ProviderFactory( $this->make_settings() );
        $this->expectException( \InvalidArgumentException::class );
        $factory->make( 'nonexistent' );
    }

    public function test_make_default_reads_from_options(): void {
        // An own OpenAI key makes the stored default honoured (no managed substitution).
        $factory = $this->make_factory( [ 'openai' ], 'free', [ 'claude' ], 'openai' );
        $this->assertInstanceOf( OpenAIProvider::class, $factory->make_default() );
    }

    public function test_make_default_falls_back_to_claude(): void {
        $factory = $this->make_factory( [], 'free', [ 'claude' ], false );
        $this->assertInstanceOf( ClaudeProvider::class, $factory->make_default() );
    }

    public function test_make_image_provider_returns_gemini_by_default(): void {
        Functions\when( 'get_option' )->alias( fn( $k, $d = null ) =>
            in_array( $k, [ 'plume_image_provider', 'plume_default_provider' ], true ) ? false : ( is_array( $d ) ? $d : [] )
        );
        Functions\when( 'sanitize_key' )->alias( fn($v) => $v );
        $factory = new ProviderFactory( new ProviderSettings() );
        $this->assertInstanceOf( GeminiProvider::class, $factory->make_image_provider() );
    }

    // ── resolve_text_slug ─────────────────────────────────────────────────────

    // TEMPORARY(#1020): the rows expecting 'claude' for a keyless site describe the
    // Claude substitution. On removal: those rows then expect the slug unchanged.
    /**
     * @return array<string, array{string, string[], string, string[], string}>
     */
    public static function resolve_matrix(): array {
        return [
            'openai, no key, free, disabled'     => [ 'openai', [], 'free', [ 'claude' ], 'claude' ],
            'openai, own key, free, disabled'    => [ 'openai', [ 'openai' ], 'free', [ 'claude' ], 'openai' ],
            'openai, no key, pro_byok'           => [ 'openai', [], 'pro_byok', [ 'claude' ], 'openai' ],
            'gemini, no key, free, enabled'      => [ 'gemini', [], 'free', [ 'claude', 'gemini' ], 'gemini' ],
            'ollama'                             => [ 'ollama', [], 'free', [ 'claude' ], 'ollama' ],
            'empty slug'                         => [ '', [], 'free', [ 'claude' ], 'claude' ],
            'openai, only a Claude key (mixed)'  => [ 'openai', [ 'claude' ], 'free', [ 'claude' ], 'openai' ],
        ];
    }

    /**
     * @dataProvider resolve_matrix
     * @param string   $slug     Requested slug.
     * @param string[] $keys     Own keys.
     * @param string   $tier     Tier.
     * @param string[] $enabled  Enabled providers.
     * @param string   $expected Expected slug.
     */
    public function test_resolve_text_slug( string $slug, array $keys, string $tier, array $enabled, string $expected ): void {
        $factory = $this->make_factory( $keys, $tier, $enabled );
        $this->assertSame( $expected, $factory->resolve_text_slug( $slug, $tier ) );
    }

    // ── missing_key_for ───────────────────────────────────────────────────────

    // TEMPORARY(#1020): the 'no keys at all' row is false only because of the has_any_key()
    // clause in missing_key_for(). On removal: it becomes true.
    /**
     * @return array<string, array{string, string[], string, string[], bool}>
     */
    public static function missing_key_matrix(): array {
        return [
            'Claude key only, openai disabled' => [ 'openai', [ 'claude' ], 'free', [ 'claude' ], true ],
            'no keys at all'                   => [ 'openai', [], 'free', [ 'claude' ], false ],
            'OpenAI key present'               => [ 'openai', [ 'openai' ], 'free', [ 'claude' ], false ],
            'pro_byok'                         => [ 'openai', [ 'claude' ], 'pro_byok', [ 'claude' ], false ],
            'openai enabled'                   => [ 'openai', [ 'claude' ], 'free', [ 'claude', 'openai' ], false ],
            'ollama'                           => [ 'ollama', [ 'claude' ], 'free', [ 'claude' ], false ],
        ];
    }

    /**
     * @dataProvider missing_key_matrix
     * @param string   $slug     Requested slug.
     * @param string[] $keys     Own keys.
     * @param string   $tier     Tier.
     * @param string[] $enabled  Enabled providers.
     * @param bool     $expected Expected result.
     */
    public function test_missing_key_for( string $slug, array $keys, string $tier, array $enabled, bool $expected ): void {
        $factory = $this->make_factory( $keys, $tier, $enabled );
        $this->assertSame( $expected, $factory->missing_key_for( $slug, $tier ) );
    }

    // ── label ─────────────────────────────────────────────────────────────────

    public function test_label_returns_brand_names_and_falls_back_to_ucfirst(): void {
        $factory = $this->make_factory( [], 'free', [ 'claude' ] );
        $this->assertSame( 'Claude', $factory->label( 'claude' ) );
        $this->assertSame( 'OpenAI', $factory->label( 'openai' ) );
        $this->assertSame( 'Gemini', $factory->label( 'gemini' ) );
        $this->assertSame( 'Ollama', $factory->label( 'ollama' ) );
        $this->assertSame( 'Mistral', $factory->label( 'mistral' ) );
    }

    // ── make_default / make_image_provider ────────────────────────────────────

    // TEMPORARY(#1020): substitution of a disabled managed provider by Claude.
    // On removal: delete this test (make_default() then throws provider_key_missing).
    public function test_temporary_1020_make_default_returns_claude_for_disabled_openai_without_any_key(): void {
        $factory = $this->make_factory( [], 'free', [ 'claude' ], 'openai' );
        $this->assertInstanceOf( ClaudeProvider::class, $factory->make_default() );
    }

    public function test_make_default_on_a_mixed_site_throws_provider_key_missing(): void {
        Functions\when( '__' )->returnArg();
        $factory = $this->make_factory( [ 'claude' ], 'free', [ 'claude' ], 'openai' );

        try {
            $factory->make_default();
            $this->fail( 'Expected a ProviderException.' );
        } catch ( ProviderException $e ) {
            $this->assertSame( 'provider_key_missing', $e->get_error_code() );
            $this->assertSame(
                'You have not added an API key for OpenAI. Add one under Settings → Providers, or choose a different model.',
                $e->getMessage()
            );
        }
    }

    public function test_make_image_provider_is_not_affected_by_managed_catalogue(): void {
        $factory = $this->make_factory( [], 'free', [ 'claude' ], 'claude', 'gemini' );
        $this->assertInstanceOf( GeminiProvider::class, $factory->make_image_provider() );

        $factory = $this->make_factory( [], 'free', [ 'claude' ], 'claude', 'openai' );
        $this->assertInstanceOf( OpenAIProvider::class, $factory->make_image_provider() );
    }
}
