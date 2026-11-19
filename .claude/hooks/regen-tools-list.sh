#!/bin/sh
# PostToolUse hook: when a tool/registry PHP file is edited, regenerate docs/tools-list.md.
f=$(php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["tool_input"]["file_path"] ?? "";')
case "$f" in
  */wsp-mcp-ai-agents-connector/includes/registry.php|*/wsp-mcp-ai-agents-connector/includes/tools/*.php|*/wsp-mcp-ai-agents-connector/includes/abilities/*.php) ;;
  *) exit 0 ;;
esac
cd "$CLAUDE_PROJECT_DIR" || exit 0
out=$(php bin/generate-tools-list.php 2>&1) || { echo "tools-list regeneration failed: $out" >&2; exit 2; }
echo "$out"
