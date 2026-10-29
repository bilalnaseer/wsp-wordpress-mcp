<?php
/**
 * Native OAuth 2.1 authorization server for "paste a URL only" Claude
 * Connectors (no config file, no manually-copied Bearer header).
 *
 * Implements just enough of RFC 8414 (AS metadata), RFC 9728 (protected
 * resource metadata), RFC 7591 (Dynamic Client Registration) and RFC 6749 +
 * PKCE (RFC 7636, S256 only) for Claude's documented `oauth_dcr` flow:
 * https://claude.com/docs/connectors/building/authentication
 *
 * Every endpoint here is matched against the raw request path on the `init`
 * hook rather than through the REST API or WordPress rewrite rules, for two
 * reasons: the two `.well-known/*` discovery documents must sit outside any
 * REST namespace (and are served at every path spelling this install can
 * reach — see maybe_dispatch()), and the token endpoint must accept
 * `application/x-www-form-urlencoded` bodies (PHP already populates $_POST
 * for that content type) rather than the REST API's JSON-only body parsing.
 * This mirrors the "match once, exit early" pattern already used by plugins
 * that serve custom root-level documents (e.g. sitemap.xml) without touching
 * rewrite rules or flush_rewrite_rules().
 *
 * Security model: public client only (no client_secret is ever issued —
 * Claude registers as a public client and authenticates with PKCE S256
 * instead). Authorization codes and refresh tokens are single-use and
 * rotated; all tokens are stored as SHA-256 hashes (see class-oauth-store.php)
 * so a database read alone is not a usable credential. An OAuth-issued
 * access token is bound to the specific WordPress user who clicked "Allow" on
 * the consent screen — tool calls then go through the *same* capability
 * checks (WSP_MCP_Auth::require_cap()) as any other logged-in user, which is
 * a stricter model than the static API key's "assume the first administrator"
 * shortcut.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Option holding the admin's explicit opt-in to the OAuth authorization server. */
define( 'WSP_MCP_OAUTH_OPTION', 'wsp_mcp_oauth_enabled' );

/**
 * Whether the site owner has switched the OAuth authorization server on.
 *
 * **Off unless an administrator explicitly enables it** (MCP > Connection).
 * This is a public, unauthenticated surface — Dynamic Client Registration, an
 * authorize endpoint that mints credentials for whoever clicks Allow, and two
 * discovery documents served at the site root — so it must never appear on a
 * site simply because the plugin was updated. An admin who wants the one-click
 * Claude Connector flow turns it on; everyone else keeps the API-key and
 * Application Password transports they already had, and the OAuth endpoints
 * 404 exactly as if this code were not present.
 *
 * @return bool
 */
function wsp_mcp_oauth_is_enabled() {
	return (bool) get_option( WSP_MCP_OAUTH_OPTION, false );
}

/**
 * The minimum capability a WordPress user must hold to authorize a connector
 * for their own account.
 *
 * Login alone is deliberately not enough. Six tools register with an empty
 * capability (`require_cap()` treats that as "any authenticated user"), and
 * `wsp_get_posts` accepts `status=all` — which returns every draft, pending
 * and scheduled post on the site with no author filter. On a site with open
 * registration (WooCommerce, membership, LMS) that would let anyone who can
 * sign up mint a token from claude.ai and read unpublished content.
 *
 * `edit_posts` is the floor: it is what "this person is site staff" means in
 * WordPress, and it is already the capability most tools here require.
 *
 * @return string A capability name; filterable for sites with custom roles.
 */
function wsp_mcp_oauth_min_capability() {
	/**
	 * Filters the capability required to approve an OAuth connector.
	 *
	 * @param string $capability Default 'edit_posts'.
	 */
	return (string) apply_filters( 'wsp_mcp_oauth_min_capability', 'edit_posts' );
}

class WSP_MCP_OAuth_Server {

	/**
	 * Token endpoint rate limit: max requests per IP within RATE_LIMIT_WINDOW
	 * seconds. Anthropic's OAuth traffic for every Connector on every site
	 * originates from one shared egress range (160.79.104.0/21 per Claude's
	 * docs), not per-end-user IPs, so this has to stay generous enough that
	 * normal connect/retry activity across unrelated users never trips it —
	 * PKCE (S256, enforced on every request) is what actually makes a code
	 * or verifier infeasible to brute-force, this is only a coarse backstop.
	 */
	const RATE_LIMIT_MAX    = 60;
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * Dynamic Client Registration is unauthenticated by design (RFC 7591 —
	 * Claude has no credential to present before it has registered), so it is
	 * the one endpoint here a stranger can write rows with. It gets its own,
	 * much tighter per-IP budget than the token endpoint: a real client
	 * registers once per connector, never in a loop. See also
	 * WSP_MCP_OAuth_Store::MAX_CLIENTS for the absolute ceiling.
	 */
	const REGISTER_RATE_LIMIT_MAX    = 5;
	const REGISTER_RATE_LIMIT_WINDOW = 600;

