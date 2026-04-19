# Matt Artz Portfolio MCP

A WordPress plugin that turns a personal knowledge graph into an interactive MCP App. Exposes a public Model Context Protocol endpoint at `/wp-json/mcp/matt-artz-portfolio`, pairs it with a bundled React UI rendered as a sandboxed iframe inside any MCP Apps-aware host (Claude Desktop, claude.ai, Claude Code).

**Landing page:** [mattartz.me/portfolio](https://www.mattartz.me/portfolio)
**Source of the React UI:** [github.com/mattartz/matt-artz-portfolio](https://github.com/mattartz/matt-artz-portfolio) (standalone repo)

## What it does

Two read-only MCP tools:

- `portfolio-open`, returns a text summary and carries an `_meta.ui.resourceUri` annotation pointing at the bundled React UI. MCP Apps-aware hosts fetch the resource and render it inline in the conversation.
- `portfolio-query`, text-only JSON search over the portfolio items (roles, projects, publications, talks, teaching, podcasts, service). Filter by type, theme, free-text query, or year range.

One MCP resource:

- `ui://matt-artz-portfolio/app.html`, the single-file React bundle served with MIME type `text/html;profile=mcp-app` per the MCP Apps 2026-01-26 extension spec.

## Install

Claude Desktop, in `~/Library/Application Support/Claude/claude_desktop_config.json`:

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

Claude Code CLI:

```bash
claude mcp add --transport http matt-artz-portfolio https://www.mattartz.me/wp-json/mcp/matt-artz-portfolio
```

claude.ai web, Settings > Connectors > Add custom connector, paste the endpoint URL.

## Architecture

This is the first WP-native MCP Apps implementation I am aware of. mcp-adapter v0.4.1 does not natively pass `_meta` through tool descriptions or call resource callbacks to fetch custom content. Both gaps are handled by a single `rest_post_dispatch` filter in `Portfolio_CORS_Fix` that mutates the JSON-RPC response body:

- On `tools/list`, injects `_meta.ui.resourceUri` on the `portfolio-open` tool
- On `resources/list`, corrects the mimeType of the UI resource
- On `resources/read` for our URI, substitutes the actual HTML bundle from disk

When mcp-adapter ships native `_meta` passthrough and custom-content callback invocation, this filter can be retired.

The plugin also mirrors the production hardening from the author's Knowledge Graph Hub plugin:

- Dedicated guest WordPress user for anonymous auth
- Raised session cap to 1000 per user
- Empty SSE stream interceptor on GET so browser-based MCP hosts don't abandon the session when the optional server-sent-events listener 405s out
- CORS `Mcp-Session-Id` exposure and allow-headers widening so browser clients can echo the session header

## Files

```
matt-artz-portfolio-mcp.php           Plugin bootstrap
includes/
  class-portfolio-mcp-server.php      Singleton, abilities, server registration, guest auth, SSE
  class-portfolio-cors-fix.php        CORS + JSON-RPC response shaping
data/portfolio.json                   Portfolio items (12 seed entries, hand-curated)
ui/app.html                           Single-file React UI bundle
```

## Dependencies

- WordPress 6.0+
- PHP 8.0+
- [abilities-api](https://github.com/WordPress/abilities-api) plugin
- [mcp-adapter](https://github.com/WordPress/mcp-adapter) plugin (v0.4.1 or compatible)

## License

MIT
