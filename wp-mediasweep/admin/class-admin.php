<?php
/**
 * Admin page shell: registers the MediaSweep menu and loads the React app.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Top-level menu: MediaSweep.
	 */
	public static function menu() {
		add_menu_page(
			__( 'MediaSweep', 'mediasweep' ),
			__( 'MediaSweep', 'mediasweep' ),
			'manage_options',
			'mediasweep',
			array( __CLASS__, 'render_page' ),
			'dashicons-images-alt2',
			58
		);
	}

	/**
	 * Enqueue the SPA assets on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_mediasweep' !== $hook ) {
			return;
		}

		// WordPress component libraries the app depends on.
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-i18n' );
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script( 'wp-compose' );
		wp_enqueue_style( 'wp-components' );

		$build = MSW_PLUGIN_DIR . 'admin/assets/build';
		$url   = MSW_PLUGIN_URL . 'admin/assets/build';

		if ( file_exists( $build . '/app.asset.php' ) ) {
			$asset = include $build . '/app.asset.php';
			wp_enqueue_script(
				'mediasweep-app',
				$url . '/app.js',
				isset( $asset['dependencies'] ) ? $asset['dependencies'] : array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
				isset( $asset['version'] ) ? $asset['version'] : MSW_VERSION,
				true
			);
			wp_enqueue_style(
				'mediasweep-app',
				MSW_PLUGIN_URL . 'admin/assets/app.css',
				array( 'wp-components' ),
				MSW_VERSION
			);
		}

		wp_localize_script(
			'mediasweep-app',
			'mswConfig',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'mediasweep/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'version'  => MSW_VERSION,
				'backends' => array(
					'imagick' => MSW_Backend_Imagick::available(),
					'gd'      => MSW_Backend_GD::available(),
				),
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'mediasweep-app', 'mediasweep', MSW_PLUGIN_DIR . 'languages' );
		}
	}

	/**
	 * Render the app mount point.
	 */
	public static function render_page() {
		?>
		<div class="wrap msw-wrap">
			<div id="msw-app"></div>
			<noscript>
				<p><?php esc_html_e( 'MediaSweep requires JavaScript.', 'mediasweep' ); ?></p>
			</noscript>
		</div>
		<?php
	}
}