	/** Register the early request-path dispatcher. */
	public static function init() {
		// Nothing is routed, and no discovery document is served, until an
		// administrator opts in — see wsp_mcp_oauth_is_enabled().
		if ( ! wsp_mcp_oauth_is_enabled() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'maybe_dispatch' ), 0 );
	}

	/**
	 * @return string Scheme + host (+ non-default port) — no path. The site
	 * origin, used to turn a raw REQUEST_URI back into an absolute URL. For the
	 * OAuth issuer *identifier* use as_issuer(), which includes the base path.
	 */
	public static function issuer() {
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$port   = wp_parse_url( home_url(), PHP_URL_PORT );
		$base   = ( $scheme ? $scheme : 'https' ) . '://' . $host;
		if ( $port && ! ( 'https' === $scheme && 443 === (int) $port ) && ! ( 'http' === $scheme && 80 === (int) $port ) ) {
			$base .= ':' . $port;
		}
		return $base;
	}

	/**
	 * The OAuth issuer identifier for *this* install — origin plus home_url()'s
	 * base path, so two WordPress installs on one domain (e.g. /mcp and /test)
	 * are distinct authorization servers rather than both claiming the bare
	 * origin. On a root install this is byte-identical to issuer(), so nothing
	 * changes for the common case; on a subdirectory install it is what makes
	 * discovery resolve to the install the client is actually talking to.
	 *
	 * @return string Issuer identifier, no trailing slash.
	 */
	public static function as_issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * The protected-resource metadata URL, used in the 401 WWW-Authenticate
	 * header — the one pointer a client is guaranteed to follow verbatim
	 * (RFC 9728 §5.1), which is why it must name a URL *this* install can
	 * actually serve.
	 *
	 * It is deliberately home_url()-relative, not issuer()-relative. RFC 9728's
	 * well-known URI is anchored at the bare origin, but a WordPress install in
	 * a subdirectory never receives origin-root requests at all — Apache/nginx
	 * routes `https://example.com/.well-known/...` to the document root, not to
	 * `/test/index.php`. Advertising the origin-root URL from a subdirectory
	 * install therefore hands the client whatever else happens to own the
	 * document root (a static file, or another WordPress install running this
	 * same plugin). The client then completes OAuth against *that* server,
	 * receives a token minted from *that* database, presents it here, and gets
	 * 401 on every call — which Claude surfaces as a connector that is
	 * "connected" but has no tools available.
	 *
	 * @return string Absolute URL of this install's protected-resource metadata.
	 */
	public static function protected_resource_metadata_url() {
		// Not the RFC 9728 /.well-known/ URI: some hosts (SiteGround's shared
		// Nginx, for one) answer /.well-known/* with a 404 before WordPress ever
		// runs, which leaves ChatGPT and other clients unable to find the
		// document. RFC 9728 §5.1 lets the 401 pointer name any URL, and the
		// alias below is served by this same plugin on every host.
		return home_url( '/wsp-mcp-oauth/protected-resource' );
	}

	/**
	 * @return string home_url()'s own path component, no trailing slash ('' for a
	 * root install, e.g. '/mcp' for a site whose WordPress Address has a base path).
	 * The .well-known/* documents are served both here and (for a root install) at
	 * the bare origin; authorize/token/register live *only* under this base path,
	 * because WordPress's auth cookies are scoped to it (SITECOOKIEPATH) —
	 * putting the login-dependent /authorize endpoint outside that scope would make
	 * is_user_logged_in() invisible to it after login and loop forever.
	 */
	private static function base_path() {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	/** Current request path, with no query string and no trailing slash (root stays "/"). */
	private static function current_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}
		return $path;
	}

	/** Route the current request to a handler if its path matches one of ours. Otherwise: no-op. */
	public static function maybe_dispatch() {
		$path   = self::current_path();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		$base = self::base_path();

		// Discovery documents are matched against a list rather than a switch
		// because a root install ($base === '') collapses the base-path variants
		// onto the origin-root ones, and every client spells the lookup slightly
		// differently. Serving all the spellings this install can actually reach
		// costs one string comparison and removes a whole class of "connected but
		// no tools" failures — see protected_resource_metadata_url().
		//
		// The origin-root forms only ever arrive on a root install (a
		// subdirectory install never receives them), so listing both is safe.

		// Derived from rest_url() rather than hardcoded, so the path-insertion
		// form still matches when the REST prefix isn't the default `wp-json`.
		$resource_path = wp_parse_url( rest_url( 'wsp-mcp/v1/mcp' ), PHP_URL_PATH );
		$resource_path = is_string( $resource_path ) ? untrailingslashit( $resource_path ) : '';

		$resource_paths = array(
			'/.well-known/oauth-protected-resource',                    // RFC 9728, origin root.
			$base . '/.well-known/oauth-protected-resource',            // Reachable from a subdirectory install.
			'/.well-known/oauth-protected-resource' . $resource_path,   // RFC 9728 §3.1 path insertion.
			$base . '/wsp-mcp-oauth/protected-resource',                // Non-.well-known alias: survives hosts that 404 /.well-known/ at the web server.
		);
		$as_paths = array(
			'/.well-known/oauth-authorization-server',          // RFC 8414, origin root.
			$base . '/.well-known/oauth-authorization-server',  // RFC 8414 path appending.
			$base . '/wsp-mcp-oauth/authorization-server',      // Non-.well-known alias, same reason as above.
		);
		if ( '' !== $base ) {
			// The MCP authorization spec has clients fall back to OpenID Connect
			// Discovery *with path appending* for an issuer that carries a path —
			// which, on a subdirectory install, is the only variant that is both
			// tried by the client and reachable by us. The document we return is
			// OAuth AS metadata rather than a full OIDC one (no jwks_uri: this
			// server issues opaque tokens, not JWTs); that is what the client is
			// looking for here, and it is deliberately scoped to our own base path
			// so a root-level OpenID provider on the same domain is left alone.
			$as_paths[] = $base . '/.well-known/openid-configuration';
		}

		if ( in_array( $path, $resource_paths, true ) ) {
			self::send_json( self::protected_resource_metadata() ); // Exits.
		}
		if ( in_array( $path, $as_paths, true ) ) {
			self::send_json( self::authorization_server_metadata() ); // Exits.
		}

		switch ( $path ) {
			case $base . '/wsp-mcp-oauth/register':
				if ( 'POST' !== $method ) {
					self::send_json( array( 'error' => 'invalid_request' ), 405 );
				}
				self::handle_register();
				break;

			case $base . '/wsp-mcp-oauth/authorize':
				self::handle_authorize( $method );
				break;

			case $base . '/wsp-mcp-oauth/token':
				if ( 'POST' !== $method ) {
					self::send_json( array( 'error' => 'invalid_request' ), 405 );
				}
				self::handle_token();
				break;
		}
	}

	/* ---------- Discovery documents ---------- */

	private static function protected_resource_metadata() {
		return array(
			'resource'              => esc_url_raw( rest_url( 'wsp-mcp/v1/mcp' ) ),
			// as_issuer(), not issuer(): on a subdirectory install the bare
			// origin is a *different* authorization server (possibly another
			// site running this same plugin), and a token minted there will
			// never validate against this install's token store.
			'authorization_servers' => array( self::as_issuer() ),
			'scopes_supported'      => array( 'mcp' ),
		);
	}

	private static function authorization_server_metadata() {
		$issuer = self::as_issuer();
		return array(
			'issuer'                                => $issuer,
			// Deliberately home_url()-relative, not issuer()-relative — see base_path().
			'authorization_endpoint'                => home_url( '/wsp-mcp-oauth/authorize' ),
			'token_endpoint'                         => home_url( '/wsp-mcp-oauth/token' ),
			'registration_endpoint'                  => home_url( '/wsp-mcp-oauth/register' ),
			'response_types_supported'               => array( 'code' ),
			'grant_types_supported'                  => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'        => array( 'S256' ),
			'token_endpoint_auth_methods_supported'   => array( 'none' ),
			'scopes_supported'                        => array( 'mcp', 'offline_access' ),
		);
	}

	/* ---------- Dynamic Client Registration (RFC 7591) ---------- */

	private static function handle_register() {
		// This endpoint cannot require a credential (the caller has none yet),
		// so it is throttled per source IP and backed by an absolute row
		// ceiling. Without both, anyone on the internet can insert unbounded
		// rows into the clients table, and every registered client is a
		// potential consent-phishing identity (see render_consent_page()).
		self::enforce_rate_limit( 'register', self::REGISTER_RATE_LIMIT_MAX, self::REGISTER_RATE_LIMIT_WINDOW );

		// Opportunistically drop abandoned registrations before testing the
		// ceiling, so a burst of junk can't permanently lock out real clients.
		if ( WSP_MCP_OAuth_Store::count_clients() >= WSP_MCP_OAuth_Store::MAX_CLIENTS ) {
			WSP_MCP_OAuth_Store::prune_unused_clients();
		}
		if ( WSP_MCP_OAuth_Store::count_clients() >= WSP_MCP_OAuth_Store::MAX_CLIENTS ) {
			self::send_json( array(
				'error'             => 'invalid_client_metadata',
				'error_description' => 'This server is not accepting new client registrations right now.',
			), 429 );
		}

		$raw  = file_get_contents( 'php://input' );
		$body = json_decode( (string) $raw, true );
		if ( ! is_array( $body ) ) {
			self::send_json( array( 'error' => 'invalid_client_metadata', 'error_description' => 'Body must be JSON.' ), 400 );
		}

		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		$redirect_uris = array_values( array_filter( $redirect_uris, 'is_string' ) );
		$redirect_uris = array_values( array_filter( array_map( 'esc_url_raw', $redirect_uris ) ) );

		if ( empty( $redirect_uris ) || count( $redirect_uris ) > 10 ) {
			self::send_json( array( 'error' => 'invalid_redirect_uri', 'error_description' => 'At least one, at most ten, valid redirect_uris are required.' ), 400 );
		}
		// Canonicalize now (see canonicalize_redirect_uri()) and store the
		// canonical form, so every later exact-match comparison — at the
		// authorize and token endpoints — is comparing like with like instead
		// of trusting whatever casing/port/userinfo variant showed up first.
		$canonical_uris = array();
		foreach ( $redirect_uris as $uri ) {
			$scheme = wp_parse_url( $uri, PHP_URL_SCHEME );
			$host   = wp_parse_url( $uri, PHP_URL_HOST );
			$is_https = 'https' === $scheme;
			$is_loopback = in_array( $scheme, array( 'http' ), true ) && in_array( $host, array( 'localhost', '127.0.0.1' ), true );
			if ( ! $is_https && ! $is_loopback ) {
				self::send_json( array( 'error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris must be HTTPS (loopback http://localhost is also accepted).' ), 400 );
			}
			$canonical = self::canonicalize_redirect_uri( $uri );
			if ( null === $canonical ) {
				self::send_json( array( 'error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris must not contain userinfo (user:pass@) or a fragment.' ), 400 );
			}
			$canonical_uris[] = $canonical;
		}
		$redirect_uris = array_values( array_unique( $canonical_uris ) );

		$client_name = isset( $body['client_name'] ) ? sanitize_text_field( wp_unslash( $body['client_name'] ) ) : 'MCP client';
		$client_name = mb_substr( $client_name, 0, 255 );

		$client_id = WSP_MCP_OAuth_Store::register_client( $client_name, $redirect_uris );

		self::send_json( array(
			'client_id'                  => $client_id,
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'client_id_issued_at'        => time(),
		), 201 );
	}

	/* ---------- Authorization endpoint (login + consent) ---------- */

	private static function handle_authorize( $method ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this is the OAuth authorize request itself; params are validated below, and the consent POST is nonce-protected separately.
		$params = ( 'POST' === $method ) ? wp_unslash( $_POST ) : wp_unslash( $_GET );

		$client_id     = isset( $params['client_id'] ) ? sanitize_text_field( $params['client_id'] ) : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? esc_url_raw( $params['redirect_uri'] ) : '';
		$state         = isset( $params['state'] ) ? sanitize_text_field( $params['state'] ) : '';
		$response_type = isset( $params['response_type'] ) ? sanitize_text_field( $params['response_type'] ) : '';
		$challenge     = isset( $params['code_challenge'] ) ? sanitize_text_field( $params['code_challenge'] ) : '';
		$challenge_m   = isset( $params['code_challenge_method'] ) ? sanitize_text_field( $params['code_challenge_method'] ) : '';
		$scope         = isset( $params['scope'] ) ? sanitize_text_field( $params['scope'] ) : 'mcp';

		$client = $client_id ? WSP_MCP_OAuth_Store::get_client( $client_id ) : null;

		// Canonicalize both sides before comparing — see canonicalize_redirect_uri().
		// Registered URIs are stored canonical already (handle_register()), but
		// re-canonicalizing here is idempotent and also covers any rows written
		// before this check existed, with no migration needed.
		$redirect_uri_canonical = ( '' !== $redirect_uri ) ? self::canonicalize_redirect_uri( $redirect_uri ) : null;
		$registered_canonical   = $client ? array_filter( array_map( array( __CLASS__, 'canonicalize_redirect_uri' ), $client['redirect_uris'] ) ) : array();

		// Everything up to and including the redirect_uri match must be verified
		// BEFORE we ever redirect anywhere — an unvalidated redirect_uri is a
		// classic OAuth open-redirect vulnerability, so failures here render a
		// plain error page instead of bouncing the browser anywhere.
		if ( ! $client || null === $redirect_uri_canonical || ! in_array( $redirect_uri_canonical, $registered_canonical, true ) ) {
			self::render_error_page( 'This connector is not registered, or its redirect address does not match what was registered.' );
		}
		// From here on, use the canonical form exclusively — it's the value
		// that was actually matched against the registered allowlist.
		$redirect_uri = $redirect_uri_canonical;

		if ( 'code' !== $response_type ) {
			self::redirect_with_error( $redirect_uri, $state, 'unsupported_response_type' );
		}
		// state is client-optional under OAuth 2.1: PKCE (required above, and
		// enforced on every request Claude makes) already binds the callback
		// to the browser session that started it, so a public client isn't
		// required to also send state for CSRF protection. Cap the length
		// only to stop an absurdly long value being reflected back verbatim.
		if ( mb_strlen( $state ) > 512 ) {
			self::redirect_with_error( $redirect_uri, $state, 'invalid_request', 'state is too long.' );
		}
		// RFC 7636 §4.2: code_challenge is 43-128 characters of base64url
		// (unpadded). A standard S256-of-SHA256 digest is always exactly 43,
		// but the spec's own bound is the range below — match that rather
		// than a narrower assumption that would reject an otherwise-valid
		// value from a conformant client.
		if ( '' === $challenge || 'S256' !== $challenge_m || ! preg_match( '/^[A-Za-z0-9\-_]{43,128}$/', $challenge ) ) {
			self::redirect_with_error( $redirect_uri, $state, 'invalid_request', 'PKCE code_challenge must be a 43-128 character S256 (base64url) value.' );
		}
		// Narrow the requested scope to what this server actually advertises
		// (see authorization_server_metadata()) rather than granting an
		// unrecognized one — but never fail the whole authorization over it;
		// RFC 6749 §3.3 explicitly allows an AS to grant a scope narrower
		// than what was requested instead of erroring the grant.
		$scope = self::validate_scope( $scope );

		// Not logged in yet: send to WordPress's own login screen and come right
		// back here with the exact same query string once authenticated. Reuses
		// the literal request URI the browser is already on (issuer() + raw
		// REQUEST_URI) rather than rebuilding it from home_url(), so this is
		// correct with or without a base path and can't accidentally double up
		// a prefix like /mcp.
		if ( ! is_user_logged_in() ) {
			$current_request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/wsp-mcp-oauth/authorize'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$return_to = self::issuer() . $current_request;
			nocache_headers();
			wp_safe_redirect( wp_login_url( $return_to ) );
			exit;
		}

		// Logged in, but is this account allowed to hand an AI client a
		// credential at all? Six tools register with an empty capability, and
		// `wsp_get_posts` with status=all returns every draft on the site, so
		// "any subscriber who can register" is too low a bar to mint a token.
		// Checked before the consent screen renders *and* again on the POST,
		// since both paths pass through here.
		$min_cap = wsp_mcp_oauth_min_capability();
		if ( '' !== $min_cap && ! current_user_can( $min_cap ) ) {
			self::render_error_page(
				__( 'Your WordPress account is not permitted to connect an AI client to this site. Ask an administrator to grant your account the required permission, or to connect using their own account.', 'wsp-mcp-ai-agents-connector' )
			);
		}

		$action = isset( $params['wsp_mcp_oauth_action'] ) ? sanitize_text_field( $params['wsp_mcp_oauth_action'] ) : '';

		if ( 'POST' === $method && '' !== $action ) {
			check_admin_referer( 'wsp_mcp_oauth_consent_' . $client_id );

			if ( 'deny' === $action ) {
				self::redirect_with_error( $redirect_uri, $state, 'access_denied' );
			}

			$code = WSP_MCP_OAuth_Store::create_code(
				$client_id,
				get_current_user_id(),
				$redirect_uri,
				$challenge,
				$scope
			);
			$target = add_query_arg( array( 'code' => $code, 'state' => $state ), $redirect_uri );
			// This response carries a single-use authorization code in its
			// Location header — it must never be cached (by a CDN/WAF such as
			// Cloudflare, a page-cache plugin, or a shared proxy), or every
			// request that ever hits a cached copy would replay the same
			// already-consumed code and fail token exchange with invalid_grant,
			// no matter who's connecting or when.
			nocache_headers();
			// wp_redirect(), not wp_safe_redirect(): the target is the client's own
			// callback (e.g. https://claude.ai/api/mcp/auth_callback), a different
			// host by design — wp_safe_redirect() would block it as "unsafe". The
			// security control here is the exact-match check against $client's
			// registered redirect_uris above, which is stricter than the generic
			// same-host allowlist wp_safe_redirect() enforces.
			wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		self::render_consent_page( $client, $client_id, $redirect_uri, $state, $challenge, $challenge_m, $scope );
	}

	/**
	 * Minimal, escaped consent screen. No theme dependency — this runs before
	 * template_redirect.
	 *
	 * What the person approving this needs to see, and why:
	 *
	 * - **The destination host, not just a name.** `client_name` is whatever
	 *   the client sent to the (unauthenticated) registration endpoint — it is
	 *   a self-assigned label, not an identity, and anyone can register a
	 *   client calling itself "WordPress Security Update". Showing only that
	 *   name turns this page into a one-click account-handover: register a
	 *   client pointing at your own callback, send a logged-in editor the
	 *   /authorize link, and their "Allow" mints a token bound to their user.
	 *   Naming the host the code will actually be sent to is what lets a human
	 *   catch that, so the host is displayed prominently and the name is
	 *   explicitly flagged as unverified.
	 * - **It must not be frameable.** This page renders on `init`, outside
	 *   wp-admin, so WordPress's own admin framing protection never applies to
	 *   it. Without the headers below, the Allow button can be overlaid in an
	 *   invisible iframe and clickjacked, which skips the phishing step
	 *   entirely.
	 */
	private static function render_consent_page( $client, $client_id, $redirect_uri, $state, $challenge, $challenge_m, $scope ) {
		nocache_headers();
		self::send_frame_protection_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$site_name   = esc_html( get_bloginfo( 'name' ) );
		$client_name = esc_html( $client['client_name'] );
		$user        = wp_get_current_user();
		$user_label  = esc_html( $user->user_login );
		$redirect_host = wp_parse_url( $redirect_uri, PHP_URL_HOST );
		$redirect_host = is_string( $redirect_host ) ? $redirect_host : $redirect_uri;
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__( 'Authorize access', 'wsp-mcp-ai-agents-connector' ); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;margin:0;padding:40px 20px;color:#1d2327}
				.box{max-width:420px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
				h1{font-size:18px;margin:0 0 6px}
				p{font-size:13.5px;line-height:1.6;color:#3c434a}
				.who{font-size:12.5px;color:#646970;margin-bottom:20px}
				.dest{margin:18px 0 0;padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px}
				.dest-l{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin-bottom:4px}
				.dest-h{font-size:14px;font-weight:700;word-break:break-all;color:#1d2327}
				.dest-u{display:block;font-size:11.5px;color:#646970;word-break:break-all;margin-top:4px}
				.warn{margin:14px 0 0;padding:11px 14px;background:#fcf9e8;border:1px solid #f0e6b2;border-radius:6px;font-size:12.5px;line-height:1.55;color:#674f00}
				.actions{display:flex;gap:10px;margin-top:24px}
				button{flex:1;padding:10px 16px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;border:1px solid #dcdcde}
				.allow{background:#0073aa;color:#fff;border-color:#0073aa}
				.deny{background:#fff;color:#3c434a}
			</style>
		</head>
		<body>
			<div class="box">
				<h1><?php echo esc_html( sprintf( /* translators: %s: connecting client name */ __( '%s wants to connect', 'wsp-mcp-ai-agents-connector' ), $client_name ) ); ?></h1>
				<p class="who"><?php echo esc_html( sprintf( /* translators: %s: WordPress username */ __( 'Signed in as %s', 'wsp-mcp-ai-agents-connector' ), $user_label ) ); ?></p>
				<p><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'This will let it read and act on %s through the MCP tools your account is allowed to use.', 'wsp-mcp-ai-agents-connector' ), $site_name ) ); ?></p>

				<div class="dest">
					<span class="dest-l"><?php esc_html_e( 'Access will be sent to', 'wsp-mcp-ai-agents-connector' ); ?></span>
					<span class="dest-h"><?php echo esc_html( $redirect_host ); ?></span>
					<span class="dest-u"><?php echo esc_html( $redirect_uri ); ?></span>
				</div>

				<p class="warn">
					<?php esc_html_e( 'Only click Allow if you started this yourself and you recognize the address above. The application\'s name is supplied by the application itself and is not verified by this site — anyone can choose one.', 'wsp-mcp-ai-agents-connector' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( home_url( '/wsp-mcp-oauth/authorize' ) ); ?>">
					<?php wp_nonce_field( 'wsp_mcp_oauth_consent_' . $client_id ); ?>
					<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
					<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
					<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
					<input type="hidden" name="response_type" value="code">
					<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $challenge ); ?>">
					<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $challenge_m ); ?>">
					<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
					<div class="actions">
						<button type="submit" name="wsp_mcp_oauth_action" value="deny" class="deny"><?php esc_html_e( 'Deny', 'wsp-mcp-ai-agents-connector' ); ?></button>
						<button type="submit" name="wsp_mcp_oauth_action" value="allow" class="allow"><?php esc_html_e( 'Allow', 'wsp-mcp-ai-agents-connector' ); ?></button>
					</div>
				</form>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Canonicalize a redirect_uri for reliable exact-match comparison against
	 * the client's registered allowlist. Lowercases scheme/host, drops a
	 * redundant default port, and — crucially — refuses (returns null) any
	 * URI carrying userinfo (`user:pass@host`) or a fragment, since both are
	 * classic tricks for making two URIs look equal to a naive parser while
	 * actually pointing (or appearing to point, to a phishing victim) somewhere
	 * else. Path and query are left byte-for-byte as-is; per RFC 3986 they are
	 * case-sensitive and not safe to normalize.
	 *
	 * @param string $uri Raw redirect_uri.
	 * @return string|null Canonical form, or null if structurally invalid/unsafe.
	 */
	private static function canonicalize_redirect_uri( $uri ) {
		$parts = wp_parse_url( (string) $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['fragment'] ) ) {
			return null;
		}
		$scheme       = strtolower( $parts['scheme'] );
		$host         = strtolower( $parts['host'] );
		$port         = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$default_port = ( 'https' === $scheme ) ? 443 : ( ( 'http' === $scheme ) ? 80 : 0 );
		$authority    = $host . ( ( $port && $port !== $default_port ) ? ':' . $port : '' );
		$path         = isset( $parts['path'] ) ? $parts['path'] : '';
		$query        = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $scheme . '://' . $authority . $path . $query;
	}

	/**
	 * Narrow a requested (space-separated) scope string to this server's
	 * supported scopes (see authorization_server_metadata()). Never fails —
	 * an unrecognized requested scope is simply dropped rather than causing
	 * the whole authorization to be rejected, per RFC 6749 §3.3.
	 *
	 * @param string $scope_raw Raw scope string from the request.
	 * @return string Normalized, deduplicated, supported-only scope string
	 *                (falls back to 'mcp' if nothing requested matched).
	 */
	private static function validate_scope( $scope_raw ) {
		$allowed   = array( 'mcp', 'offline_access' );
		$requested = array_values( array_filter( explode( ' ', trim( (string) $scope_raw ) ), 'strlen' ) );
		$granted   = array_values( array_intersect( array_unique( $requested ), $allowed ) );
		return empty( $granted ) ? 'mcp' : implode( ' ', $granted );
	}

	/**
	 * Refuse to be rendered inside a frame. Both headers are sent: CSP
	 * `frame-ancestors` is the modern control and is what browsers actually
	 * honour, X-Frame-Options is kept for older clients and because security
	 * scanners and the WordPress.org review look for it. Applied to every HTML
	 * page this server emits — the consent screen because its Allow button is
	 * the clickjacking target, the error page because it is reachable with
	 * attacker-chosen text.
	 */
	private static function send_frame_protection_headers() {
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}

	private static function render_error_page( $message ) {
		nocache_headers();
		self::send_frame_protection_headers();
		status_header( 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Authorization error', 'wsp-mcp-ai-agents-connector' ) . '</title></head><body style="font-family:sans-serif;padding:40px;"><h1>' . esc_html__( 'Authorization error', 'wsp-mcp-ai-agents-connector' ) . '</h1><p>' . esc_html( $message ) . '</p></body></html>';
		exit;
	}

	/**
	 * Every call site reaches here only after $redirect_uri has already been
	 * matched exactly against the registered client's redirect_uris (see
	 * handle_authorize()), so — like the success path above — wp_redirect() is
	 * used deliberately instead of wp_safe_redirect(), which would otherwise
	 * block a redirect back to the client's own (different-host) callback.
	 */
	private static function redirect_with_error( $redirect_uri, $state, $error, $description = '' ) {
		if ( '' === $redirect_uri ) {
			self::render_error_page( $description ? $description : $error );
		}
		$args = array( 'error' => $error, 'state' => $state );
		if ( $description ) {
			$args['error_description'] = $description;
		}
		nocache_headers();
		wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/* ---------- Token endpoint (RFC 6749 + PKCE) ---------- */

	private static function handle_token() {
		// Brute-forcing a PKCE verifier or refresh token is an offline-guessing
		// attack made online only by this endpoint — throttle per source IP
		// before doing any real work, regardless of which grant is requested.
		self::enforce_rate_limit();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token endpoint is a machine-to-machine OAuth grant exchange, authenticated by the authorization code / refresh token itself, not a WP nonce.
		$grant_type = isset( $_POST['grant_type'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_type'] ) ) : '';

		if ( 'authorization_code' === $grant_type ) {
			self::token_exchange_code();
		} elseif ( 'refresh_token' === $grant_type ) {
			self::token_refresh();
		} else {
			self::send_json( array( 'error' => 'unsupported_grant_type' ), 400 );
		}
	}

	private static function token_exchange_code() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see handle_token().
		$code          = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$code_verifier = isset( $_POST['code_verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['code_verifier'] ) ) : '';

		if ( '' === $code || '' === $code_verifier ) {
			self::send_json( array( 'error' => 'invalid_request' ), 400 );
		}
		// RFC 7636 §4.1: code_verifier must be 43-128 characters from the
		// unreserved character set. Reject malformed values before touching
		// the store, rather than letting them fall through to a generic PKCE
		// mismatch.
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $code_verifier ) ) {
			self::send_json( array( 'error' => 'invalid_request', 'error_description' => 'code_verifier must be 43-128 characters from the unreserved character set.' ), 400 );
		}

		// The code was stored against the canonical redirect_uri (see
		// handle_authorize()) — canonicalize the one presented here the same
		// way before comparing, so equivalent-but-differently-formatted URIs
		// (case, default port, etc.) don't spuriously fail this check.
		$redirect_uri_canonical = ( '' !== $redirect_uri ) ? self::canonicalize_redirect_uri( $redirect_uri ) : null;

		$row = WSP_MCP_OAuth_Store::consume_code( $code );
		if ( ! $row || $row['client_id'] !== $client_id || null === $redirect_uri_canonical || $row['redirect_uri'] !== $redirect_uri_canonical ) {
			self::send_json( array( 'error' => 'invalid_grant' ), 400 );
		}
		if ( ! self::pkce_matches( $code_verifier, $row['code_challenge'] ) ) {
			self::send_json( array( 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.' ), 400 );
		}

		$tokens = WSP_MCP_OAuth_Store::issue_tokens( $client_id, (int) $row['user_id'], $row['scope'] );
		self::send_json( array(
			'access_token'  => $tokens['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $tokens['expires_in'],
			'refresh_token' => $tokens['refresh_token'],
			'scope'         => $row['scope'],
		) );
	}

	private static function token_refresh() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see handle_token().
		$refresh_token = isset( $_POST['refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['refresh_token'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

		if ( '' === $refresh_token || '' === $client_id ) {
			self::send_json( array( 'error' => 'invalid_request' ), 400 );
		}

		$fresh = WSP_MCP_OAuth_Store::rotate_refresh_token( $refresh_token, $client_id );
		if ( ! $fresh ) {
			// RFC 6749-compliant code for an unusable refresh token, per Claude's
			// documented refresh-failure requirement (invalid_grant, not a custom code).
			self::send_json( array( 'error' => 'invalid_grant' ), 400 );
		}

		self::send_json( array(
			'access_token'  => $fresh['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $fresh['expires_in'],
			'refresh_token' => $fresh['refresh_token'],
			'scope'         => $fresh['scope'],
		) );
	}

	/**
	 * Throttle one endpoint per source IP using a transient counter. Sends a
	 * 429 (with Retry-After) and exits once the caller has made more than $max
	 * requests to that endpoint within $window seconds.
	 *
	 * Each $bucket keeps its own counter, so the generous token-endpoint
	 * budget (Anthropic's whole OAuth fleet shares one egress range) cannot be
	 * spent by, or spend, the deliberately tight registration budget.
	 *
	 * Fails open (no throttling) only when the IP genuinely cannot be
	 * determined, so this can never wedge every client behind one shared
	 * proxy IP into indefinitely rejecting everyone else — see client_ip().
	 *
	 * @param string $bucket Endpoint identifier, e.g. 'token' or 'register'.
	 * @param int    $max    Max requests permitted in the window.
	 * @param int    $window Window length in seconds.
	 */
	private static function enforce_rate_limit( $bucket = 'token', $max = self::RATE_LIMIT_MAX, $window = self::RATE_LIMIT_WINDOW ) {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return;
		}
		$key   = 'wsp_mcp_oauth_rl_' . md5( $bucket . '|' . $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			nocache_headers();
			header( 'Retry-After: ' . (int) $window );
			self::send_json( array( 'error' => 'slow_down', 'error_description' => 'Too many requests. Please retry later.' ), 429 );
		}
		// First hit in the window sets the TTL; later hits just increment,
		// so the window doesn't keep sliding forward on every request.
		if ( 0 === $count ) {
			set_transient( $key, 1, $window );
		} else {
			set_transient( $key, $count + 1, $window );
		}
	}

	/**
	 * Best-effort client IP for rate limiting. Only the direct connection
	 * (REMOTE_ADDR) is trusted — proxy headers (X-Forwarded-For, etc.) are
	 * attacker-controlled and would let an attacker spoof a fresh IP on every
	 * request to bypass the limiter entirely.
	 *
	 * @return string A validated IPv4/IPv6 address, or '' if unavailable.
	 */
	private static function client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** RFC 7636 S256 PKCE verification. */
	private static function pkce_matches( $code_verifier, $code_challenge ) {
		if ( '' === $code_verifier || '' === $code_challenge ) {
			return false;
		}
		$hash   = hash( 'sha256', $code_verifier, true );
		$b64url = rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
		return hash_equals( $code_challenge, $b64url );
	}

	/* ---------- Response helper ---------- */

	private static function send_json( array $data, $status = 200 ) {
		// Discards any stray output another active plugin/theme printed
		// earlier on this request (see includes/response-guard.php) so it
		// can never precede the JSON body below, and so the header() calls
		// right above never hit "headers already sent" because of it.
		wsp_mcp_output_guard_flush();
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data );
		exit;
	}
}
