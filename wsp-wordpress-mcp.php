<?php
/**
 * Plugin Name: WebSensePro MCP Abilities
 * Description: Exposes WordPress content to Claude AI via MCP with full read/write control. Manage all abilities from Settings > WSP MCP.
 * Version: 1.1.0
 * Author: WebSensePro
 * Author URI: https://websensepro.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSP_MCP_OPTION', 'wsp_mcp_abilities' );

// ─────────────────────────────────────────────
// ABILITY REGISTRY
// ─────────────────────────────────────────────
function wsp_mcp_ability_registry() {
    return array(
        // POSTS
        'wsp/get-posts'    => array( 'label' => 'Read Posts',    'description' => 'List published blog posts (title, URL, date, excerpt, categories, tags).', 'group' => 'Posts',    'access' => 'read',  'default' => true  ),
        'wsp/create-post'  => array( 'label' => 'Create Post',   'description' => 'Create a new blog post (title, content, status, categories, tags, slug).', 'group' => 'Posts',    'access' => 'write', 'default' => false ),
        'wsp/update-post'  => array( 'label' => 'Update Post',   'description' => 'Update an existing post by ID.',                                            'group' => 'Posts',    'access' => 'write', 'default' => false ),
        'wsp/delete-post'  => array( 'label' => 'Delete Post',   'description' => 'Move a post to trash by ID.',                                               'group' => 'Posts',    'access' => 'write', 'default' => false ),
        // PAGES
        'wsp/get-pages'    => array( 'label' => 'Read Pages',    'description' => 'List published pages (title, URL, parent, status).',                        'group' => 'Pages',    'access' => 'read',  'default' => true  ),
        'wsp/create-page'  => array( 'label' => 'Create Page',   'description' => 'Create a new WordPress page (title, content, status, parent, slug).',       'group' => 'Pages',    'access' => 'write', 'default' => false ),
        'wsp/update-page'  => array( 'label' => 'Update Page',   'description' => 'Update an existing page by ID.',                                            'group' => 'Pages',    'access' => 'write', 'default' => false ),
        'wsp/delete-page'  => array( 'label' => 'Delete Page',   'description' => 'Move a page to trash by ID.',                                               'group' => 'Pages',    'access' => 'write', 'default' => false ),
        // TAXONOMY
        'wsp/get-categories'    => array( 'label' => 'Read Categories',  'description' => 'List all post categories with IDs, slugs, and post counts.', 'group' => 'Taxonomy', 'access' => 'read',  'default' => true  ),
        'wsp/create-category'   => array( 'label' => 'Create Category',  'description' => 'Create a new post category.',                                'group' => 'Taxonomy', 'access' => 'write', 'default' => false ),
        'wsp/get-tags'          => array( 'label' => 'Read Tags',        'description' => 'List all post tags with IDs, slugs, and post counts.',       'group' => 'Taxonomy', 'access' => 'read',  'default' => true  ),
        'wsp/create-tag'        => array( 'label' => 'Create Tag',       'description' => 'Create a new post tag.',                                     'group' => 'Taxonomy', 'access' => 'write', 'default' => false ),
        // COMMENTS
        'wsp/get-comments'      => array( 'label' => 'Read Comments',    'description' => 'List comments with author, status, and content snippet.',    'group' => 'Comments', 'access' => 'read',  'default' => false ),
        'wsp/approve-comment'   => array( 'label' => 'Approve Comment',  'description' => 'Approve a pending comment by ID.',                           'group' => 'Comments', 'access' => 'write', 'default' => false ),
        'wsp/delete-comment'    => array( 'label' => 'Delete Comment',   'description' => 'Move a comment to trash by ID.',                             'group' => 'Comments', 'access' => 'write', 'default' => false ),
        // MEDIA
        'wsp/get-media'         => array( 'label' => 'Read Media',       'description' => 'List media library items (title, URL, MIME type, date).',    'group' => 'Media',    'access' => 'read',  'default' => false ),
        // USERS
        'wsp/get-users'         => array( 'label' => 'Read Users',       'description' => 'List users with display name, email, and role.',             'group' => 'Users',    'access' => 'read',  'default' => false ),
        // SEARCH
        'wsp/search'            => array( 'label' => 'Search Content',   'description' => 'Search posts and pages by keyword.',                         'group' => 'Search',   'access' => 'read',  'default' => true  ),
        // SITE
        'wsp/get-site-info'     => array( 'label' => 'Read Site Info',   'description' => 'Return site name, URL, tagline, WP version, and language.', 'group' => 'Site',     'access' => 'read',  'default' => true  ),
        'wsp/get-plugins'       => array( 'label' => 'Read Plugins',     'description' => 'List all active plugins with name, version, and author.',    'group' => 'Site',     'access' => 'read',  'default' => false ),
    );
}

function wsp_mcp_get_settings() {
    $saved    = get_option( WSP_MCP_OPTION, array() );
    $registry = wsp_mcp_ability_registry();
    $settings = array();
    foreach ( $registry as $key => $cfg ) {
        $settings[ $key ] = isset( $saved[ $key ] ) ? (bool) $saved[ $key ] : $cfg['default'];
    }
    return $settings;
}

function wsp_mcp_is_enabled( $key ) {
    $s = wsp_mcp_get_settings();
    return ! empty( $s[ $key ] );
}

// ─────────────────────────────────────────────
// ADMIN MENU
// ─────────────────────────────────────────────
add_action( 'admin_menu', 'wsp_mcp_add_menu' );
function wsp_mcp_add_menu() {
    // 1. Add the main top-level menu exactly beneath Dashboard (position 3)
    add_menu_page( 
        'WSP MCP Abilities', 
        'MCP', 
        'manage_options', 
        'wsp-mcp-abilities', 
        'wsp_mcp_settings_page', 
        'dashicons-admin-generic', 
        3 
    );

    // 2. Rename the first default sub-menu item to "Settings"
    add_submenu_page(
        'wsp-mcp-abilities',
        'Settings',
        'Settings',
        'manage_options',
        'wsp-mcp-abilities' // Same slug as parent routes it to settings
    );

    // 3. Add the new "Config Files" sub-menu
    add_submenu_page(
        'wsp-mcp-abilities',
        'Config Files',
        'Config Files',
        'manage_options',
        'wsp-mcp-config',
        'wsp_mcp_config_page'
    );
}

add_action( 'admin_init', 'wsp_mcp_register_settings' );
function wsp_mcp_register_settings() {
    register_setting( 'wsp_mcp_settings_group', WSP_MCP_OPTION, array( 'sanitize_callback' => 'wsp_mcp_sanitize_settings' ) );
}

function wsp_mcp_sanitize_settings( $input ) {
    $clean = array();
    foreach ( wsp_mcp_ability_registry() as $key => $cfg ) {
        $clean[ $key ] = ! empty( $input[ $key ] );
    }
    return $clean;
}

// ─────────────────────────────────────────────
// SETTINGS PAGE
// ─────────────────────────────────────────────
function wsp_mcp_settings_page() {
    $registry = wsp_mcp_ability_registry();
    $settings = wsp_mcp_get_settings();
    $groups   = array();
    foreach ( $registry as $key => $cfg ) { $groups[ $cfg['group'] ][ $key ] = $cfg; }
    $icons    = array( 'Posts' => '📝', 'Pages' => '📄', 'Taxonomy' => '🏷️', 'Comments' => '💬', 'Media' => '🖼️', 'Users' => '👥', 'Search' => '🔍', 'Site' => '🌐' );
    $total    = count( $settings );
    $enabled  = count( array_filter( $settings ) );
    $writes   = 0;
    foreach ( $settings as $key => $on ) { if ( $on && isset( $registry[$key] ) && $registry[$key]['access']==='write' ) $writes++; }
    ?>
    <style>
        .wsp-wrap{max-width:860px;margin:30px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .wsp-header{display:flex;align-items:center;gap:12px;margin-bottom:24px}
        .wsp-header h1{margin:0;font-size:22px;font-weight:700;color:#1d2327}
        .wsp-badge-ver{background:#0073aa;color:#fff;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px}
        .wsp-desc{color:#646970;margin-bottom:24px;font-size:13.5px;line-height:1.65}
        .wsp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
        .wsp-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 20px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .wsp-stat-n{font-size:30px;font-weight:700;color:#1d2327}
        .wsp-stat-l{font-size:11px;color:#787c82;margin-top:2px;text-transform:uppercase;letter-spacing:.5px}
        .wsp-stat--on .wsp-stat-n{color:#00a32a}
        .wsp-stat--wr .wsp-stat-n{color:#d63638}
        .wsp-group{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-bottom:16px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .wsp-gh{background:#f6f7f7;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #dcdcde}
        .wsp-gt{font-weight:700;font-size:13.5px;color:#1d2327;margin:0;display:flex;align-items:center;gap:8px}
        .wsp-toggle-all{font-size:12px;color:#0073aa;cursor:pointer;text-decoration:underline;background:none;border:none;padding:0;font-weight:600}
        .wsp-row{display:grid;grid-template-columns:1fr 50px;align-items:center;padding:13px 20px;border-bottom:1px solid #f0f0f1;gap:12px;transition:background .12s}
        .wsp-row:last-child{border-bottom:none}
        .wsp-row:hover{background:#fafafa}
        .wsp-al{font-weight:600;font-size:13px;color:#1d2327;display:flex;align-items:center;gap:7px;margin-bottom:3px}
        .wsp-ad{font-size:12px;color:#787c82;line-height:1.5}
        .wsp-ac{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px}
        .wsp-ac--read{background:#e0f0fa;color:#0073aa}
        .wsp-ac--write{background:#fde8e8;color:#d63638}
        .wsp-sw{position:relative;display:inline-block;width:46px;height:26px;flex-shrink:0}
        .wsp-sw input{opacity:0;width:0;height:0}
        .wsp-sl{position:absolute;cursor:pointer;inset:0;background:#c3c4c7;border-radius:34px;transition:.25s}
        .wsp-sl:before{position:absolute;content:"";height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 3px rgba(0,0,0,.25)}
        input:checked+.wsp-sl{background:#00a32a}
        input:checked+.wsp-sl:before{transform:translateX(20px)}
        .wsp-savebar{display:flex;align-items:center;gap:16px;margin-top:24px;padding:18px 20px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
        .wsp-savebar .button-primary{font-size:14px;padding:7px 20px;height:auto}
        .wsp-savenote{font-size:12px;color:#787c82}
    </style>

    <div class="wsp-wrap">
        <div class="wsp-header">
            <h1>⚙️ WSP MCP Abilities</h1>
            <span class="wsp-badge-ver">v2.0</span>
        </div>
        <p class="wsp-desc">
            Control exactly what your <strong>AI agents</strong> can <strong>read</strong> and <strong>write</strong> on your WordPress site via MCP.
            <span style="color:#d63638;font-weight:600;"> ⚠ Write abilities modify your live site — enable with care.</span>
        </p>

        <div class="wsp-stats">
            <div class="wsp-stat"><div class="wsp-stat-n"><?php echo $total; ?></div><div class="wsp-stat-l">Total Abilities</div></div>
            <div class="wsp-stat wsp-stat--on"><div class="wsp-stat-n"><?php echo $enabled; ?></div><div class="wsp-stat-l">Enabled</div></div>
            <div class="wsp-stat wsp-stat--wr"><div class="wsp-stat-n"><?php echo $writes; ?></div><div class="wsp-stat-l">Write Access Active</div></div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'wsp_mcp_settings_group' ); ?>

            <?php foreach ( $groups as $gname => $abilities ) : ?>
            <div class="wsp-group">
                <div class="wsp-gh">
                    <h3 class="wsp-gt"><?php echo $icons[$gname] ?? '⚡'; ?> <?php echo esc_html($gname); ?></h3>
                    <button type="button" class="wsp-toggle-all" data-group="<?php echo esc_attr($gname); ?>">Toggle All</button>
                </div>
                <?php foreach ( $abilities as $key => $cfg ) : ?>
                <div class="wsp-row">
                    <div>
                        <div class="wsp-al">
                            <?php echo esc_html($cfg['label']); ?>
                            <span class="wsp-ac wsp-ac--<?php echo esc_attr($cfg['access']); ?>"><?php echo esc_html($cfg['access']); ?></span>
                        </div>
                        <div class="wsp-ad"><?php echo esc_html($cfg['description']); ?></div>
                    </div>
                    <label class="wsp-sw">
                        <input type="checkbox"
                            name="<?php echo WSP_MCP_OPTION; ?>[<?php echo esc_attr($key); ?>]"
                            value="1"
                            data-group="<?php echo esc_attr($gname); ?>"
                            data-access="<?php echo esc_attr($cfg['access']); ?>"
                            <?php checked( !empty($settings[$key]) ); ?>>
                        <span class="wsp-sl"></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <div class="wsp-savebar">
                <?php submit_button( '💾 Save Settings', 'primary', 'submit', false ); ?>
                <span class="wsp-savenote">Changes take effect immediately for all active MCP sessions.</span>
            </div>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
        // Warn before enabling write abilities
        document.querySelectorAll('input[data-access="write"]').forEach(function(cb){
            cb.addEventListener('change', function(){
                if(this.checked && !confirm('⚠️ This ability can MODIFY live site content.\n\nAre you sure you want to enable it?')){
                    this.checked = false;
                }
            });
        });
        // Toggle all in group
        document.querySelectorAll('.wsp-toggle-all').forEach(function(btn){
            btn.addEventListener('click', function(){
                var g = this.dataset.group;
                var boxes = document.querySelectorAll('input[data-group="'+g+'"]');
                var allOn = Array.from(boxes).every(function(b){ return b.checked; });
                boxes.forEach(function(b){ b.checked = !allOn; });
            });
        });
    });
    </script>
    <?php
}

// ─────────────────────────────────────────────
// CONFIG FILES PAGE
// ─────────────────────────────────────────────
function wsp_mcp_config_page() {
    $current_user = wp_get_current_user();
    $username     = $current_user->exists() ? $current_user->user_login : 'your-wordpress-user-name';
    $api_url      = untrailingslashit( rest_url( 'mcp/mcp-adapter-default-server' ) );

    // Derive a TOML-safe server key from the site domain
    $site_domain = parse_url( get_site_url(), PHP_URL_HOST );
    $toml_key    = 'wsp-' . trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $site_domain ) ), '-' );

    // Shared JSON config (Claude Desktop, Cursor, Antigravity)
    $config_array = array(
        'mcpServers' => array(
            'wsp-wordpress-mcp' => array(
                'command' => 'npx',
                'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
                'env'     => array(
                    'WP_API_URL'      => $api_url,
                    'WP_API_USERNAME' => $username,
                    'WP_API_PASSWORD' => 'replace-with-your-application-password',
                ),
            ),
        ),
    );
    $config_json = wp_json_encode( $config_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

    // Codex TOML config
    $toml_config = "[mcp_servers.{$toml_key}]\n"
        . "command = \"npx\"\n"
        . "args = [\"-y\", \"@automattic/mcp-wordpress-remote@latest\"]\n"
        . "\n"
        . "[mcp_servers.{$toml_key}.env]\n"
        . "WP_API_URL = \"{$api_url}\"\n"
        . "WP_API_USERNAME = \"{$username}\"\n"
        . "WP_API_PASSWORD = \"replace-with-your-application-password\"";
    ?>
    <style>
        .wsp-wrap { max-width:860px; margin:30px 20px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .wsp-header { display:flex; align-items:center; gap:12px; margin-bottom:24px; }
        .wsp-header h1 { margin:0; font-size:22px; font-weight:700; color:#1d2327; }
        .wsp-desc { color:#646970; margin-bottom:16px; font-size:13.5px; line-height:1.65; }
        /* Tabs */
        .wsp-tabs { display:flex; gap:0; margin-bottom:0; border-bottom:2px solid #dcdcde; }
        .wsp-tab-btn { background:none; border:none; padding:10px 22px; font-size:14px; font-weight:600; color:#787c82; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:color .15s,border-color .15s; }
        .wsp-tab-btn:hover { color:#1d2327; }
        .wsp-tab-btn.wsp-tab-active { color:#0073aa; border-bottom-color:#0073aa; }
        .wsp-tab-panel { display:none; }
        .wsp-tab-panel.wsp-tab-panel-active { display:block; }
        /* Config box */
        .wsp-config-box { background:#fff; border:1px solid #dcdcde; border-radius:0 0 8px 8px; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.04); }
        .wsp-instructions { padding:20px; border-bottom:1px solid #f0f0f1; background:#fff; }
        .wsp-instructions p { margin:0 0 10px 0; color:#3c434a; font-size:14px; }
        .wsp-instructions p:last-child { margin:0; }
        .wsp-instructions code { background:#f0f0f1; padding:3px 6px; border-radius:4px; font-size:13px; color:#d63638; font-family:monospace; }
        .wsp-config-header { background:#f6f7f7; padding:12px 20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #dcdcde; }
        .wsp-config-title { font-weight:700; font-size:13.5px; color:#1d2327; margin:0; }
        .wsp-copy-btn { font-size:12px; color:#0073aa; cursor:pointer; background:none; border:none; padding:0; font-weight:600; display:flex; align-items:center; gap:4px; transition:color .2s; }
        .wsp-copy-btn:hover { color:#00a32a; }
        .wsp-code-area { background:#1e1e1e; color:#d4d4d4; padding:20px; margin:0; font-family:Consolas,Monaco,monospace; font-size:13.5px; line-height:1.6; overflow-x:auto; white-space:pre; }
    </style>

    <div class="wsp-wrap">
        <div class="wsp-header">
            <h1>⚙️ Connection Config</h1>
        </div>
        <p class="wsp-desc">
            Connect your AI tool to this WordPress site. Your API URL and username are auto-detected —
            just generate an <strong>Application Password</strong> and paste it in.
        </p>

        <div class="wsp-tabs">
            <button type="button" class="wsp-tab-btn wsp-tab-active" data-tab="claude">Claude Desktop</button>
            <button type="button" class="wsp-tab-btn" data-tab="cursor">Cursor</button>
            <button type="button" class="wsp-tab-btn" data-tab="codex">Codex</button>
            <button type="button" class="wsp-tab-btn" data-tab="antigravity">Antigravity</button>
        </div>

        <?php /* ── Tab 1: Claude Desktop ── */ ?>
        <div class="wsp-tab-panel wsp-tab-panel-active" id="wsp-tab-claude">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Copy the code and paste it into your <code>claude_desktop_config.json</code> file.</p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">claude_desktop_config.json</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-claude">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-claude"><?php echo esc_html( $config_json ); ?></pre>
            </div>
        </div>

        <?php /* ── Tab 2: Cursor ── */ ?>
        <div class="wsp-tab-panel" id="wsp-tab-cursor">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Copy the code and paste it into <code>~/.cursor/mcp.json</code> (global) or <code>.cursor/mcp.json</code> in your project root.</p>
                    <p>4. Restart Cursor for the changes to take effect.</p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">~/.cursor/mcp.json</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-cursor">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-cursor"><?php echo esc_html( $config_json ); ?></pre>
            </div>
        </div>

        <?php /* ── Tab 3: Codex ── */ ?>
        <div class="wsp-tab-panel" id="wsp-tab-codex">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Copy the code and paste it into your <code>~/.codex/config.toml</code> file.</p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">~/.codex/config.toml</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-codex">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-codex"><?php echo esc_html( $toml_config ); ?></pre>
            </div>
        </div>

        <?php /* ── Tab 4: Antigravity ── */ ?>
        <div class="wsp-tab-panel" id="wsp-tab-antigravity">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Copy the code and paste it into <code>~/.gemini/config/mcp_config.json</code>, or open <strong>Manage MCP Servers &gt; View raw config</strong> in Antigravity.</p>
                    <p>4. Restart Antigravity for the changes to take effect.</p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">~/.gemini/config/mcp_config.json</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-antigravity">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-antigravity"><?php echo esc_html( $config_json ); ?></pre>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Tab switching
        document.querySelectorAll('.wsp-tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.wsp-tab-btn').forEach(function(b){ b.classList.remove('wsp-tab-active'); });
                document.querySelectorAll('.wsp-tab-panel').forEach(function(p){ p.classList.remove('wsp-tab-panel-active'); });
                btn.classList.add('wsp-tab-active');
                document.getElementById('wsp-tab-' + btn.dataset.tab).classList.add('wsp-tab-panel-active');
            });
        });

        // Copy buttons
        function makeCopyBtn(btnId, codeId) {
            var btn  = document.getElementById(btnId);
            var code = document.getElementById(codeId);
            if (!btn || !code) return;
            btn.addEventListener('click', function() {
                navigator.clipboard.writeText(code.innerText).then(function() {
                    var orig = btn.innerHTML;
                    btn.innerHTML = '<span class="dashicons dashicons-yes-alt" style="font-size:16px;width:16px;height:16px;"></span> Copied!';
                    btn.style.color = '#00a32a';
                    setTimeout(function(){ btn.innerHTML = orig; btn.style.color = ''; }, 2500);
                }).catch(function(){ alert('Failed to copy. Please select and copy manually.'); });
            });
        }
        makeCopyBtn('wsp-copy-claude',      'wsp-code-claude');
        makeCopyBtn('wsp-copy-cursor',      'wsp-code-cursor');
        makeCopyBtn('wsp-copy-codex',       'wsp-code-codex');
        makeCopyBtn('wsp-copy-antigravity', 'wsp-code-antigravity');
    });
    </script>
    <?php
}

// ─────────────────────────────────────────────
// REGISTER CATEGORY
// ─────────────────────────────────────────────
add_action( 'wp_abilities_api_categories_init', 'wsp_register_ability_category' );
function wsp_register_ability_category() {
    wp_register_ability_category( 'wsp', array( 'label' => 'WebSensePro', 'description' => 'WebSensePro MCP abilities.' ) );
}

// ─────────────────────────────────────────────
// REGISTER ABILITIES (only if enabled in settings)
// ─────────────────────────────────────────────
add_action( 'wp_abilities_api_init', 'wsp_register_all_abilities' );
function wsp_register_all_abilities() {

    $base = array( 'category' => 'wsp', 'output_schema' => array('type'=>'object'), 'meta' => array('mcp'=>array('public'=>true)) );

    // GET POSTS
    if ( wsp_mcp_is_enabled('wsp/get-posts') ) {
        wp_register_ability( 'wsp/get-posts', array_merge($base, array(
            'label'       => 'Get Blog Posts',
            'description' => 'Returns blog posts with full metadata.',
            'input_schema' => array('type'=>'object','properties'=>array(
                'per_page' => array('type'=>'integer','description'=>'Number of posts. Default 10.'),
                'status'   => array('type'=>'string', 'description'=>'publish | draft | all. Default publish.'),
            )),
            'permission_callback' => '__return_true',
            'execute_callback'   => 'wsp_execute_get_posts',
        )));
    }

    // CREATE POST
    if ( wsp_mcp_is_enabled('wsp/create-post') ) {
        wp_register_ability( 'wsp/create-post', array_merge($base, array(
            'label'       => 'Create Post',
            'description' => 'Creates a new blog post.',
            'input_schema' => array('type'=>'object','required'=>array('title','content'),'properties'=>array(
                'title'      => array('type'=>'string', 'description'=>'Post title.'),
                'content'    => array('type'=>'string', 'description'=>'Post content (HTML).'),
                'status'     => array('type'=>'string', 'description'=>'publish | draft | pending. Default draft.'),
                'categories' => array('type'=>'array',  'items'=>array('type'=>'integer'), 'description'=>'Category IDs.'),
                'tags'       => array('type'=>'array',  'items'=>array('type'=>'integer'), 'description'=>'Tag IDs.'),
                'excerpt'    => array('type'=>'string', 'description'=>'Post excerpt.'),
                'slug'       => array('type'=>'string', 'description'=>'URL slug.'),
            )),
            'permission_callback' => function(){ return current_user_can('publish_posts'); },
            'execute_callback'   => 'wsp_execute_create_post',
        )));
    }

    // UPDATE POST
    if ( wsp_mcp_is_enabled('wsp/update-post') ) {
        wp_register_ability( 'wsp/update-post', array_merge($base, array(
            'label'       => 'Update Post',
            'description' => 'Updates an existing post.',
            'input_schema' => array('type'=>'object','required'=>array('id'),'properties'=>array(
                'id'         => array('type'=>'integer','description'=>'Post ID.'),
                'title'      => array('type'=>'string', 'description'=>'New title.'),
                'content'    => array('type'=>'string', 'description'=>'New content.'),
                'status'     => array('type'=>'string', 'description'=>'New status.'),
                'categories' => array('type'=>'array',  'items'=>array('type'=>'integer')),
                'tags'       => array('type'=>'array',  'items'=>array('type'=>'integer')),
            )),
            'permission_callback' => function(){ return current_user_can('edit_posts'); },
            'execute_callback'   => 'wsp_execute_update_post',
        )));
    }

    // DELETE POST
    if ( wsp_mcp_is_enabled('wsp/delete-post') ) {
        wp_register_ability( 'wsp/delete-post', array_merge($base, array(
            'label'       => 'Delete Post',
            'description' => 'Moves a post to trash.',
            'input_schema' => array('type'=>'object','required'=>array('id'),'properties'=>array(
                'id' => array('type'=>'integer','description'=>'Post ID.'),
            )),
            'permission_callback' => function(){ return current_user_can('delete_posts'); },
            'execute_callback'   => 'wsp_execute_delete_post',
        )));
    }

    // GET PAGES
    if ( wsp_mcp_is_enabled('wsp/get-pages') ) {
        wp_register_ability( 'wsp/get-pages', array_merge($base, array(
            'label'       => 'Get Pages',
            'description' => 'Returns published pages.',
            'input_schema' => array('type'=>'object','properties'=>array()),
            'permission_callback' => '__return_true',
            'execute_callback'   => 'wsp_execute_get_pages',
        )));
    }

    // CREATE PAGE
    if ( wsp_mcp_is_enabled('wsp/create-page') ) {
        wp_register_ability( 'wsp/create-page', array_merge($base, array(
            'label'       => 'Create Page',
            'description' => 'Creates a new page.',
            'input_schema' => array('type'=>'object','required'=>array('title','content'),'properties'=>array(
                'title'   => array('type'=>'string', 'description'=>'Page title.'),
                'content' => array('type'=>'string', 'description'=>'Page content.'),
                'status'  => array('type'=>'string', 'description'=>'publish | draft.'),
                'parent'  => array('type'=>'integer','description'=>'Parent page ID.'),
                'slug'    => array('type'=>'string', 'description'=>'URL slug.'),
            )),
            'permission_callback' => function(){ return current_user_can('publish_pages'); },
            'execute_callback'   => 'wsp_execute_create_page',
        )));
    }

    // UPDATE PAGE
    if ( wsp_mcp_is_enabled('wsp/update-page') ) {
        wp_register_ability( 'wsp/update-page', array_merge($base, array(
            'label'       => 'Update Page',
            'description' => 'Updates an existing page.',
            'input_schema' => array('type'=>'object','required'=>array('id'),'properties'=>array(
                'id'      => array('type'=>'integer','description'=>'Page ID.'),
                'title'   => array('type'=>'string', 'description'=>'New title.'),
                'content' => array('type'=>'string', 'description'=>'New content.'),
                'status'  => array('type'=>'string', 'description'=>'New status.'),
            )),
            'permission_callback' => function(){ return current_user_can('edit_pages'); },
            'execute_callback'   => 'wsp_execute_update_page',
        )));
    }

    // DELETE PAGE
    if ( wsp_mcp_is_enabled('wsp/delete-page') ) {
        wp_register_ability( 'wsp/delete-page', array_merge($base, array(
            'label'       => 'Delete Page',
            'description' => 'Moves a page to trash.',
            'input_schema' => array('type'=>'object','required'=>array('id'),'properties'=>array(
                'id' => array('type'=>'integer','description'=>'Page ID.'),
            )),
            'permission_callback' => function(){ return current_user_can('delete_pages'); },
            'execute_callback'   => 'wsp_execute_delete_page',
        )));
    }

    // GET CATEGORIES
    if ( wsp_mcp_is_enabled('wsp/get-categories') ) {
        wp_register_ability( 'wsp/get-categories', array_merge($base, array(
            'label'       => 'Get Categories',
            'description' => 'Returns all categories.',
            'input_schema' => array('type'=>'object','properties'=>array()),
            'permission_callback' => '__return_true',
            'execute_callback'   => 'wsp_execute_get_categories',
        )));
    }

    // CREATE CATEGORY
    if ( wsp_mcp_is_enabled('wsp/create-category') ) {
        wp_register_ability( 'wsp/create-category', array_merge($base, array(
            'label'       => 'Create Category',
            'description' => 'Creates a new category.',
            'input_schema' => array('type'=>'object','required'=>array('name'),'properties'=>array(
                'name'        => array('type'=>'string', 'description'=>'Category name.'),
                'description' => array('type'=>'string', 'description'=>'Description.'),
                'parent'      => array('type'=>'integer','description'=>'Parent category ID.'),
            )),
            'permission_callback' => function(){ return current_user_can('manage_categories'); },
            'execute_callback'   => 'wsp_execute_create_category',
        )));
    }

    // GET TAGS
    if ( wsp_mcp_is_enabled('wsp/get-tags') ) {
        wp_register_ability( 'wsp/get-tags', array_merge($base, array(
            'label'       => 'Get Tags',
            'description' => 'Returns all tags.',
            'input_schema' => array('type'=>'object','properties'=>array()),
            'permission_callback' => '__return_true',
            'execute_callback'   => 'wsp_execute_get_tags',
        )));
    }

    // CREATE TAG
    if ( wsp_mcp_is_enabled('wsp/create-tag') ) {
        wp_register_ability( 'wsp/create-tag', array_merge($base, array(
            'label'       => 'Create Tag',
            'description' => 'Creates a new tag.',
            'input_schema' => array('type'=>'object','required'=>array('name'),'properties'=>array(
                'name'        => array('type'=>'string','description'=>'Tag name.'),
                'description' => array('type'=>'string','description'=>'Description.'),
            )),
            'permission_callback' => function(){ return current_user_can('manage_categories'); },
            'execute_callback'   => 'wsp_execute_create_tag',
        )));
    }

    // GET COMMENTS
    if ( wsp_mcp_is_enabled('wsp/get-comments') ) {
        wp_register_ability( 'wsp/get-comments', array_merge($base, array(
            'label'       => 'Get Comments',
            'description' => 'Returns comments.',
            'input_schema' => array('type'=>'object','properties'=>array(
                'status'   => array('type'=>'string', 'description'=>'hold | approve | all.'),
                'per_page' => array('type'=>'integer','description'=>'Limit. Default 20.'),
            )),
            'permission_callback' => function(){ return current_user_can('moderate_comments'); },
            'execute_callback'   => 'wsp_execute_get_comments',
        )));
    }

    // APPROVE COMMENT
    if ( wsp_mcp_is_enabled('wsp/approve-comment') ) {
        wp_register_ability( 'wsp/approve-comment', array_merge($base, array(
            'label'       => 'Approve Comment',
            'description' => 'Approves a pending comment.',
            'input_schema' => array('type'=>'object','required'=>array('id'),'properties'=>array(
                'id' => array('type'=>'integer','description'=>'Comment ID.'),
            )),
            'permission_callback' => function(){ return current_user_can('moderate_comments'); },
            'execute_callback'   => 'wsp_execute_approve_comment',
        )));
    }

    // DELETE COMMENT
    if ( wsp_mcp_is_enabled('wsp/delete-comment') ) {
        wp_register_ability( 'wsp/delete-comment', array_merge($base, array(
            'label'       => 'Delete Comment',
            'description' => 'Trashes a comment.',
            'input_schema' => array('type'=>'object','required'=>array('id'),'properties'=>array(
                'id' => array('type'=>'integer','description'=>'Comment ID.'),
            )),
            'permission_callback' => function(){ return current_user_can('moderate_comments'); },
            'execute_callback'   => 'wsp_execute_delete_comment',
        )));
    }

    // GET MEDIA
    if ( wsp_mcp_is_enabled('wsp/get-media') ) {
        wp_register_ability( 'wsp/get-media', array_merge($base, array(
            'label'       => 'Get Media',
            'description' => 'Lists media library items.',
            'input_schema' => array('type'=>'object','properties'=>array(
                'per_page' => array('type'=>'integer','description'=>'Limit. Default 20.'),
                'type'     => array('type'=>'string', 'description'=>'MIME type filter e.g. image.'),
            )),
            'permission_callback' => function(){ return current_user_can('upload_files'); },
            'execute_callback'   => 'wsp_execute_get_media',
        )));
    }

    // GET USERS
    if ( wsp_mcp_is_enabled('wsp/get-users') ) {
        wp_register_ability( 'wsp/get-users', array_merge($base, array(
            'label'       => 'Get Users',
            'description' => 'Lists registered users.',
            'input_schema' => array('type'=>'object','properties'=>array()),
            'permission_callback' => function(){ return current_user_can('list_users'); },
            'execute_callback'   => 'wsp_execute_get_users',
        )));
    }

    // SEARCH
    if ( wsp_mcp_is_enabled('wsp/search') ) {
        wp_register_ability( 'wsp/search', array_merge($base, array(
            'label'       => 'Search Content',
            'description' => 'Search posts and pages by keyword.',
            'input_schema' => array('type'=>'object','required'=>array('query'),'properties'=>array(
                'query' => array('type'=>'string','description'=>'Search keyword.'),
            )),
            'permission_callback' => '__return_true',
            'execute_callback'   => 'wsp_execute_search',
        )));
    }

    // GET SITE INFO
    if ( wsp_mcp_is_enabled('wsp/get-site-info') ) {
        wp_register_ability( 'wsp/get-site-info', array_merge($base, array(
            'label'       => 'Get Site Info',
            'description' => 'Returns site metadata.',
            'input_schema' => array('type'=>'object','properties'=>array()),
            'permission_callback' => '__return_true',
            'execute_callback'   => 'wsp_execute_get_site_info',
        )));
    }

    // GET PLUGINS
    if ( wsp_mcp_is_enabled('wsp/get-plugins') ) {
        wp_register_ability( 'wsp/get-plugins', array_merge($base, array(
            'label'       => 'Get Active Plugins',
            'description' => 'Lists active plugins.',
            'input_schema' => array('type'=>'object','properties'=>array()),
            'permission_callback' => function(){ return current_user_can('activate_plugins'); },
            'execute_callback'   => 'wsp_execute_get_plugins',
        )));
    }
}

// ─────────────────────────────────────────────
// EXECUTE CALLBACKS
// ─────────────────────────────────────────────

function wsp_execute_get_posts( $input ) {
    $per_page = isset($input['per_page']) ? intval($input['per_page']) : 10;
    $status   = isset($input['status'])   ? sanitize_text_field($input['status']) : 'publish';
    if ( $status === 'all' ) $status = array('publish','draft','pending','future');
    $q = new WP_Query(array('post_status'=>$status,'posts_per_page'=>$per_page,'orderby'=>'date','order'=>'DESC'));
    $posts = array();
    foreach ( $q->posts as $p ) {
        $posts[] = array(
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'url'        => get_permalink($p->ID),
            'status'     => $p->post_status,
            'date'       => get_the_date('Y-m-d', $p->ID),
            'author'     => get_the_author_meta('display_name', $p->post_author),
            'categories' => wp_get_post_categories($p->ID, array('fields'=>'names')),
            'tags'       => wp_get_post_tags($p->ID, array('fields'=>'names')),
            'excerpt'    => has_excerpt($p->ID) ? get_the_excerpt($p) : wp_trim_words($p->post_content, 40),
        );
    }
    return array('posts'=>$posts,'total'=>$q->found_posts);
}

function wsp_execute_create_post( $input ) {
    $args = array(
        'post_title'   => sanitize_text_field($input['title']),
        'post_content' => wp_kses_post($input['content']),
        'post_status'  => isset($input['status'])  ? sanitize_text_field($input['status']) : 'draft',
        'post_type'    => 'post',
    );
    if (!empty($input['excerpt']))    $args['post_excerpt']  = sanitize_text_field($input['excerpt']);
    if (!empty($input['slug']))       $args['post_name']     = sanitize_title($input['slug']);
    if (!empty($input['categories'])) $args['post_category'] = array_map('intval', $input['categories']);
    $id = wp_insert_post($args, true);
    if (is_wp_error($id)) return array('success'=>false,'error'=>$id->get_error_message());
    if (!empty($input['tags'])) wp_set_post_tags($id, array_map('intval', $input['tags']));
    return array('success'=>true,'id'=>$id,'url'=>get_permalink($id),'status'=>$args['post_status']);
}

function wsp_execute_update_post( $input ) {
    $args = array('ID'=>intval($input['id']));
    if (isset($input['title']))      $args['post_title']    = sanitize_text_field($input['title']);
    if (isset($input['content']))    $args['post_content']  = wp_kses_post($input['content']);
    if (isset($input['status']))     $args['post_status']   = sanitize_text_field($input['status']);
    if (isset($input['categories'])) $args['post_category'] = array_map('intval',$input['categories']);
    $id = wp_update_post($args, true);
    if (is_wp_error($id)) return array('success'=>false,'error'=>$id->get_error_message());
    if (isset($input['tags'])) wp_set_post_tags($id, array_map('intval',$input['tags']));
    return array('success'=>true,'id'=>$id,'url'=>get_permalink($id));
}

function wsp_execute_delete_post( $input ) {
    $id = intval($input['id']);
    return wp_trash_post($id)
        ? array('success'=>true,'message'=>"Post $id moved to trash.")
        : array('success'=>false,'error'=>'Could not trash post.');
}

function wsp_execute_get_pages( $input ) {
    $pages = get_pages(array('post_status'=>'publish'));
    $result = array();
    foreach ($pages as $page) {
        $result[] = array('id'=>$page->ID,'title'=>$page->post_title,'url'=>get_permalink($page->ID),'parent'=>$page->post_parent,'status'=>$page->post_status);
    }
    return array('pages'=>$result);
}

function wsp_execute_create_page( $input ) {
    $args = array('post_title'=>sanitize_text_field($input['title']),'post_content'=>wp_kses_post($input['content']),'post_status'=>isset($input['status'])?sanitize_text_field($input['status']):'draft','post_type'=>'page');
    if (!empty($input['parent'])) $args['post_parent'] = intval($input['parent']);
    if (!empty($input['slug']))   $args['post_name']   = sanitize_title($input['slug']);
    $id = wp_insert_post($args, true);
    if (is_wp_error($id)) return array('success'=>false,'error'=>$id->get_error_message());
    return array('success'=>true,'id'=>$id,'url'=>get_permalink($id));
}

function wsp_execute_update_page( $input ) {
    $args = array('ID'=>intval($input['id']),'post_type'=>'page');
    if (isset($input['title']))   $args['post_title']   = sanitize_text_field($input['title']);
    if (isset($input['content'])) $args['post_content'] = wp_kses_post($input['content']);
    if (isset($input['status']))  $args['post_status']  = sanitize_text_field($input['status']);
    $id = wp_update_post($args, true);
    if (is_wp_error($id)) return array('success'=>false,'error'=>$id->get_error_message());
    return array('success'=>true,'id'=>$id,'url'=>get_permalink($id));
}

function wsp_execute_delete_page( $input ) {
    $id = intval($input['id']);
    return wp_trash_post($id)
        ? array('success'=>true,'message'=>"Page $id moved to trash.")
        : array('success'=>false,'error'=>'Could not trash page.');
}

function wsp_execute_get_categories( $input ) {
    $cats = get_categories(array('hide_empty'=>false));
    $result = array();
    foreach ($cats as $c) { $result[] = array('id'=>$c->term_id,'name'=>$c->name,'slug'=>$c->slug,'count'=>$c->count,'parent'=>$c->parent); }
    return array('categories'=>$result);
}

function wsp_execute_create_category( $input ) {
    $args = array();
    if (!empty($input['description'])) $args['description'] = sanitize_text_field($input['description']);
    if (!empty($input['parent']))      $args['parent']      = intval($input['parent']);
    $result = wp_insert_term(sanitize_text_field($input['name']), 'category', $args);
    if (is_wp_error($result)) return array('success'=>false,'error'=>$result->get_error_message());
    return array('success'=>true,'id'=>$result['term_id'],'name'=>$input['name']);
}

function wsp_execute_get_tags( $input ) {
    $tags = get_tags(array('hide_empty'=>false));
    $result = array();
    foreach ($tags as $t) { $result[] = array('id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'count'=>$t->count); }
    return array('tags'=>$result);
}

function wsp_execute_create_tag( $input ) {
    $args = !empty($input['description']) ? array('description'=>sanitize_text_field($input['description'])) : array();
    $result = wp_insert_term(sanitize_text_field($input['name']), 'post_tag', $args);
    if (is_wp_error($result)) return array('success'=>false,'error'=>$result->get_error_message());
    return array('success'=>true,'id'=>$result['term_id'],'name'=>$input['name']);
}

function wsp_execute_get_comments( $input ) {
    $per_page = isset($input['per_page']) ? intval($input['per_page']) : 20;
    $status   = isset($input['status'])   ? sanitize_text_field($input['status']) : '';
    $args = array('number'=>$per_page);
    if ($status && $status !== 'all') $args['status'] = $status;
    $comments = get_comments($args);
    $result = array();
    foreach ($comments as $c) {
        $result[] = array('id'=>$c->comment_ID,'post_id'=>$c->comment_post_ID,'author'=>$c->comment_author,'email'=>$c->comment_author_email,'content'=>wp_trim_words($c->comment_content,20),'status'=>$c->comment_approved,'date'=>$c->comment_date);
    }
    return array('comments'=>$result,'total'=>count($result));
}

function wsp_execute_approve_comment( $input ) {
    return wp_set_comment_status(intval($input['id']), 'approve')
        ? array('success'=>true,'message'=>'Comment approved.')
        : array('success'=>false,'error'=>'Failed to approve.');
}

function wsp_execute_delete_comment( $input ) {
    return wp_trash_comment(intval($input['id']))
        ? array('success'=>true,'message'=>'Comment trashed.')
        : array('success'=>false,'error'=>'Failed to trash comment.');
}

function wsp_execute_get_media( $input ) {
    $per_page = isset($input['per_page']) ? intval($input['per_page']) : 20;
    $args = array('post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>$per_page);
    if (!empty($input['type'])) $args['post_mime_type'] = sanitize_text_field($input['type']);
    $q = new WP_Query($args);
    $result = array();
    foreach ($q->posts as $item) {
        $result[] = array('id'=>$item->ID,'title'=>$item->post_title,'url'=>wp_get_attachment_url($item->ID),'type'=>$item->post_mime_type,'date'=>get_the_date('Y-m-d',$item->ID));
    }
    return array('media'=>$result,'total'=>$q->found_posts);
}

function wsp_execute_get_users( $input ) {
    $users = get_users();
    $result = array();
    foreach ($users as $u) {
        $result[] = array('id'=>$u->ID,'display_name'=>$u->display_name,'email'=>$u->user_email,'roles'=>$u->roles,'registered'=>$u->user_registered);
    }
    return array('users'=>$result);
}

function wsp_execute_search( $input ) {
    $keyword = isset($input['query']) ? sanitize_text_field($input['query']) : '';
    $q = new WP_Query(array('s'=>$keyword,'post_status'=>'publish','posts_per_page'=>10));
    $results = array();
    foreach ($q->posts as $p) {
        $results[] = array('id'=>$p->ID,'title'=>$p->post_title,'url'=>get_permalink($p->ID),'type'=>$p->post_type);
    }
    return array('results'=>$results,'total'=>$q->found_posts);
}

function wsp_execute_get_site_info( $input ) {
    return array('name'=>get_bloginfo('name'),'url'=>get_site_url(),'tagline'=>get_bloginfo('description'),'admin_email'=>get_option('admin_email'),'wp_version'=>get_bloginfo('version'),'language'=>get_bloginfo('language'));
}

function wsp_execute_get_plugins( $input ) {
    if (!function_exists('get_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
    $all    = get_plugins();
    $active = get_option('active_plugins', array());
    $result = array();
    foreach ($active as $file) {
        if (isset($all[$file])) {
            $result[] = array('name'=>$all[$file]['Name'],'version'=>$all[$file]['Version'],'author'=>$all[$file]['Author'],'file'=>$file);
        }
    }
    return array('active_plugins'=>$result,'total'=>count($result));
}
