<?php
/**
 * Registers all available AI tools and formats them for each provider's wire format.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all available tools and formats them for each AI provider's wire format.
 */
class ToolRegistry {

	/**
	 * All registered tool definitions.
	 *
	 * @var ToolDefinition[]
	 */
	private array $tools = [];

	/**
	 * Register all built-in tool definitions on construction.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->register_tools();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Return tools formatted for the given provider slug.
	 *
	 * Write tools are omitted when the wp_ai_mind_enable_write_tools option is falsy.
	 *
	 * @param string $provider_slug One of: claude, openai, gemini, ollama.
	 * @return array
	 */
	public function get_for_provider( string $provider_slug ): array {
		$write_enabled = (bool) \get_option( 'wp_ai_mind_enable_write_tools', false );

		$tools = array_filter(
			$this->tools,
			static function ( ToolDefinition $tool ) use ( $write_enabled ): bool {
				if ( in_array( $tool->name, [ 'create_post', 'update_post' ], true ) ) {
					return false; // Used programmatically only — not exposed to the AI.
				}
				return ! $tool->requires_write_tools || $write_enabled;
			}
		);

		return match ( $provider_slug ) {
			'claude' => $this->format_claude( array_values( $tools ) ),
			'openai' => $this->format_openai( array_values( $tools ) ),
			'gemini' => $this->format_gemini( array_values( $tools ) ),
			default  => [],  // ollama and unknown providers.
		};
	}

	/**
	 * Return the list of allowed post types, honouring developer filter overrides.
	 *
	 * @return array
	 */
	public function allowed_post_types(): array {
		return \apply_filters(
			'wp_ai_mind_allowed_post_types',
			\get_option( 'wp_ai_mind_allowed_post_types', [ 'post', 'page' ] )
		);
	}

