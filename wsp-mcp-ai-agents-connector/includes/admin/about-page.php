<?php
/**
 * MCP > About Us admin page.
 *
 * Static information about WebSensePro, the agency behind this plugin. All
 * content is hard-coded (taken from websensepro.com) — nothing is fetched at
 * runtime, so the page makes no external requests. Outbound links carry the
 * same UTM parameters as the promo cards (see promo-cards.php).
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Register the About Us submenu under the MCP top-level menu, after Analytics. */
function wsp_mcp_add_about_menu() {
	$page_hook = add_submenu_page(
		'wsp-mcp-abilities',
		__( 'About Us', 'wsp-mcp-ai-agents-connector' ),
		__( 'About Us', 'wsp-mcp-ai-agents-connector' ),
		'manage_options',
		'wsp-mcp-about',
		'wsp_mcp_about_page'
	);

	add_action( 'load-' . $page_hook, 'wsp_mcp_enqueue_about_assets' );
}
add_action( 'admin_menu', 'wsp_mcp_add_about_menu', 40 );

/** Enqueue this page's inline styles. */
function wsp_mcp_enqueue_about_assets() {
	add_action( 'admin_enqueue_scripts', function () {
		$custom_css = '
			.wsp-wrap{max-width:1180px;margin:24px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
			.wsp-header h1{margin:0 0 6px;font-size:22px;font-weight:700;color:#1d2327}
			.wsp-desc{color:#646970;margin:0 0 20px;font-size:13.5px;line-height:1.65;max-width:760px}

			.wsp-about-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
			@media (max-width:782px){.wsp-about-stats{grid-template-columns:repeat(2,1fr)}}
			.wsp-about-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.wsp-about-stat-n{font-size:26px;font-weight:700;color:#2271b1;line-height:1.2}
			.wsp-about-stat-l{font-size:12px;color:#787c82;margin-top:4px}

			.wsp-panels{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;margin-bottom:16px}
			@media (max-width:960px){.wsp-panels{grid-template-columns:1fr}}
			.wsp-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden;margin-bottom:16px}
			.wsp-panels .wsp-panel{margin-bottom:0}
			.wsp-panel-h{padding:14px 18px;border-bottom:1px solid #f0f0f1;font-weight:700;font-size:13.5px;color:#1d2327}
			.wsp-panel-body{padding:16px 18px;font-size:13px;line-height:1.65;color:#3c434a}
			.wsp-panel-body p{margin:0 0 10px}
			.wsp-panel-body p:last-child{margin-bottom:0}

			.wsp-about-list{margin:0;padding:0;list-style:none}
			.wsp-about-list li{padding:7px 0;border-bottom:1px solid #f0f0f1}
			.wsp-about-list li:last-child{border-bottom:none}
			.wsp-about-list strong{color:#1d2327}

			.wsp-about-links{display:flex;flex-wrap:wrap;gap:8px}
		';
		wp_add_inline_style( 'common', $custom_css );
	} );
}

/** Render the About Us page. */
function wsp_mcp_about_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'wsp-mcp-ai-agents-connector' ) );
	}

	$campaign = 'about_page';

	$stats = array(
		array( '1,200+', __( 'Projects delivered', 'wsp-mcp-ai-agents-connector' ) ),
		array( '850+', __( 'Websites launched', 'wsp-mcp-ai-agents-connector' ) ),
		array( '500+', __( 'Happy clients worldwide', 'wsp-mcp-ai-agents-connector' ) ),
		array( '120+', __( 'AI automations deployed', 'wsp-mcp-ai-agents-connector' ) ),
		array( '10+', __( 'Years in digital services', 'wsp-mcp-ai-agents-connector' ) ),
		array( '99.9%', __( 'Client satisfaction rate', 'wsp-mcp-ai-agents-connector' ) ),
	);

	$services = array(
		__( 'WordPress Development', 'wsp-mcp-ai-agents-connector' ),
		__( 'Shopify & Ecommerce Development', 'wsp-mcp-ai-agents-connector' ),
		__( 'Search Engine Optimization (SEO)', 'wsp-mcp-ai-agents-connector' ),
		__( 'Pay Per Click / Google Ads Management', 'wsp-mcp-ai-agents-connector' ),
		__( 'Social Media Marketing', 'wsp-mcp-ai-agents-connector' ),
		__( 'AI Automation', 'wsp-mcp-ai-agents-connector' ),
		__( 'Logo & Brand Design', 'wsp-mcp-ai-agents-connector' ),
	);

	$values = array(
		array( __( 'Fast Response', 'wsp-mcp-ai-agents-connector' ), __( 'Swift, effective communication so you get the support you need promptly.', 'wsp-mcp-ai-agents-connector' ) ),
		array( __( 'World Class Designers', 'wsp-mcp-ai-agents-connector' ), __( 'Creativity and precision in every project, crafting visuals that elevate your brand.', 'wsp-mcp-ai-agents-connector' ) ),
		array( __( 'Best Quality', 'wsp-mcp-ai-agents-connector' ), __( 'Committed to excellence, ensuring every website, store, and campaign meets the highest standards.', 'wsp-mcp-ai-agents-connector' ) ),
	);

	$links = array(
		'about'     => array( __( 'About Us', 'wsp-mcp-ai-agents-connector' ), 'https://websensepro.com/about-us/' ),
		'services'  => array( __( 'Our Services', 'wsp-mcp-ai-agents-connector' ), 'https://websensepro.com/our-services/' ),
		'portfolio' => array( __( 'Portfolio', 'wsp-mcp-ai-agents-connector' ), 'https://websensepro.com/portfolio/' ),
		'contact'   => array( __( 'Contact Us', 'wsp-mcp-ai-agents-connector' ), 'https://websensepro.com/contact-us/' ),
	);

	$social = array(
		'YouTube'   => 'https://www.youtube.com/c/websensepro',
		'LinkedIn'  => 'https://www.linkedin.com/company/websensepro/',
		'Facebook'  => 'https://www.facebook.com/websensepro',
		'Instagram' => 'https://www.instagram.com/websensepro/',
		'TikTok'    => 'https://www.tiktok.com/@websensepro',
		'GitHub'    => 'https://github.com/websensepro1',
	);
	?>
	<div class="wsp-wrap">
		<div class="wsp-header">
			<h1>👋 <?php esc_html_e( 'About WebSensePro', 'wsp-mcp-ai-agents-connector' ); ?></h1>
		</div>
		<p class="wsp-desc">
			<?php esc_html_e( 'WSP MCP is built by WebSensePro, an AI-powered digital media agency delivering Web Development, WordPress, Shopify, SEO and AI Automation for growing businesses. We combine human expertise with AI-driven tools to deliver responsive websites, optimized costs, and clear communication for small and medium-sized businesses.', 'wsp-mcp-ai-agents-connector' ); ?>
		</p>

		<div class="wsp-about-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<div class="wsp-about-stat">
					<div class="wsp-about-stat-n"><?php echo esc_html( $stat[0] ); ?></div>
					<div class="wsp-about-stat-l"><?php echo esc_html( $stat[1] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="wsp-panels">
			<div class="wsp-panel">
				<div class="wsp-panel-h">🛠️ <?php esc_html_e( 'What we do', 'wsp-mcp-ai-agents-connector' ); ?></div>
				<div class="wsp-panel-body">
					<ul class="wsp-about-list">
						<?php foreach ( $services as $service ) : ?>
							<li><?php echo esc_html( $service ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>

			<div class="wsp-panel">
				<div class="wsp-panel-h">⭐ <?php esc_html_e( 'Why clients choose us', 'wsp-mcp-ai-agents-connector' ); ?></div>
				<div class="wsp-panel-body">
					<ul class="wsp-about-list">
						<?php foreach ( $values as $value ) : ?>
							<li><strong><?php echo esc_html( $value[0] ); ?></strong> — <?php echo esc_html( $value[1] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</div>

		<div class="wsp-panels">
			<div class="wsp-panel">
				<div class="wsp-panel-h">📬 <?php esc_html_e( 'Get in touch', 'wsp-mcp-ai-agents-connector' ); ?></div>
				<div class="wsp-panel-body">
					<ul class="wsp-about-list">
						<li><strong><?php esc_html_e( 'Email:', 'wsp-mcp-ai-agents-connector' ); ?></strong> <a href="mailto:info@websensepro.com">info@websensepro.com</a></li>
						<li><strong><?php esc_html_e( 'Phone:', 'wsp-mcp-ai-agents-connector' ); ?></strong> <a href="tel:+19177302010">+1 (917) 730-2010</a></li>
						<li><strong><?php esc_html_e( 'Offices:', 'wsp-mcp-ai-agents-connector' ); ?></strong> <?php esc_html_e( 'Denver, CO · Queens Village, NY · Karachi, Pakistan', 'wsp-mcp-ai-agents-connector' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="wsp-panel">
				<div class="wsp-panel-h">🔗 <?php esc_html_e( 'Learn more', 'wsp-mcp-ai-agents-connector' ); ?></div>
				<div class="wsp-panel-body">
					<p class="wsp-about-links">
						<?php foreach ( $links as $key => $link ) : ?>
							<a class="button" href="<?php echo esc_url( wsp_mcp_promo_url( $link[1], $key, $campaign ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $link[0] ); ?></a>
						<?php endforeach; ?>
					</p>
					<p class="wsp-about-links">
						<?php foreach ( $social as $name => $url ) : ?>
							<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $name ); ?></a>
						<?php endforeach; ?>
					</p>
				</div>
			</div>
		</div>
	</div>
	<?php
}
