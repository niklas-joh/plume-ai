<?php
/**
 * Registers all available AI tools and formats them for each provider's wire format.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tools;

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
	 * Write tools are omitted when the plume_enable_write_tools option is falsy.
	 *
	 * @param string $provider_slug One of: claude, openai, gemini, ollama.
	 * @return array
	 */
	public function get_for_provider( string $provider_slug ): array {
		$write_enabled = (bool) \get_option( 'plume_enable_write_tools', true );

		$tools = array_filter(
			$this->tools,
			static fn( ToolDefinition $tool ): bool => ! $tool->requires_write_tools || $write_enabled
		);

		return match ( $provider_slug ) {
			'claude' => $this->format_claude( array_values( $tools ) ),
			'openai' => $this->format_openai( array_values( $tools ) ),
			'gemini' => $this->format_gemini( array_values( $tools ) ),
			'proxy'  => $this->format_proxy( array_values( $tools ) ),
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
			'plume_allowed_post_types',
			\get_option( 'plume_allowed_post_types', [ 'post', 'page' ] )
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
			name:                'chat_response',
			description:         'Send a conversational reply to the user. Call this tool for EVERY response — acknowledgements, explanations, answers, summaries, and follow-ups after completing an action. This is the only way to deliver text back to the user.',
			parameters:          [
				'type'       => 'object',
				'properties' => [
					'message' => [
						'type'        => 'string',
						'description' => 'The reply to display to the user.',
					],
				],
				'required'   => [ 'message' ],
			],
			capability:          'read',
			requires_write_tools: false,
		);

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
			name:                'plan_post',
			description:         'Propose a new WordPress blog post or page for user approval. Call this tool whenever the user asks you to write, create, draft, or generate a post or page. Provide a title, a brief outline shown on the approval card, and the complete post content that will be published exactly as given when the user approves. After this tool returns, the post is queued for user approval — immediately call chat_response to tell the user it is ready. Do not call plan_post again.',
			parameters:          [
				'type'       => 'object',
				'properties' => [
					'title'       => [
						'type'        => 'string',
						'description' => 'The post title.',
					],
					'status'      => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish', 'pending' ],
						'description' => 'Publication status.',
					],
					'outline'     => [
						'type'        => 'string',
						'description' => 'A brief summary of the post shown to the user on the approval card: key sections, topics, and angle. 1–3 sentences.',
					],
					'content'     => [
						'type'        => 'string',
						'description' => 'The complete post body to publish when the user approves. Must be the full content, not an outline or summary. Write it in Markdown; it is converted to WordPress blocks automatically when applied.',
					],
					'post_type'   => [
						'type'        => 'string',
						'description' => 'Post type (e.g. post, page, product). Must be in the site\'s allowed post types.',
					],
					'meta_fields' => [
						'type'                 => 'object',
						'description'          => 'Optional post meta key/value pairs to set (e.g. {"_price":"9.99","_sku":"ABC"}). String values only.',
						'additionalProperties' => [ 'type' => 'string' ],
					],
				],
				'required'   => [ 'title', 'content' ],
			],
			capability:          'edit_posts',
			requires_write_tools: true,
		);

		$this->tools[] = new ToolDefinition(
			name:                'plan_update',
			description:         'Propose an update to an existing WordPress post for user approval. Call this tool whenever the user asks you to edit, update, revise, improve, or change a post. First retrieve the post content with get_post_content, then provide a human-readable summary of changes AND the full updated content that will be applied when the user approves. After this tool returns, the update is queued for user approval — immediately call chat_response to tell the user it is ready. Do not call plan_update again.',
			parameters:          [
				'type'       => 'object',
				'properties' => [
					'post_id'     => [
						'type'        => 'integer',
						'description' => 'The ID of the post to update.',
					],
					'changes'     => [
						'type'        => 'string',
						'description' => 'Human-readable summary of what is being changed. Shown to the user on the approval card (e.g. "Made the intro punchier and tightened the conclusion").',
					],
					'new_content' => [
						'type'        => 'string',
						'description' => 'The complete updated post content to apply if the user approves. Must be the full post body, not a diff or partial snippet. Write it in Markdown; it is converted to WordPress blocks automatically when applied.',
					],
					'new_title'   => [
						'type'        => 'string',
						'description' => 'The updated post title, if it is also changing. Omit if the title stays the same.',
					],
					'status'      => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish', 'pending' ],
						'description' => 'New publication status, if changing.',
					],
					'meta_fields' => [
						'type'                 => 'object',
						'description'          => 'Optional post meta key/value pairs to update (e.g. {"_price":"9.99"}). String values only.',
						'additionalProperties' => [ 'type' => 'string' ],
					],
				],
				'required'   => [ 'post_id', 'changes', 'new_content' ],
			],
			capability:          'edit_posts',
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

	/**
	 * Format tools in the canonical provider-neutral format for the proxy.
	 *
	 * The Worker (plume-proxy) receives this format and translates it to the
	 * wire format required by the target provider. Using a single canonical shape
	 * here keeps the PHP side decoupled from provider-specific schema conventions.
	 *
	 * @since 1.0.0
	 * @param ToolDefinition[] $tools Tool definitions to format.
	 * @return array<int, array<string, mixed>> Canonical tool definitions.
	 */
	private function format_proxy( array $tools ): array {
		return array_map(
			static function ( ToolDefinition $tool ): array {
				return [
					'name'        => $tool->name,
					'description' => $tool->description,
					'parameters'  => [
						'type'       => 'object',
						'properties' => ! empty( $tool->parameters['properties'] ) ? $tool->parameters['properties'] : new \stdClass(),
						'required'   => $tool->parameters['required'] ?? [],
					],
				];
			},
			$tools
		);
	}
}