	/**
	 * Instantiate and register all built-in tool definitions.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_tools(): void {
		$this->tools[] = new ToolDefinition(
			name: 'get_recent_posts',
			description: 'Get a list of recent posts from the WordPress site',
			parameters: [
				'properties' => [
					'post_type' => [
						'type'        => 'string',
						'description' => 'The post type to retrieve.',
						'default'     => 'post',
					],
					'count'     => [
						'type'        => 'integer',
						'description' => 'Number of posts to return (1–20).',
						'minimum'     => 1,
						'maximum'     => 20,
						'default'     => 5,
					],
					'status'    => [
						'type'        => 'string',
						'description' => 'Post status filter.',
						'default'     => 'publish',
					],
				],
				'required'   => [],
			],
			capability: 'edit_posts',
			requires_write_tools: false,
		);

		$this->tools[] = new ToolDefinition(
			name: 'get_post_content',
			description: 'Get the full content of a specific WordPress post or page by ID',
			parameters: [
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The ID of the post to retrieve.',
					],
				],
				'required'   => [ 'post_id' ],
			],
			capability: 'edit_posts',
			requires_write_tools: false,
		);

		$this->tools[] = new ToolDefinition(
			name: 'search_posts',
			description: 'Search for posts or pages matching a keyword query',
			parameters: [
				'properties' => [
					'query'     => [
						'type'        => 'string',
						'description' => 'The search query string.',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'Post type to search within.',
						'default'     => 'post',
					],
					'count'     => [
						'type'        => 'integer',
						'description' => 'Number of results to return.',
						'default'     => 5,
					],
				],
				'required'   => [ 'query' ],
			],
			capability: 'edit_posts',
			requires_write_tools: false,
		);

		$this->tools[] = new ToolDefinition(
			name: 'create_post',
			description: 'Create a new WordPress post or page',
			parameters: [
				'properties' => [
					'title'     => [
						'type'        => 'string',
						'description' => 'The post title.',
					],
					'content'   => [
						'type'        => 'string',
						'description' => 'The post body content.',
					],
					'status'    => [
						'type'        => 'string',
						'description' => 'Publication status.',
						'enum'        => [ 'draft', 'publish', 'pending' ],
						'default'     => 'draft',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'The post type to create.',
						'default'     => 'post',
					],
				],
				'required'   => [ 'title' ],
			],
			capability: 'edit_posts',
			requires_write_tools: true,
		);

		$this->tools[] = new ToolDefinition(
			name:                'plan_post',
			description:         'Propose a new WordPress blog post or page for user approval. Use this instead of create_post. Provide a title, publication status, and a brief outline of what the post will cover. Do not write the full content — the content will be generated after the user approves the plan.',
			parameters:          [
				'type'       => 'object',
				'properties' => [
					'title'     => [
						'type'        => 'string',
						'description' => 'The post title.',
					],
					'status'    => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish', 'pending' ],
						'description' => 'Publication status.',
					],
					'outline'   => [
						'type'        => 'string',
						'description' => 'A brief outline of the post: key sections, topics, and angle. 1–3 sentences.',
					],
					'post_type' => [
						'type'        => 'string',
						'enum'        => [ 'post', 'page' ],
						'description' => 'Post type. Defaults to post.',
					],
				],
				'required'   => [ 'title' ],
			],
			capability:          'edit_posts',
			requires_write_tools: true,
		);

		$this->tools[] = new ToolDefinition(
			name:                'plan_update',
			description:         'Propose an update to an existing WordPress post for user approval. Provide the post_id and a description of the changes to make. Do not write the full content — it will be generated after the user approves.',
			parameters:          [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The ID of the post to update.',
					],
					'changes' => [
						'type'        => 'string',
						'description' => 'Description of what to add, change, or remove. Be specific (e.g. "Add stages 3 and 4, matching the tone of stage 1").',
					],
					'status'  => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish', 'pending' ],
						'description' => 'New publication status, if changing.',
					],
				],
				'required'   => [ 'post_id', 'changes' ],
			],
			capability:          'edit_posts',
			requires_write_tools: true,
		);

		$this->tools[] = new ToolDefinition(
			name: 'update_post',
			description: 'Update an existing WordPress post or page',
			parameters: [
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The ID of the post to update.',
					],
					'title'   => [
						'type'        => 'string',
						'description' => 'New post title.',
					],
					'content' => [
						'type'        => 'string',
						'description' => 'New post body content.',
					],
					'status'  => [
						'type'        => 'string',
						'description' => 'New publication status.',
					],
				],
				'required'   => [ 'post_id' ],
			],
			capability: 'edit_posts',
			requires_write_tools: true,
		);

		$this->tools[] = new ToolDefinition(
			name: 'get_pages',
			description: 'Get a list of pages on the WordPress site',
			parameters: [
				'properties' => [
					'count' => [
						'type'        => 'integer',
						'description' => 'Number of pages to return.',
						'default'     => 10,
					],
				],
				'required'   => [],
			],
			capability: 'edit_posts',
			requires_write_tools: false,
		);

		$this->tools[] = new ToolDefinition(
			name: 'get_site_info',
			description: 'Get general information about the WordPress site',
			parameters: [
				'properties' => new \stdClass(),
				'required'   => [],
			],
			capability: 'read',
			requires_write_tools: false,
		);

		$this->tools[] = new ToolDefinition(
			name:                 'generate_seo_meta',
			description:          'Generate and apply SEO metadata (meta title, OG description, excerpt, featured-image alt text) for a WordPress post. On the Pro plan the metadata is generated by AI and applied to the post automatically. On the free tier, returns the post data so you can draft SEO suggestions manually and inform the user that automatic application is available on the Pro plan.',
			parameters:           [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The ID of the post to optimise.',
					],
				],
				'required'   => [ 'post_id' ],
			],
			capability:           'edit_posts',
			requires_write_tools: true,
		);
	}

	// -------------------------------------------------------------------------
	// Wire-format formatters
	// -------------------------------------------------------------------------

	/**
	 * Format tools for the Anthropic Claude API.
	 *
	 * @since 1.0.0
	 * @param ToolDefinition[] $tools Tool definitions to format.
	 * @return array
	 */
	private function format_claude( array $tools ): array {
		return array_map(
			static function ( ToolDefinition $tool ): array {
				return [
					'name'         => $tool->name,
					'description'  => $tool->description,
					'input_schema' => [
						'type'       => 'object',
						'properties' => ! empty( $tool->parameters['properties'] ) ? $tool->parameters['properties'] : new \stdClass(),
						'required'   => $tool->parameters['required'] ?? [],
					],
				];
			},
			$tools
		);
	}

	/**
	 * Format tools for the OpenAI API.
	 *
	 * @since 1.0.0
	 * @param ToolDefinition[] $tools Tool definitions to format.
	 * @return array
	 */
	private function format_openai( array $tools ): array {
		return array_map(
			static function ( ToolDefinition $tool ): array {
				return [
					'type'     => 'function',
					'function' => [
						'name'        => $tool->name,
						'description' => $tool->description,
						'parameters'  => [
							'type'       => 'object',
							'properties' => ! empty( $tool->parameters['properties'] ) ? $tool->parameters['properties'] : new \stdClass(),
							'required'   => $tool->parameters['required'] ?? [],
						],
					],
				];
			},
			$tools
		);
	}

	/**
	 * Format tools for the Google Gemini API.
	 *
	 * @since 1.0.0
	 * @param ToolDefinition[] $tools Tool definitions to format.
	 * @return array
	 */
	private function format_gemini( array $tools ): array {
		$declarations = array_map(
			static function ( ToolDefinition $tool ): array {
				return [
					'name'        => $tool->name,
					'description' => $tool->description,
					'parameters'  => [
						'type'       => 'OBJECT',
						'properties' => ! empty( $tool->parameters['properties'] ) ? $tool->parameters['properties'] : new \stdClass(),
						'required'   => $tool->parameters['required'] ?? [],
					],
				];
			},
			$tools
		);

		return [ [ 'functionDeclarations' => $declarations ] ];
	}
}
