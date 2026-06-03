<?php
declare( strict_types=1 );

namespace Stilus\Tests\Unit\Tools;

use Stilus\Tools\ToolDefinition;
use PHPUnit\Framework\TestCase;

class ToolDefinitionTest extends TestCase {

	public function test_constructor_sets_all_properties(): void {
		$params = [
			'properties' => [ 'post_id' => [ 'type' => 'integer' ] ],
			'required'   => [ 'post_id' ],
		];

		$tool = new ToolDefinition(
			name: 'my_tool',
			description: 'Does something useful',
			parameters: $params,
			capability: 'manage_options',
			requires_write_tools: true,
		);

		$this->assertSame( 'my_tool', $tool->name );
		$this->assertSame( 'Does something useful', $tool->description );
		$this->assertSame( $params, $tool->parameters );
		$this->assertSame( 'manage_options', $tool->capability );
		$this->assertTrue( $tool->requires_write_tools );
	}

	public function test_default_capability_is_edit_posts(): void {
		$tool = new ToolDefinition(
			name: 'simple_tool',
			description: 'A tool with defaults',
			parameters: [ 'properties' => [], 'required' => [] ],
		);

		$this->assertSame( 'edit_posts', $tool->capability );
	}

	public function test_default_requires_write_tools_is_false(): void {
		$tool = new ToolDefinition(
			name: 'read_tool',
			description: 'A read-only tool',
			parameters: [ 'properties' => [], 'required' => [] ],
		);

		$this->assertFalse( $tool->requires_write_tools );
	}
}
