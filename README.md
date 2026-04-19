# Matt Artz Portfolio MCP

A WordPress plugin that turns a personal knowledge graph into an interactive MCP App. Exposes a public Model Context Protocol endpoint, pairs it with a bundled React UI rendered as a sandboxed iframe inside any MCP Apps-aware host (Claude Desktop, claude.ai, Claude Code).

The endpoint is the artifact. There is no browser-facing landing page. Paste the URL into an MCP-aware client and the UI builds dynamically inside that client.

## Endpoint

```
https://www.mattartz.me/wp-json/mcp/matt-artz-portfolio
```

## Install

**Claude Desktop**, in `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "matt-artz-portfolio": {
      "type": "http",
      "url": "https://www.mattartz.me/wp-json/mcp/matt-artz-portfolio"
    }
  }
}
```

Restart Claude Desktop. Ask Claude to open the portfolio. The interactive UI renders in a sandboxed iframe inline in the conversation.

**Claude Code CLI**:

```bash
claude mcp add --transport http matt-artz-portfolio https://www.mattartz.me/wp-json/mcp/matt-artz-portfolio
```

**claude.ai web**: Settings > Connectors > Add custom connector, paste the endpoint URL.

## What the connector exposes

Two read-only tools:

- `portfolio-open`, returns a text summary and carries an `_meta.ui.resourceUri` annotation pointing at the bundled React UI. MCP Apps-aware hosts fetch the resource and render it inline in the conversation.
- `portfolio-query`, text-only JSON search over the portfolio items. Filter by type, theme, free-text query, or year range.

One MCP resource:

- `ui://matt-artz-portfolio/app.html`, a single-file React bundle served with MIME type `text/html;profile=mcp-app` per the MCP Apps 2026-01-26 extension spec.

## Architecture

First WP-native MCP Apps implementation I am aware of. mcp-adapter v0.4.1 does not natively pass `_meta` through tool descriptions or invoke resource callbacks to fetch custom content. Both gaps are handled by a single `rest_post_dispatch` filter in `Portfolio_CORS_Fix` that mutates the JSON-RPC response body:

- On `tools/list`, injects `_meta.ui.resourceUri` on the `portfolio-open` tool
- On `resources/list`, corrects the mimeType of the UI resource
- On `resources/read` for our URI, substitutes the actual HTML bundle from disk

When mcp-adapter adds native `_meta` passthrough and custom-content callback invocation, this filter can be retired.

The plugin also mirrors production hardening from the author's Knowledge Graph Hub plugin:

- Dedicated guest WordPress user for anonymous auth
- Raised session cap to 1000 per user
- Empty SSE stream interceptor on GET so browser-based MCP hosts do not abandon the session when the optional server-sent-events listener returns 405
- CORS `Mcp-Session-Id` exposure and allow-headers widening so browser clients can echo the session header

## Files

```
matt-artz-portfolio-mcp.php           Plugin bootstrap
includes/
  class-portfolio-mcp-server.php      Singleton, abilities, server registration, guest auth, SSE
  class-portfolio-cors-fix.php        CORS + JSON-RPC response shaping
data/portfolio.json                   Portfolio items (seed entries, hand-curated)
ui/app.html                           Single-file React UI bundle
```

## Dependencies

- WordPress 6.0+
- PHP 8.0+
- [abilities-api](https://github.com/WordPress/abilities-api) plugin
- [mcp-adapter](https://github.com/WordPress/mcp-adapter) plugin (v0.4.1 or compatible)

## License

MIT
