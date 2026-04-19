<?php
/**
 * Plugin Name: Matt Artz Portfolio MCP
 * Plugin URI: https://www.mattartz.me
 * Description: Public MCP App server that exposes Matt Artz's interactive portfolio at /wp-json/mcp/matt-artz-portfolio. Any MCP Apps-aware host (Claude Desktop, claude.ai Custom Connectors) that adds this URL gets a sandboxed iframe UI plus text-only tools over the same data.
 * Version: 0.1.0
 * Author: Matt Artz
 * Author URI: https://www.mattartz.me
 * License: GPL-2.0-or-later
 * Text Domain: matt-artz-portfolio-mcp
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MATT_ARTZ_PORTFOLIO_MCP_VERSION', '0.1.0' );
define( 'MATT_ARTZ_PORTFOLIO_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MATT_ARTZ_PORTFOLIO_MCP_FILE', __FILE__ );

require_once MATT_ARTZ_PORTFOLIO_MCP_DIR . 'includes/class-portfolio-mcp-server.php';
require_once MATT_ARTZ_PORTFOLIO_MCP_DIR . 'includes/class-portfolio-cors-fix.php';

/**
 * Boot the server singleton at plugin load so its hooks on
 * `wp_abilities_api_init` and `mcp_adapter_init` register in time.
 */
Portfolio_MCP_Server::instance();

/**
 * Register the CORS + _meta injection filter on init. Separate class so
 * the response-shaping concern stays isolated from the server registration.
 */
add_action( 'init', [ 'Portfolio_CORS_Fix', 'register' ] );
