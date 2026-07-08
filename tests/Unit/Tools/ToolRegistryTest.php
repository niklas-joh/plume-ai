<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Stub both get_option calls that ToolRegistry uses on construction-time and
	 * get_for_provider-time so tests stay isolated.
	 */
	private function stub_write_tools( bool $enabled ): void {
		Functions\when( 'get_option' )
			->alias( static function ( string $key, $default = false ) use ( $enabled ) {
				if ( 'plume_enable_write_tools' === $key ) {
					return $enabled;
				}
				if ( 'plume_allowed_post_types' === $key ) {
					return $default; // Return default passthrough.
				}
				return $default;
			} );
	}

	private function stub_apply_filters_passthrough(): void {
		Functions\when( 'apply_filters' )
			->alias( static function ( string $tag, $value ) {
				return $value; // Just return the second argument unchanged.
			} );
	}

	// -------------------------------------------------------------------------
	// Provider wire-format tests
	// -------------------------------------------------------------------------

	public function test_get_for_provider_claude_format(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'claude' );

		$this->assertNotEmpty( $tools );
		$first = $tools[0];
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'description', $first );
		$this->assertArrayHasKey( 'input_schema', $first );
		$this->assertSame( 'object', $first['input_schema']['type'] );
		$this->assertArrayHasKey( 'properties', $first['input_schema'] );
		$this->assertArrayHasKey( 'required', $first['input_schema'] );
	}

	public function test_get_for_provider_openai_format(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'openai' );

		$this->assertNotEmpty( $tools );
		$first = $tools[0];
		$this->assertSame( 'function', $first['type'] );
		$this->assertArrayHasKey( 'function', $first );
		$this->assertArrayHasKey( 'name', $first['function'] );
		$this->assertArrayHasKey( 'description', $first['function'] );
		$this->assertArrayHasKey( 'parameters', $first['function'] );
	}

	public function test_get_for_provider_gemini_format(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'gemini' );

		$this->assertNotEmpty( $tools );
		$this->assertArrayHasKey( 'functionDeclarations', $tools[0] );
		$declarations = $tools[0]['functionDeclarations'];
		$this->assertNotEmpty( $declarations );
		$this->assertArrayHasKey( 'name', $declarations[0] );
		$this->assertSame( 'OBJECT', $declarations[0]['parameters']['type'] );
	}

	public function test_get_for_provider_ollama_returns_empty(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'ollama' );

		$this->assertSame( [], $tools );
	}

	// -------------------------------------------------------------------------
	// Write-tools filtering
	// -------------------------------------------------------------------------

	public function test_write_tools_omitted_when_disabled(): void {
		$this->stub_write_tools( false );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'claude' );

		$names = array_column( $tools, 'name' );
		$this->assertNotContains( 'create_post', $names );
		$this->assertNotContains( 'update_post', $names );
	}

	public function test_write_tools_present_when_enabled(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'claude' );

		$names = array_column( $tools, 'name' );
		// create_post and update_post are no longer registered at all — direct
		// writes live in PostWriter. plan_post and plan_update are the AI-facing tools.
		$this->assertNotContains( 'create_post', $names );
		$this->assertNotContains( 'update_post', $names );
		$this->assertContains( 'plan_post', $names );
		$this->assertContains( 'plan_update', $names );
		$this->assertContains( 'submit_post_content', $names );
	}

	public function test_plan_update_no_longer_accepts_new_content_directly(): void {
		// new_content moved to submit_post_content so a single completion never has to
		// share its output-token budget between a short summary and a potentially large
		// post body — see the class docblock on ToolExecutor::plan_update().
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'claude' );

		$by_name = [];
		foreach ( $tools as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		$this->assertArrayNotHasKey( 'new_content', $by_name['plan_update']['input_schema']['properties'] );
		$this->assertNotContains( 'new_content', $by_name['plan_update']['input_schema']['required'] );
	}

	public function test_submit_post_content_requires_post_id_and_content(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'claude' );

		$by_name = [];
		foreach ( $tools as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		$this->assertArrayHasKey( 'submit_post_content', $by_name );
		$schema = $by_name['submit_post_content']['input_schema'];
		$this->assertArrayHasKey( 'post_id', $schema['properties'] );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertSame( [ 'post_id', 'content' ], $schema['required'] );
	}

	public function test_single_use_tools_includes_submit_post_content(): void {
		$this->assertContains( 'submit_post_content', ToolRegistry::SINGLE_USE_TOOLS );
	}

	// -------------------------------------------------------------------------
	// analysis parameter on plan tools
	// -------------------------------------------------------------------------

	public function test_plan_tools_require_analysis_parameter(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'claude' );

		$by_name = [];
		foreach ( $tools as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		foreach ( [ 'plan_post', 'plan_update' ] as $name ) {
			$this->assertArrayHasKey( $name, $by_name );
			$schema = $by_name[ $name ]['input_schema'];
			$this->assertArrayHasKey( 'analysis', $schema['properties'] );
			$this->assertContains( 'analysis', $schema['required'] );
		}
	}

	// -------------------------------------------------------------------------
	// No-default-links guidance on content-producing tool descriptions (#919)
	// -------------------------------------------------------------------------

	public function test_plan_post_content_description_warns_against_default_links(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'claude' );

		$by_name = [];
		foreach ( $tools as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		$description = $by_name['plan_post']['input_schema']['properties']['content']['description'];
		$this->assertStringContainsString( 'hyperlinks', $description );
		$this->assertStringContainsString( 'explicitly', $description );
	}

	public function test_submit_post_content_description_warns_against_default_links(): void {
		$this->stub_write_tools( true );
		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$tools    = $registry->get_for_provider( 'claude' );

		$by_name = [];
		foreach ( $tools as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		$description = $by_name['submit_post_content']['input_schema']['properties']['content']['description'];
		$this->assertStringContainsString( 'hyperlinks', $description );
		$this->assertStringContainsString( 'explicitly', $description );
	}

	// -------------------------------------------------------------------------
	// allowed_post_types()
	// -------------------------------------------------------------------------

	public function test_allowed_post_types_returns_default(): void {
		Functions\when( 'get_option' )
			->alias( static function ( string $key, $default = false ) {
				if ( 'plume_allowed_post_types' === $key ) {
					return $default; // Returns ['post', 'page'].
				}
				return false;
			} );

		$this->stub_apply_filters_passthrough();

		$registry = new ToolRegistry();
		$types    = $registry->allowed_post_types();

		$this->assertSame( [ 'post', 'page' ], $types );
	}

	public function test_allowed_post_types_honours_filter(): void {
		Functions\when( 'get_option' )
			->alias( static function ( string $key, $default = false ) {
				if ( 'plume_allowed_post_types' === $key ) {
					return $default;
				}
				return false;
			} );

		// Override the filter to append 'product'.
		Functions\when( 'apply_filters' )
			->alias( static function ( string $tag, $value ) {
				if ( 'plume_allowed_post_types' === $tag ) {
					return [ 'post', 'page', 'product' ];
				}
				return $value;
			} );

		$registry = new ToolRegistry();
		$types    = $registry->allowed_post_types();

		$this->assertContains( 'product', $types );
		$this->assertCount( 3, $types );
	}
}
