<?php
/**
 * Site Context (AGENTS.md + CHANGELOG.md) — v2.9.5.
 *
 * The site admin writes two Markdown documents on MCP > Context:
 *   - AGENTS.md    — how this site is built and how an agent should work on it
 *   - CHANGELOG.md — what changed on the site and why (newest first)
 *
 * When the feature is switched on, a connected agent receives them FIRST, so
 * it does not have to crawl the site to learn its structure:
 *   1. `instructions` in the MCP `initialize` result (clients inject this into
 *      the model's context automatically; capped, see WSP_MCP_CONTEXT_*_CHARS)
 *   2. tool `wsp_get_site_context` — the full, uncapped documents
 *   3. MCP resources `wsp://context/agents.md` / `wsp://context/changelog.md`
 *
 * Written from the admin page, or by an agent through the opt-in tool
 * `wsp_update_site_context` (replace / append / prepend, so a large file can be
 * sent in chunks).
 *
 * Off by default. The documents are admin-authored plain text: they are never
 * rendered as HTML (admin page uses esc_textarea(); MCP output is JSON), so the
 * sanitizer normalises encoding/control characters instead of stripping tags —
 * Markdown legitimately contains `<placeholders>` and inline HTML.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Master on/off switch (default off). */
define( 'WSP_MCP_CONTEXT_ENABLED_OPTION', 'wsp_mcp_context_enabled' );
/** AGENTS.md body. */
define( 'WSP_MCP_CONTEXT_AGENTS_OPTION', 'wsp_mcp_context_agents' );
/** CHANGELOG.md body. */
define( 'WSP_MCP_CONTEXT_CHANGELOG_OPTION', 'wsp_mcp_context_changelog' );

/** Hard storage limit per document (characters). Only the pushed head is capped smaller. */
define( 'WSP_MCP_CONTEXT_MAX_CHARS', 300000 );
/** How much of AGENTS.md is pushed in `initialize` instructions. */
define( 'WSP_MCP_CONTEXT_AGENTS_PUSH_CHARS', 6000 );
/** How much of the changelog head (newest entries) is pushed in `initialize`. */
define( 'WSP_MCP_CONTEXT_CHANGELOG_PUSH_CHARS', 1500 );

/** Resource URIs. */
define( 'WSP_MCP_CONTEXT_URI_AGENTS', 'wsp://context/agents.md' );
define( 'WSP_MCP_CONTEXT_URI_CHANGELOG', 'wsp://context/changelog.md' );

/** Is the admin's master switch on? */
function wsp_mcp_context_is_enabled() {
	return (bool) get_option( WSP_MCP_CONTEXT_ENABLED_OPTION, false );
}

/**
 * Stored document.
 *
 * @param string $which 'agents' | 'changelog'.
 * @return string
 */
function wsp_mcp_context_get( $which ) {
	$option = 'changelog' === $which ? WSP_MCP_CONTEXT_CHANGELOG_OPTION : WSP_MCP_CONTEXT_AGENTS_OPTION;
	$value  = get_option( $option, '' );
	return is_string( $value ) ? $value : '';
}

/**
 * Enabled AND at least one document has content. This — not the bare switch —
 * decides whether the server advertises anything, so an empty page never
 * costs an agent a wasted round-trip.
 */
function wsp_mcp_context_is_active() {
	return wsp_mcp_context_is_enabled()
		&& ( '' !== trim( wsp_mcp_context_get( 'agents' ) ) || '' !== trim( wsp_mcp_context_get( 'changelog' ) ) );
}

/**
 * Normalise a pasted Markdown document for storage.
 *
 * @param mixed $text Raw (already wp_unslash()ed) input.
 * @param bool  $trim Trim surrounding whitespace. Off for chunked MCP writes,
 *                    where a chunk boundary can fall on a newline.
 * @return string
 */
function wsp_mcp_context_sanitize( $text, $trim = true ) {
	if ( ! is_string( $text ) ) {
		return '';
	}
	$text = wp_check_invalid_utf8( $text );
	$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
	// Drop control characters except tab and newline.
	$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
	$text = (string) $text;
	if ( $trim ) {
		$text = trim( $text );
	}
	if ( mb_strlen( $text ) > WSP_MCP_CONTEXT_MAX_CHARS ) {
		$text = mb_substr( $text, 0, WSP_MCP_CONTEXT_MAX_CHARS );
	}
	return $text;
}

