# Status — Matt Artz Portfolio MCP

**Last updated:** 2026-04-19
**State:** Paused by user. Working, but with unresolved client-side rendering issues in Claude Desktop.

## Goal (unchanged)

A single URL on Matt's Anthropic PM Research application resume. The URL is an MCP endpoint. Reviewers paste it into Claude Desktop and interact with Matt's portfolio through Claude. No browser-facing landing page. No static files. The endpoint is the artifact.

**Target URL:** `https://www.mattartz.me/wp-json/mcp/matt-artz-portfolio`

## What exists right now

### Deployed and working server-side

- **WordPress plugin:** `matt-artz-portfolio-mcp` v0.1.0, active on mattartz.me
- **MCP endpoint:** `https://www.mattartz.me/wp-json/mcp/matt-artz-portfolio` responds 200 in about 400ms
- **Two tools:**
  - `portfolio-open`: has `_meta.ui.resourceUri` annotation per MCP Apps 2026-01-26 spec; intended to render the React UI in a sandboxed iframe
  - `portfolio-query`: text-only JSON search with filter args (type, theme, year range, free-text)
- **One resource:** `ui://matt-artz-portfolio/app.html` — 520KB single-file React bundle, `text/html;profile=mcp-app`
- **Full MCP 2025-06-18 Streamable HTTP:** initialize, tools/list, tools/call, resources/list, resources/read, GET SSE interceptor, session headers
- **CORS hardening:** `Mcp-Session-Id` exposed and allowed, credentials stripped, all per what we learned on KGH
- **Guest user auto-auth, session cap raised to 1000**

### GitHub

