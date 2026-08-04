# GeoDirectory Playground tests

The focused integration suite runs against an ephemeral WordPress install and
a real GeoDirectory checkout. GeoDirectory is mounted at runtime and is not
copied or vendored into this repository. Set the first variable to a local
GeoDirectory plugin directory (tested with GeoDirectory 2.8.169).

From the repository root on the current Windows development environment:

```powershell
$geoDirectoryPlugin = 'C:\path\to\geodirectory'

npx.cmd --yes @wp-playground/cli@latest php `
  --blueprint=.\tests\geodirectory-blueprint.json `
  --mount-dir '.' '/workspace' `
  --mount-dir $geoDirectoryPlugin '/wordpress/wp-content/plugins/geodirectory' `
  --mount-dir '.\wsp-mcp-ai-agents-connector' '/wordpress/wp-content/plugins/wsp-mcp-ai-agents-connector' `
  -- '/workspace/tests/geodirectory-playground.php'
```

The test covers the allowlist, sanitization and validation, batch cap and
per-row continuation, dry-run behavior, duplicate reasons and coordinate
threshold, post-type checks, capability guards, normalized search results,
and draft-to-publish updates through GeoDirectory's own REST controller.