/** Rough token estimate (≈4 chars/token) for the admin page. */
function wsp_mcp_context_estimate_tokens( $text ) {
	return (int) ceil( mb_strlen( (string) $text ) / 4 );
}

/**
 * Text pushed to the agent in the `initialize` result, or '' when inactive.
 * Capped: the full documents are always available from wsp_get_site_context.
 */
function wsp_mcp_context_instructions() {
	if ( ! wsp_mcp_context_is_active() ) {
		return '';
	}

	$agents    = trim( wsp_mcp_context_get( 'agents' ) );
	$changelog = trim( wsp_mcp_context_get( 'changelog' ) );
	$truncated = false;

	$out  = "SITE CONTEXT — written by this site's administrator. Read it BEFORE exploring the site with tools; ";
	$out .= "it describes how the site is built so you do not need to crawl it. Follow the rules in it.\n";

	if ( '' !== $agents ) {
		if ( mb_strlen( $agents ) > WSP_MCP_CONTEXT_AGENTS_PUSH_CHARS ) {
			$agents    = mb_substr( $agents, 0, WSP_MCP_CONTEXT_AGENTS_PUSH_CHARS );
			$truncated = true;
		}
		$out .= "\n=== AGENTS.md ===\n" . $agents . "\n";
	}

	if ( '' !== $changelog ) {
		if ( mb_strlen( $changelog ) > WSP_MCP_CONTEXT_CHANGELOG_PUSH_CHARS ) {
			$changelog = mb_substr( $changelog, 0, WSP_MCP_CONTEXT_CHANGELOG_PUSH_CHARS );
			$truncated = true;
		}
		$out .= "\n=== CHANGELOG.md (newest entries) ===\n" . $changelog . "\n";
	}

	if ( $truncated ) {
		$out .= "\n[Truncated. Call the tool wsp_get_site_context for the complete documents.]";
	} else {
		$out .= "\n[Complete. The same documents are available from the tool wsp_get_site_context.]";
	}
	return $out;
}

/**
 * Tool callback: wsp_get_site_context.
 *
 * @param array $input { file?: 'all'|'agents'|'changelog' }
 * @return array|WP_Error
 */
function wsp_execute_get_site_context( $input ) {
	$file = isset( $input['file'] ) ? sanitize_key( $input['file'] ) : 'all';
	if ( ! in_array( $file, array( 'all', 'agents', 'changelog' ), true ) ) {
		return new WP_Error( 'invalid_file', 'file must be one of: all, agents, changelog.' );
	}

	$result = array();
	if ( 'all' === $file || 'agents' === $file ) {
		$result['agents_md'] = wsp_mcp_context_get( 'agents' );
	}
	if ( 'all' === $file || 'changelog' === $file ) {
		$result['changelog_md'] = wsp_mcp_context_get( 'changelog' );
	}
	return $result;
}

/**
 * Tool callback: wsp_update_site_context.
 *
 * Writes one document. `append` / `prepend` let an agent upload a file larger
 * than it can comfortably send in one call: first chunk with `replace`, the
 * rest with `append`. Content is normalised but not trimmed, so chunk
 * boundaries survive. Over-limit writes are refused, never silently truncated,
 * so the agent knows when the stored copy would be incomplete.
 *
 * @param array $input {
 *     @type string $file    Required. 'agents' | 'changelog'.
 *     @type string $content Required. Markdown ('' with mode=replace clears the document).
 *     @type string $mode    'replace' (default) | 'append' | 'prepend'.
 *     @type bool   $enable  Optional. Also set the Site Context master switch.
 * }
 * @return array|WP_Error
 */
