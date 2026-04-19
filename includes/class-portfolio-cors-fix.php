<?php
/**
 * CORS + `_meta.ui.resourceUri` injection for the portfolio MCP endpoint.
 *
 * Two concerns folded into one class since both operate on the REST
 * response pipeline for the same route scope.
 *
 * CORS side (same rationale as KGH_CORS_Fix):
 *   - WordPress core's `rest_send_cors_headers()` emits
 *     `Access-Control-Allow-Origin: *` AND `Access-Control-Allow-Credentials: true`,
 *     an invalid combination per the CORS spec.
 *   - The portfolio endpoint is anonymous and read-only, so Allow-Credentials
 *     is unnecessary noise. We strip it.
 *   - MCP Streamable HTTP (2025-06-18) requires the client to echo
 *     `Mcp-Session-Id` on every follow-up request. Browser MCP hosts
 *     (claude.ai's Custom Connector) can only read that header from the
 *     initialize response if it is in `Access-Control-Expose-Headers`.
 *     Ditto `Mcp-Session-Id` / `Mcp-Protocol-Version` needing to be in
 *     `Access-Control-Allow-Headers` for preflights.
 *
 * `_meta` injection side:
 *   - mcp-adapter v0.4.1's `ToolsHandler::sanitize_tool_data()` passes
 *     only a hardcoded set of fields through to the tools/list response:
 *     name, description, type, inputSchema, outputSchema, annotations.
 *     It does not preserve `_meta`.
 *   - The MCP Apps extension spec requires a `_meta.ui.resourceUri`
 *     annotation on the tool that drives the UI so the host knows which
 *     resource to fetch and render in its sandboxed iframe.
 *   - We filter `rest_post_dispatch`, find the tools/list response for
 *     our route, locate the `portfolio-open` tool by name, and inject
 *     the annotation before WP serializes the body.
 *   - When mcp-adapter gains native `_meta` passthrough we drop this
 *     filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portfolio_CORS_Fix {

	private const ROUTE_PREFIX = '/mcp/matt-artz-portfolio';

	public static function register(): void {
		// CORS header shaping runs at priority 11 so it runs AFTER WP core's
		// rest_send_cors_headers at priority 10 and can override what it set.
		add_filter( 'rest_pre_serve_request', [ self::class, 'adjust_cors' ], 11, 3 );

		// `_meta.ui.resourceUri` injection runs on rest_post_dispatch so we
		// can mutate the WP_REST_Response data before it's serialized.
		add_filter( 'rest_post_dispatch', [ self::class, 'inject_ui_meta' ], 10, 3 );
	}

	public static function adjust_cors( $served, $result, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $served;
		}
		$route = $request->get_route();
		if ( 0 !== strpos( $route, self::ROUTE_PREFIX ) ) {
			return $served;
		}

		header_remove( 'Access-Control-Allow-Credentials' );

		// Reissue expose-headers with Mcp-Session-Id included.
		header_remove( 'Access-Control-Expose-Headers' );
		header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, Link, Mcp-Session-Id' );

		// Expand allow-headers so preflights admit the MCP session header.
		header_remove( 'Access-Control-Allow-Headers' );
		header( 'Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version' );

		return $served;
	}

	/**
	 * Parse the JSON-RPC response body. If it is a tools/list result,
	 * add `_meta.ui.resourceUri` to the `portfolio-open` tool so the MCP
	 * Apps host knows where to fetch the UI HTML.
	 *
	 * @param \WP_HTTP_Response $response
	 * @param \WP_REST_Server   $server
	 * @param \WP_REST_Request  $request
	 * @return \WP_HTTP_Response
	 */
	public static function inject_ui_meta( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response ) {
			return $response;
		}
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 0 !== strpos( $request->get_route(), self::ROUTE_PREFIX ) ) {
			return $response;
		}

		// Only POST /mcp/matt-artz-portfolio carries JSON-RPC bodies.
		if ( 'POST' !== $request->get_method() ) {
			return $response;
		}

		$body   = $request->get_json_params();
		$method = is_array( $body ) ? ( $body['method'] ?? '' ) : '';
		if ( ! in_array( $method, [ 'tools/list', 'resources/list', 'resources/read' ], true ) ) {
			return $response;
		}

		// Normalize to associative array so we can safely mutate. mcp-adapter
		// sometimes returns stdClass-wrapped structures (empty input schemas
		// are stdClass, etc.), which blow up if treated as arrays.
		$data = json_decode( wp_json_encode( $response->get_data() ), true );
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$mutated = false;

		if ( 'tools/list' === $method ) {
			$mutated = self::inject_tool_meta( $data );
		} elseif ( 'resources/list' === $method ) {
			$mutated = self::fix_resource_list( $data );
		} elseif ( 'resources/read' === $method ) {
			$requested_uri = $body['params']['uri'] ?? '';
			if ( $requested_uri === Portfolio_MCP_Server::UI_RESOURCE_URI ) {
				$mutated = self::fix_resource_read( $data );
			}
		}

		if ( $mutated ) {
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * tools/list: add `_meta.ui.resourceUri` to the portfolio-open tool so
	 * MCP Apps-aware hosts know which UI resource to render for it.
	 */
	private static function inject_tool_meta( array &$data ): bool {
		$tools_ref = null;
		if ( isset( $data['result']['tools'] ) && is_array( $data['result']['tools'] ) ) {
			$tools_ref = &$data['result']['tools'];
		} elseif ( isset( $data['tools'] ) && is_array( $data['tools'] ) ) {
			$tools_ref = &$data['tools'];
		}
		if ( null === $tools_ref ) {
			return false;
		}
		$target   = str_replace( '/', '-', Portfolio_MCP_Server::ABILITY_OPEN );
		$mutated  = false;
		foreach ( $tools_ref as &$tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}
			if ( ( $tool['name'] ?? '' ) === $target ) {
				$tool['_meta'] = [
					'ui' => [
						'resourceUri' => Portfolio_MCP_Server::UI_RESOURCE_URI,
					],
				];
				$mutated = true;
			}
		}
		unset( $tool );
		return $mutated;
	}

	/**
	 * resources/list: fix the mimeType on our UI resource entry and strip
	 * the leaked description text. mcp-adapter's default resource mapper
	 * falls back to the ability description as the resource content with
	 * mimeType `text/plain`, ignoring the `mimeType` we set in ability meta.
	 */
	private static function fix_resource_list( array &$data ): bool {
		$resources_ref = null;
		if ( isset( $data['result']['resources'] ) && is_array( $data['result']['resources'] ) ) {
			$resources_ref = &$data['result']['resources'];
		}
		if ( null === $resources_ref ) {
			return false;
		}
		$mutated = false;
		foreach ( $resources_ref as &$resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}
			if ( ( $resource['uri'] ?? '' ) === Portfolio_MCP_Server::UI_RESOURCE_URI ) {
				$resource['mimeType'] = Portfolio_MCP_Server::UI_MIME_TYPE;
				// Resources/list entries should not carry body content, per
				// MCP spec. mcp-adapter inlines the description as a `text`
				// field; remove it.
				unset( $resource['text'] );
				$mutated = true;
			}
		}
		unset( $resource );
		return $mutated;
	}

	/**
	 * resources/read: replace mcp-adapter's fallback content (which echoes
	 * the ability description at text/plain) with the bundled React UI
	 * HTML at the correct mimeType. Also recovers from the adapter
	 * returning an error wrapper by constructing a fresh contents envelope.
	 */
	private static function fix_resource_read( array &$data ): bool {
		// If the request was for our URI, substitute content regardless of
		// whether the handler errored or returned placeholder text. We read
		// the HTML from disk here so the single-file bundle can be
		// regenerated in the plugin's ui/ directory without touching code.
		$request_uri = $data['result']['contents'][0]['uri'] ?? null;
		if ( null === $request_uri ) {
			// Error path: the handler blew up before populating contents.
			// Look at whether the original JSON-RPC request targeted our URI.
			$request_uri = $data['_inferred_uri'] ?? null;
		}

		$html = self::read_ui_html();
		if ( null === $html ) {
			return false;
		}

		$envelope = [
			'contents' => [
				[
					'uri'      => Portfolio_MCP_Server::UI_RESOURCE_URI,
					'mimeType' => Portfolio_MCP_Server::UI_MIME_TYPE,
					'text'     => $html,
				],
			],
		];
		// Replace or overwrite the whole result.
		$data['result'] = $envelope;
		// If mcp-adapter produced a top-level JSON-RPC error, clear it so
		// the client sees a clean result.
		if ( isset( $data['error'] ) ) {
			unset( $data['error'] );
		}
		return true;
	}

	private static function read_ui_html(): ?string {
		$path = defined( 'MATT_ARTZ_PORTFOLIO_MCP_DIR' )
			? MATT_ARTZ_PORTFOLIO_MCP_DIR . 'ui/app.html'
			: null;
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}
		$html = file_get_contents( $path );
		return is_string( $html ) ? $html : null;
	}
}