- Public repo: [github.com/MattArtzAnthro/matt-artz-portfolio-mcp](https://github.com/MattArtzAnthro/matt-artz-portfolio-mcp)
- MIT licensed, no homepage link (removed after we deleted the landing page)
- README describes install paths and architecture

### Local configs

Both added today in this session:

```
~/.claude.json                          Claude Code CLI: both matt-artz-portfolio and matt-artz-kg registered, ✓ Connected
~/Library/Application Support/Claude/   Claude Desktop: both servers via mcp-remote stdio proxy
  claude_desktop_config.json
```

Claude Desktop config uses the stdio proxy pattern because the installed version of Claude Desktop does not accept `"type": "http"` in local `mcpServers`:

```json
"matt-artz-portfolio": {
  "command": "npx",
  "args": ["-y", "mcp-remote", "https://www.mattartz.me/wp-json/mcp/matt-artz-portfolio"]
}
```

## What is broken

### 1. claude.ai web Custom Connector — Anthropic backend issue

Symptom: "Couldn't reach the MCP server. If this persists, share this reference with support: `ofid_*`."

Diagnosis: zero inbound traffic reaches our server during claude.ai validation. Confirmed via debug mu-plugin across multiple attempts. Three different ofid references logged:
- `ofid_7c5adc5c17c68908`
- `ofid_b549906a3fbb4760`
- `ofid_0c31131fb9811cb0`

This is on Anthropic's side, not ours. The same URL works from every other MCP client we tested (Claude Code CLI, curl, mcp-remote proxy). **Action item:** email Anthropic support with the ofids.

### 2. Claude Desktop local connector — tool discovery

Symptom: Connector panel says "matt-artz-portfolio — This connector has no tools available" even though the MCP server log clearly shows a successful tools/list handshake returning both tools with full schemas.

Root cause identified: `inputSchema.properties` on `portfolio-open` was serializing as `[]` (array) instead of `{}` (object) because my `Portfolio_CORS_Fix::inject_tool_meta()` filter does `json_decode(..., true)` for mutation, which flattens empty PHP `stdClass` instances into empty PHP arrays, which re-encode as `[]` rather than `{}`. That is invalid JSON Schema; strict validators on the client side reject the tool and the UI shows zero tools.

**Fix deployed in this session:** the filter now casts empty `inputSchema.properties` back to `stdClass` before `set_data`. Verified by curl that the response now has `"properties": {}`.

**Not yet verified:** whether Claude Desktop accepts the fixed schema. Matt paused before doing the Cmd+Q + restart cycle.

### 3. Potential secondary issue — `"type": "action"` field

mcp-adapter injects a non-standard `"type": "action"` field into each tool description. MCP 2025-06-18 spec does not define `type` on tools. Some strict clients may reject unknown fields. If the properties fix alone does not unblock Claude Desktop, strip `type` from the response in `Portfolio_CORS_Fix::inject_tool_meta()`.

## Resume the work: checklist

When we pick this up again, start here:

1. **Verify Claude Desktop now shows the tools.**
   ```
   Cmd+Q Claude Desktop (not just close)
   Reopen
   Settings > Connectors (or the composer tool icon) > check matt-artz-portfolio shows 2 tools
   ```

2. **If tools still absent, strip the "type" field too.**
   In `includes/class-portfolio-cors-fix.php`, inside the `foreach ( $tools_ref as &$tool )` loop, after the existing mutations, add:
   ```php
   unset( $tool['type'] );
   ```
   Rebuild zip, redeploy, restart Claude Desktop, retry.

3. **Once tools load, test the MCP App iframe render.**
   In a fresh Claude Desktop conversation, prompt:
   > Use the portfolio-open tool from matt-artz-portfolio to open my portfolio.

   Expected: Claude calls the tool, Claude Desktop fetches `ui://matt-artz-portfolio/app.html`, the React UI renders as a sandboxed iframe inline in the conversation.

4. **If the iframe renders but data is placeholder:**
   Populate real content. Edit `~/Documents/Claude Code/Projects/Matt Artz Website/Plugins/matt-artz-portfolio-mcp/data/portfolio.json` to replace the 12 seed items with actual roles, projects, publications, talks, teaching, podcasts, service. Rebuild the React bundle:
   ```
   cd ~/Documents/Claude\ Code/Projects/Portfolio
   # Edit src/data/portfolio.json to match the plugin's data/portfolio.json
   npm run build
   cp dist/mcp-app.html "../Matt Artz Website/Plugins/matt-artz-portfolio-mcp/ui/app.html"
   cd "../Matt Artz Website/Plugins/matt-artz-portfolio-mcp"
   zip -rq ../matt-artz-portfolio-mcp.zip . -x "*.DS_Store" ".git*"
   scp ../matt-artz-portfolio-mcp.zip siteground:~/
   ssh siteground "cd ~/www/mattartz.me/public_html && wp plugin install ~/matt-artz-portfolio-mcp.zip --force"
   git add . && git commit -m "Content: populate portfolio items" && git push
   ```

5. **Consider whether this is even the right artifact.**
   Before pushing further, honest question: is the MCP App the best Anthropic-application artifact, or have we over-indexed on the technical flex? Alternatives include:
   - A simple public static resume page (lowest friction, less novel)
   - An npm stdio wrapper published as `@mattartz/portfolio-mcp` (different install path, bypasses all HTTP connector issues)
   - A completely different demonstration that better showcases PM Research thinking

## Key paths

- **Plugin source:** `~/Documents/Claude Code/Projects/Matt Artz Website/Plugins/matt-artz-portfolio-mcp/`
- **React UI source:** `~/Documents/Claude Code/Projects/Portfolio/`
- **GitHub:** [github.com/MattArtzAnthro/matt-artz-portfolio-mcp](https://github.com/MattArtzAnthro/matt-artz-portfolio-mcp)
- **MCP endpoint:** `https://www.mattartz.me/wp-json/mcp/matt-artz-portfolio`
- **Claude Desktop config:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Claude Code config:** `~/.claude.json`
- **Claude Desktop MCP logs:** `~/Library/Logs/Claude/mcp-server-matt-artz-portfolio.log`

## Decisions logged

- MCP endpoint is the artifact. No browser-facing page. No static file under `/wp-content/uploads/portfolio/`. Both deleted in this session.
- The MCP App UI bundle is served only as a `ui://` resource, not web-reachable. Invisible to crawlers.
- Plugin repo at `MattArtzAnthro/matt-artz-portfolio-mcp` (Matt's GitHub username), MIT, homepage cleared.
- `mcp-remote` stdio proxy used for Claude Desktop because Desktop's bundled MCP client version does not accept `"type": "http"` direct.

## Session timeline

- Built Portfolio Node+Fly scaffold first (wrong direction per Matt's preference for WP-native)
- Pivoted to WordPress plugin mirroring KGH architecture
- Shipped server, tools, resource, plus two-step response-shaping filter to work around mcp-adapter v0.4.1 gaps (`_meta` passthrough, resource content callback)
- Built /portfolio/ landing page, then deleted it when Matt clarified the endpoint itself is the artifact
- Pivoted Claude Desktop config from HTTP to stdio-proxy when the direct HTTP config was rejected by Claude Desktop
- Diagnosed "no tools available" as JSON Schema validation failure on empty `properties`; fix deployed but not yet verified with a Claude Desktop restart