function wsp_execute_update_site_context( $input ) {
	$file = isset( $input['file'] ) && is_string( $input['file'] ) ? sanitize_key( $input['file'] ) : '';
	if ( ! in_array( $file, array( 'agents', 'changelog' ), true ) ) {
		return new WP_Error( 'invalid_file', 'file must be one of: agents, changelog.' );
	}
	if ( ! isset( $input['content'] ) || ! is_string( $input['content'] ) ) {
		return new WP_Error( 'invalid_content', 'content (string) is required.' );
	}
	$mode = isset( $input['mode'] ) && is_string( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'replace';
	if ( ! in_array( $mode, array( 'replace', 'append', 'prepend' ), true ) ) {
		return new WP_Error( 'invalid_mode', 'mode must be one of: replace, append, prepend.' );
	}

	// Check the raw length first: the sanitizer would otherwise truncate silently.
	$too_large = sprintf( 'The limit per document is %d characters. Nothing was saved.', WSP_MCP_CONTEXT_MAX_CHARS );
	if ( mb_strlen( $input['content'] ) > WSP_MCP_CONTEXT_MAX_CHARS ) {
		return new WP_Error( 'too_large', 'content is too long. ' . $too_large );
	}

	// MCP arguments are decoded JSON, never slashed — no wp_unslash() here.
	$chunk   = wsp_mcp_context_sanitize( $input['content'], false );
	$current = wsp_mcp_context_get( $file );
	if ( 'append' === $mode ) {
		$text = $current . $chunk;
	} elseif ( 'prepend' === $mode ) {
		$text = $chunk . $current;
	} else {
		$text = $chunk;
	}

	$length = mb_strlen( $text );
	if ( $length > WSP_MCP_CONTEXT_MAX_CHARS ) {
		return new WP_Error(
			'too_large',
			sprintf( 'Result would be %d characters. ', $length ) . $too_large
		);
	}

	$option = 'changelog' === $file ? WSP_MCP_CONTEXT_CHANGELOG_OPTION : WSP_MCP_CONTEXT_AGENTS_OPTION;
	update_option( $option, $text, false );

	if ( isset( $input['enable'] ) ) {
		update_option( WSP_MCP_CONTEXT_ENABLED_OPTION, rest_sanitize_boolean( $input['enable'] ) ? 1 : 0, false );
	}

	return array(
		'file'             => 'changelog' === $file ? 'CHANGELOG.md' : 'AGENTS.md',
		'mode'             => $mode,
		'chunk_chars'      => mb_strlen( $chunk ),
		'total_chars'      => $length,
		'max_chars'        => WSP_MCP_CONTEXT_MAX_CHARS,
		'estimated_tokens' => wsp_mcp_context_estimate_tokens( $text ),
		'sha256'           => hash( 'sha256', $text ),
		'context_enabled'  => wsp_mcp_context_is_enabled(),
		'context_active'   => wsp_mcp_context_is_active(),
		'note'             => 'wsp_get_site_context returns the new text immediately; the copy pushed on connect updates when agents reconnect.',
	);
}

/**
 * MCP resources for the non-empty documents (empty array when inactive).
 *
 * @return array[]
 */
function wsp_mcp_context_resources() {
	if ( ! wsp_mcp_context_is_active() ) {
		return array();
	}
	$resources = array();
	if ( '' !== trim( wsp_mcp_context_get( 'agents' ) ) ) {
		$resources[] = array(
			'uri'         => WSP_MCP_CONTEXT_URI_AGENTS,
			'name'        => 'AGENTS.md',
			'description' => 'Site-specific instructions for AI agents, written by the site administrator. Read first.',
			'mimeType'    => 'text/markdown',
		);
	}
	if ( '' !== trim( wsp_mcp_context_get( 'changelog' ) ) ) {
		$resources[] = array(
			'uri'         => WSP_MCP_CONTEXT_URI_CHANGELOG,
			'name'        => 'CHANGELOG.md',
			'description' => 'What changed on this site and why, newest first.',
			'mimeType'    => 'text/markdown',
		);
	}
	return $resources;
}

/**
 * Body for resources/read.
 *
 * @param string $uri Requested resource URI.
 * @return array|null `contents` entry, or null when unknown/inactive.
 */
function wsp_mcp_context_read_resource( $uri ) {
	if ( ! wsp_mcp_context_is_active() ) {
		return null;
	}
	if ( WSP_MCP_CONTEXT_URI_AGENTS === $uri ) {
		$which = 'agents';
	} elseif ( WSP_MCP_CONTEXT_URI_CHANGELOG === $uri ) {
		$which = 'changelog';
	} else {
		return null;
	}
	$text = wsp_mcp_context_get( $which );
	if ( '' === trim( $text ) ) {
		return null;
	}
	return array(
		'uri'      => $uri,
		'mimeType' => 'text/markdown',
		'text'     => $text,
	);
}
