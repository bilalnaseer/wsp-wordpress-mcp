<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_mcp_add_menu() {
    add_menu_page(
        'WSP MCP Abilities',
        'MCP',
        'manage_options',
        'wsp-mcp-abilities',
        'wsp_mcp_settings_page',
        'dashicons-admin-generic',
        3
    );

    add_submenu_page(
        'wsp-mcp-abilities',
        'Settings',
        'Settings',
        'manage_options',
        'wsp-mcp-abilities'
    );

    add_submenu_page(
        'wsp-mcp-abilities',
        'Config Files',
        'Config Files',
        'manage_options',
        'wsp-mcp-config',
        'wsp_mcp_config_page'
    );
}

function wsp_mcp_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $registry = wsp_mcp_ability_registry();
    $settings = wsp_mcp_get_settings();
    $groups   = array();
    foreach ( $registry as $key => $cfg ) {
        $groups[ $cfg['group'] ][ $key ] = $cfg;
    }

    $icons   = array(
        'Posts'     => '📝',
        'Pages'     => '📄',
        'Taxonomy'  => '🏷️',
        'Comments'  => '💬',
        'Media'     => '🖼️',
        'Users'     => '👥',
        'Search'    => '🔍',
        'Site'      => '🌐',
        'Elementor' => '⚡',
        'Yoast SEO' => '🔎',
    );
    $total   = count( $settings );
    $enabled = count( array_filter( $settings ) );
    $writes  = 0;
    foreach ( $settings as $key => $on ) {
        if ( $on && isset( $registry[ $key ] ) && 'write' === $registry[ $key ]['access'] ) {
            $writes++;
        }
    }
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
            <span class="wsp-badge-ver">v<?php echo esc_html( WSP_MCP_VERSION ); ?></span>
        </div>
        <p class="wsp-desc">
            Control exactly what <strong>your AI agents</strong> can <strong>read</strong> and <strong>write</strong> on your WordPress site via MCP.
            <span style="color:#d63638;font-weight:600;"> ⚠ Write abilities modify your live site — enable with care.</span>
        </p>

        <div class="wsp-stats">
            <div class="wsp-stat"><div class="wsp-stat-n"><?php echo esc_html( $total ); ?></div><div class="wsp-stat-l">Total Abilities</div></div>
            <div class="wsp-stat wsp-stat--on"><div class="wsp-stat-n"><?php echo esc_html( $enabled ); ?></div><div class="wsp-stat-l">Enabled</div></div>
            <div class="wsp-stat wsp-stat--wr"><div class="wsp-stat-n"><?php echo esc_html( $writes ); ?></div><div class="wsp-stat-l">Write Access Active</div></div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'wsp_mcp_settings_group' ); ?>

            <?php foreach ( $groups as $gname => $abilities ) : ?>
            <div class="wsp-group">
                <div class="wsp-gh">
                    <h3 class="wsp-gt"><?php echo isset( $icons[ $gname ] ) ? esc_html( $icons[ $gname ] ) . ' ' : ''; ?><?php echo esc_html( $gname ); ?></h3>
                    <button type="button" class="wsp-toggle-all" data-group="<?php echo esc_attr( $gname ); ?>">Toggle All</button>
                </div>
                <?php foreach ( $abilities as $key => $cfg ) : ?>
                <div class="wsp-row">
                    <div>
                        <div class="wsp-al">
                            <?php echo esc_html( $cfg['label'] ); ?>
                            <span class="wsp-ac wsp-ac--<?php echo esc_attr( $cfg['access'] ); ?>"><?php echo esc_html( $cfg['access'] ); ?></span>
                        </div>
                        <div class="wsp-ad"><?php echo esc_html( $cfg['description'] ); ?></div>
                    </div>
                    <label class="wsp-sw">
                        <input type="checkbox"
                            name="<?php echo esc_attr( WSP_MCP_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
                            value="1"
                            data-group="<?php echo esc_attr( $gname ); ?>"
                            data-access="<?php echo esc_attr( $cfg['access'] ); ?>"
                            <?php checked( ! empty( $settings[ $key ] ) ); ?>>
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
        document.querySelectorAll('input[data-access="write"]').forEach(function(cb){
            cb.addEventListener('change', function(){
                if(this.checked && !confirm('⚠️ This ability can MODIFY live site content.\n\nAre you sure you want to enable it?')){
                    this.checked = false;
                }
            });
        });
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
