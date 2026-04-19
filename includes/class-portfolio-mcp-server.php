<?php
/**
 * Matt Artz Portfolio MCP Server.
 *
 * Registers three read-only abilities with the WordPress Abilities API and
 * exposes them as an MCP server at /wp-json/mcp/matt-artz-portfolio.
 *
 * Two of the abilities are registered as MCP tools:
 *   - portfolio/open    Returns a text summary AND carries the
 *                       `_meta.ui.resourceUri` annotation that tells a
 *                       Claude Desktop MCP host to render the bundled
 *                       React UI in a sandboxed iframe inline in the
 *                       conversation.
 *   - portfolio/query   Text-only search. Returns matching items as
 *                       structured JSON for LLM reasoning outside the UI.
 *
 * The third ability is registered as an MCP resource at the URI
 *   ui://matt-artz-portfolio/app.html
 * and serves the pre-built React single-file HTML bundle shipped in
 * this plugin's `ui/` directory. The MCP Apps extension spec requires
 * this pairing: a tool annotation pointing at a `ui://` resource the
 * host can fetch and render.
 *
 * mcp-adapter v0.4.1 does not pass `_meta` through `sanitize_tool_data`
 * on tools/list responses. A separate class, `Portfolio_CORS_Fix`,
 * hooks `rest_post_dispatch` and injects the annotation in the response
 * body before it is flushed. That file documents the workaround.
 *
 * Follows the same pattern as the KGH_MCP_Server class: guest-user
 * auto-authentication for anonymous access, raised session cap, and an
 * SSE GET interceptor so browser-based MCP hosts (claude.ai's Custom
 * Connector) don't abandon the session when the optional SSE listener
 * 405s out.
 *
 * Endpoint: POST /wp-json/mcp/matt-artz-portfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portfolio_MCP_Server {

	private static ?self $instance = null;

	/**
	 * Server identity constants.
	 */
	public const SERVER_ID          = 'matt-artz-portfolio';
	public const SERVER_NAMESPACE   = 'mcp';
	public const SERVER_ROUTE       = 'matt-artz-portfolio';
	public const SERVER_NAME        = 'Matt Artz Portfolio';
	public const SERVER_DESCRIPTION = "Interactive portfolio for Matt Artz. Call the `portfolio-open` tool to render the full visual portfolio as a sandboxed UI panel, or `portfolio-query` for a text-only JSON search. Read-only, anonymous.";

	public const ABILITY_OPEN     = 'portfolio/open';
	public const ABILITY_QUERY    = 'portfolio/query';
	public const ABILITY_UI_ASSET = 'portfolio/ui-app-html';

	public const UI_RESOURCE_URI  = 'ui://matt-artz-portfolio/app.html';
	public const UI_MIME_TYPE     = 'text/html;profile=mcp-app';

	private const GUEST_USER_LOGIN    = 'portfolio_mcp_guest';
	private const GUEST_USER_META_KEY = 'portfolio_mcp_guest_user_id';
	private const REST_ROUTE_PREFIX   = '/wp-json/mcp/matt-artz-portfolio';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
		add_action( 'mcp_adapter_init', [ $this, 'register_server' ] );
		add_action( 'admin_notices', [ $this, 'maybe_admin_notice' ] );

		add_filter( 'determine_current_user', [ $this, 'auto_authenticate' ], 999 );
		add_filter( 'mcp_adapter_session_max_per_user', [ $this, 'raise_session_max' ], 10, 1 );

		// GET /wp-json/mcp/matt-artz-portfolio with Accept: text/event-stream
		// is the MCP Streamable HTTP listener. mcp-adapter's stub 405s. We
		// intercept with a 200 empty SSE so browser hosts stay happy.
		add_filter( 'rest_pre_dispatch', [ $this, 'intercept_sse_get' ], 1, 3 );
	}

	// ═══════════════════════════════════════════════════════════════
	//  Ability registrations
	// ═══════════════════════════════════════════════════════════════

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'portfolio',
			[
				'label'       => __( 'Portfolio', 'matt-artz-portfolio-mcp' ),
				'description' => __( "Read-only abilities that expose Matt Artz's interactive portfolio to MCP clients.", 'matt-artz-portfolio-mcp' ),
			]
		);
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_open_ability();
		$this->register_query_ability();
		$this->register_ui_resource_ability();
	}

	/**
	 * The "open" tool. Returns a text summary of the portfolio, suitable for
	 * the model's reasoning context. The UI resource annotation is injected
	 * by Portfolio_CORS_Fix::inject_ui_meta() on tools/list responses since
	 * mcp-adapter does not surface `_meta` on tool descriptions directly.
	 */
	private function register_open_ability(): void {
		wp_register_ability(
			self::ABILITY_OPEN,
			[
				'label'               => __( 'Open Portfolio', 'matt-artz-portfolio-mcp' ),
				'description'         => "Opens Matt Artz's interactive portfolio as a visual panel in the host. Shows roles, projects, publications, talks, teaching, podcasts, and service, filterable by type, theme, and year. Click any item for its detail panel with approach, reflective notes, and linked artifacts. Use this when the user asks to see or explore the portfolio.",
				'category'            => 'portfolio',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => new stdClass(),
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'summary' => [ 'type' => 'string' ],
						'counts'  => [ 'type' => 'object' ],
					],
				],
				'permission_callback' => '__return_true',
				'execute_callback'    => function () {
					$items = self::load_items();
					$counts = [];
					foreach ( $items as $it ) {
						$t = $it['type'] ?? 'item';
						$counts[ $t ] = ( $counts[ $t ] ?? 0 ) + 1;
					}
					$count_parts = [];
					foreach ( $counts as $t => $n ) {
						$count_parts[] = $n . ' ' . $t . ( $n > 1 ? 's' : '' );
					}
					$featured = array_values( array_filter( $items, static fn( $i ) => ! empty( $i['featured'] ) ) );
					$featured_titles = array_map( static fn( $i ) => $i['title'] ?? '', $featured );

					$summary = sprintf(
						'Portfolio loaded. %d items total: %s. Featured: %s.',
						count( $items ),
						implode( ', ', $count_parts ),
						implode( '; ', $featured_titles )
					);

					return [
						'summary' => $summary,
						'counts'  => $counts,
					];
				},
				'meta' => [
					'annotations' => [
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					],
				],
			]
		);
	}

	/**
	 * Text-only JSON search over the portfolio items. Used for LLM flows
	 * that do not open the UI panel.
	 */
	private function register_query_ability(): void {
		wp_register_ability(
			self::ABILITY_QUERY,
			[
				'label'               => __( 'Query Portfolio', 'matt-artz-portfolio-mcp' ),
				'description'         => "Text-only search over Matt Artz's portfolio items. Returns matching entries as JSON. Filter by type, theme, free-text query, or year range. Use when you want to reason about specific items without opening the visual UI.",
				'category'            => 'portfolio',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'query'    => [ 'type' => 'string', 'description' => 'Free-text search across title, summary, organization, and themes.' ],
						'type'     => [
							'type' => 'string',
							'enum' => [ 'role', 'project', 'publication', 'talk', 'teaching', 'podcast', 'service' ],
						],
						'theme'    => [ 'type' => 'string' ],
						'yearFrom' => [ 'type' => 'integer' ],
						'yearTo'   => [ 'type' => 'integer' ],
						'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
					],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total' => [ 'type' => 'integer' ],
						'items' => [ 'type' => 'array' ],
					],
				],
				'permission_callback' => '__return_true',
				'execute_callback'    => function ( $args ) {
					$items  = self::load_items();
					$q      = isset( $args['query'] ) ? strtolower( trim( (string) $args['query'] ) ) : '';
					$type   = $args['type']     ?? null;
					$theme  = $args['theme']    ?? null;
					$from   = isset( $args['yearFrom'] ) ? (int) $args['yearFrom'] : null;
					$to     = isset( $args['yearTo'] )   ? (int) $args['yearTo']   : null;
					$limit  = isset( $args['limit'] )    ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;

					$matches = [];
					foreach ( $items as $it ) {
						if ( $type && ( $it['type'] ?? '' ) !== $type ) {
							continue;
						}
						if ( $theme && ! in_array( $theme, $it['themes'] ?? [], true ) ) {
							continue;
						}
						$start_year = (int) substr( $it['dateStart'] ?? '0000', 0, 4 );
						if ( null !== $from && $start_year < $from ) {
							continue;
						}
						if ( null !== $to && $start_year > $to ) {
							continue;
						}
						if ( $q ) {
							$hay = strtolower(
								( $it['title'] ?? '' ) . ' ' .
								( $it['summary'] ?? '' ) . ' ' .
								( $it['organization'] ?? '' ) . ' ' .
								implode( ' ', (array) ( $it['themes'] ?? [] ) )
							);
							if ( false === strpos( $hay, $q ) ) {
								continue;
							}
						}
						$matches[] = $it;
					}

					usort( $matches, static fn( $a, $b ) => strcmp( $b['dateStart'] ?? '', $a['dateStart'] ?? '' ) );
					$matches = array_slice( $matches, 0, $limit );

					return [
						'total' => count( $matches ),
						'items' => $matches,
					];
				},
				'meta' => [
					'annotations' => [
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					],
				],
			]
		);
	}

	/**
	 * The MCP resource ability. Its `meta.uri` + `meta.mimeType` tell
	 * mcp-adapter's `RegisterAbilityAsMcpResource` to expose this as a
	 * resource at `ui://matt-artz-portfolio/app.html`. When a host calls
	 * `resources/read` against that URI, the execute_callback returns the
	 * bundled React HTML.
	 */
	private function register_ui_resource_ability(): void {
		wp_register_ability(
			self::ABILITY_UI_ASSET,
			[
				'label'               => __( 'Portfolio UI', 'matt-artz-portfolio-mcp' ),
				'description'         => 'Bundled single-file React UI for the portfolio. Rendered in a sandboxed iframe by MCP Apps-aware hosts.',
				'category'            => 'portfolio',
				// Permissive schema: resources/read may invoke with no
				// arguments (empty array), which otherwise fails "type:
				// object" validation.
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => new stdClass(),
					'additionalProperties' => true,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'html' => [ 'type' => 'string' ] ],
				],
				'permission_callback' => '__return_true',
				'execute_callback'    => [ $this, 'serve_ui_html' ],
				'meta' => [
					'uri'      => self::UI_RESOURCE_URI,
					'mimeType' => self::UI_MIME_TYPE,
				],
			]
		);
	}

	/**
	 * Read the bundled UI HTML from disk. Returned as a raw string so
	 * mcp-adapter wraps it as a TextResourceContents entry in the
	 * resources/read response.
	 */
	public function serve_ui_html() {
		$path = MATT_ARTZ_PORTFOLIO_MCP_DIR . 'ui/app.html';
		if ( ! file_exists( $path ) ) {
			return '<!doctype html><html><body><p>Portfolio UI bundle missing. Build and drop dist/mcp-app.html into the plugin\'s ui/ directory.</p></body></html>';
		}
		return (string) file_get_contents( $path );
	}

	// ═══════════════════════════════════════════════════════════════
	//  Data loader
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Load portfolio items from the bundled JSON file. Cached per request.
	 * This is intentionally static curated content rather than a live KG
	 * query. The tools that operate on it are pure functions of this
	 * dataset.
	 */
	public static function load_items(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$path = MATT_ARTZ_PORTFOLIO_MCP_DIR . 'data/portfolio.json';
		if ( ! file_exists( $path ) ) {
			$cache = [];
			return $cache;
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$cache   = is_array( $decoded ) ? $decoded : [];
		return $cache;
	}

	// ═══════════════════════════════════════════════════════════════
	//  Server registration
	// ═══════════════════════════════════════════════════════════════

	public function register_server( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$http_transport = 'WP\\MCP\\Transport\\HttpTransport';
		$error_handler  = 'WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
		$observability  = 'WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';

		if ( ! class_exists( $http_transport ) || ! class_exists( $error_handler ) || ! class_exists( $observability ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::SERVER_NAMESPACE,
			self::SERVER_ROUTE,
			self::SERVER_NAME,
			self::SERVER_DESCRIPTION,
			MATT_ARTZ_PORTFOLIO_MCP_VERSION,
			[ $http_transport ],
			$error_handler,
			$observability,
			// Abilities exposed as MCP tools.
			[ self::ABILITY_OPEN, self::ABILITY_QUERY ],
			// Abilities exposed as MCP resources.
			[ self::ABILITY_UI_ASSET ],
			// No prompts.
			[],
			// Public anonymous access; individual abilities enforce their
			// own permission_callback (all __return_true by design).
			'__return_true'
		);
	}

	// ═══════════════════════════════════════════════════════════════
	//  Guest auto-auth (mirrors KGH_MCP_Server pattern)
	// ═══════════════════════════════════════════════════════════════

	public function auto_authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( false === strpos( $uri, self::REST_ROUTE_PREFIX ) ) {
			return $user_id;
		}
		$guest_id = $this->ensure_guest_user();
		return $guest_id ?: $user_id;
	}

	public function raise_session_max( $max ): int {
		return max( (int) $max, 1000 );
	}

	private function ensure_guest_user(): int {
		$cached = (int) get_option( self::GUEST_USER_META_KEY, 0 );
		if ( $cached && get_user_by( 'id', $cached ) ) {
			return $cached;
		}
		$existing = get_user_by( 'login', self::GUEST_USER_LOGIN );
		if ( $existing ) {
			update_option( self::GUEST_USER_META_KEY, $existing->ID, false );
			return (int) $existing->ID;
		}
		$user_id = wp_insert_user( [
			'user_login'   => self::GUEST_USER_LOGIN,
			'user_pass'    => wp_generate_password( 64, true, true ),
			'user_email'   => self::GUEST_USER_LOGIN . '+' . wp_generate_uuid4() . '@no-reply.invalid',
			'display_name' => 'Portfolio MCP Guest',
			'role'         => 'subscriber',
		] );
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}
		update_option( self::GUEST_USER_META_KEY, (int) $user_id, false );
		return (int) $user_id;
	}

	// ═══════════════════════════════════════════════════════════════
	//  SSE GET interceptor (same as KGH)
	// ═══════════════════════════════════════════════════════════════

	public function intercept_sse_get( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( 'GET' !== $request->get_method() ) {
			return $result;
		}
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/mcp/' . self::SERVER_ROUTE ) ) {
			return $result;
		}
		$accept = (string) $request->get_header( 'accept' );
		if ( false === stripos( $accept, 'text/event-stream' ) ) {
			return $result;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' );
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';
		if ( $origin ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, Link, Mcp-Session-Id' );
		}
		echo ": ok\n\n";
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			flush();
		}
		exit;
	}

	// ═══════════════════════════════════════════════════════════════
	//  Admin notice
	// ═══════════════════════════════════════════════════════════════

	public function maybe_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = [];
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$missing[] = 'Abilities API';
		}
		if ( ! class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
			$missing[] = 'MCP Adapter';
		}
		if ( empty( $missing ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Matt Artz Portfolio MCP:</strong> required plugin(s) not active: %s. The endpoint at <code>/wp-json/mcp/matt-artz-portfolio</code> will not work until both Abilities API and MCP Adapter are active.</p></div>',
			esc_html( implode( ', ', $missing ) )
		);
	}
}
