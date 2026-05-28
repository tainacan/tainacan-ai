<?php
declare(strict_types=1);

namespace Tainacan\AI;

use Tainacan\AI\Admin\AdminPage;
use Tainacan\AI\Hooks\CollectionFormHook;
use Tainacan\AI\Hooks\ItemFormHook;
use Tainacan\AI\Hooks\MetadatumFormHook;
use Tainacan\AI\REST\API;
use Tainacan\AI\Extraction\ExtractionMetadata;
use Tainacan\AI\Extraction\PromptTemplates;
use Tainacan\AI\Support\CoreAIRequestLogging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static ?Plugin $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks(): void {
		register_activation_hook( TAINACAN_AI_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( TAINACAN_AI_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'init' ), 10 );
		add_filter( 'plugin_action_links_' . plugin_basename( TAINACAN_AI_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );

		$this->init_consent_api();
	}

	public function activate(): void {
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			deactivate_plugins( plugin_basename( TAINACAN_AI_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'Tainacan AI requires PHP 8.0 or higher.', 'tainacan-ai' ),
				esc_html__( 'Activation Error', 'tainacan-ai' ),
				array( 'back_link' => true )
			);
		}

		$this->set_default_options();
		flush_rewrite_rules();
	}

	public function deactivate(): void {
		global $wpdb;

		$like_keys = array(
			'_transient_tainacan_ai_%',
			'_transient_timeout_tainacan_ai_%',
		);

		foreach ( $like_keys as $like_key ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like_key
				)
			);
		}

		flush_rewrite_rules();
	}

	private function set_default_options(): void {
		$default_options = array(
			'default_preamble' => PromptTemplates::get_default_preamble(),
			'max_tokens'       => 2000,
			'temperature'      => 0.1,
			'request_timeout'  => 120,
			'cache_duration'   => 3600,
			'extract_exif'     => true,
			'consent_required' => true,
		);

		$existing = get_option( 'tainacan_ai_options', array() );
		update_option( 'tainacan_ai_options', wp_parse_args( $existing, $default_options ) );
	}

	private function init_consent_api(): void {
		$plugin = plugin_basename( TAINACAN_AI_PLUGIN_FILE );
		add_filter( "wp_consent_api_registered_{$plugin}", '__return_true' );

		add_action(
			'wp_enqueue_scripts',
			static function (): void {
				if ( function_exists( 'wp_add_cookie_info' ) ) {
					\wp_add_cookie_info(
						'tainacan_ai_cache',
						__( 'AI analysis cache', 'tainacan-ai' ),
						'functional',
						__( 'Stores analysis results to avoid repeated API calls', 'tainacan-ai' ),
						false,
						false,
						false
					);
				}
			}
		);
	}

	public static function has_consent(): bool {
		$options = get_option( 'tainacan_ai_options', array() );

		if ( empty( $options['consent_required'] ) ) {
			return true;
		}

		if ( function_exists( 'wp_has_consent' ) ) {
			return \wp_has_consent( 'functional' );
		}

		return current_user_can( 'manage_options' );
	}

	public function init(): void {
		$this->set_default_options();

		if ( ! class_exists( '\Tainacan\Repositories\Items' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Tainacan AI requires the Tainacan plugin to be active.', 'tainacan-ai' );
					echo '</p></div>';
				}
			);
			return;
		}

		if ( class_exists( '\Tainacan\Pages' ) ) {
			AdminPage::get_instance();
		}

		new API();
		new ItemFormHook();
		new CollectionFormHook();
		new MetadatumFormHook();

		ExtractionMetadata::get_instance()->init_hooks();

		add_action( 'wpai_features_initialized', array( CoreAIRequestLogging::class, 'register_integration' ) );
	}

	/**
	 * Add plugin settings link in the plugins list.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=tainacan_ai' ),
			__( 'Settings', 'tainacan-ai' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Return all plugin options.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_options(): array {
		$options = get_option( 'tainacan_ai_options', array() );
		return is_array( $options ) ? $options : array();
	}
}
