<?php defined( 'ABSPATH' ) or die();

/**
 * WPCUpdater Plugin Class
 *
 * Although we maintain a separated versioning controls for each
 * themes and plugins, the core files are always original and untouched.
 *
 * We included this WPCUpdater class in the main plugin file (or
 * functions.php for theme) for the purposes of automatic updates
 * only. You may also choose to download the original source
 * without our updater.
 *
 * @author WPC Alpha <alpha@wpcommon.com>
 * @package Easy Digital Downloads
 * @since 1.0.0
 */

class WPCUpdater_Plugin {
	private $api_url = 'https://wpcommon.com';
	private $api_data = array();
	private $name = '';
	private $slug = '';
	private $id = '';
	private $fname = '';
	private $version = '';
	private $wp_override = false;
	private $beta = false;
	private $update_key = '';
	private $cache_key = '';

	public function __construct( $_plugin_file, $_api_data = null ) {
		global $edd_plugin_data;
		$this->api_url = trailingslashit( $this->api_url );
		$this->api_data = $_api_data;
		$this->name = plugin_basename( $_plugin_file );
		$this->slug = basename( $_plugin_file, '.php' );
		$this->id = $this->api_data['id'];
		$this->fname = $this->api_data['fname'];
		$this->version = $this->api_data['version'];
		$this->wp_override = isset( $this->api_data['wp_override'] ) ? (bool) $this->api_data['wp_override'] : false;
		$this->beta = isset( $this->api_data['beta'] ) ? (bool) $this->api_data['beta'] : false;
		$this->update_key = trim( get_option( 'wpc_' . $this->id . '_update_key' ) );
		$this->cache_key = 'wpc_' . md5( serialize( $this->slug . $this->update_key . $this->beta ) );

		$edd_plugin_data[ $this->slug ] = $this->api_data;
		do_action( 'post_edd_sl_plugin_updater_setup', $edd_plugin_data );

		$this->init();
	}

	private function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_plugins_check' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'admin_init_register_option' ) );
		add_action( 'admin_init', array( $this, 'admin_init_key_action' ) );
		add_action( 'admin_init', array( $this, 'admin_init_key_ping' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices_no_key' ) );
		$this->add_key_card();
	}

	public function update_plugins_check( $_transient_data ) {
		global $pagenow;
		if ( ! is_object( $_transient_data ) ) {
			$_transient_data = new stdClass();
		}

		if ( is_multisite() && $pagenow == 'plugins.php' ) {
			return $_transient_data;
		}

		if ( ! empty( $_transient_data->response ) && ! empty( $_transient_data->response[ $this->name ] ) && $this->wp_override === false ) {
			return $_transient_data;
		}

		$current = $this->api_version_cache();
		if ( $current !== false && is_object( $current ) && isset( $current->new_version ) ) {
			if ( version_compare( $this->version, $current->new_version, '<' ) ) {
				$_transient_data->response[ $this->name ] = $current;
			} else {
				$_transient_data->no_update[ $this->name ] = $current;
			}
		}

		$_transient_data->last_checked = time();
		$_transient_data->checked[ $this->name ] = $this->version;

		return $_transient_data;
	}

	public function plugins_api_filter( $_data, $_action = '', $_args = null ) {
		if ( $_action != 'plugin_information' ) {
			return $_data;
		}

		$current = $this->api_version_cache();
		if ( $current->slug == $_args->slug ) {
			$_data = $current;
			if ( isset( $_data->sections ) && ! is_array( $_data->sections ) ) {
				$_data->sections = $this->convert_object_to_array( $_data->sections );
			}

			if ( isset( $_data->banners ) && ! is_array( $_data->banners ) ) {
				$_data->banners = $this->convert_object_to_array( $_data->banners );
			}

			if ( isset( $_data->icons ) && ! is_array( $_data->icons ) ) {
				$_data->icons = $this->convert_object_to_array( $_data->icons );
			}

			if ( isset( $_data->contributors ) && ! is_array( $_data->contributors ) ) {
				$_data->contributors = $this->convert_object_to_array( $_data->contributors );
			}
		}

		return $_data;
	}

	public function admin_init_register_option() {
		register_setting(
			'wpc_' . $this->id . '_update',
			'wpc_' . $this->id . '_update_key',
			function( $n ) {
				$o = get_option( 'wpc_' . $this->id . '_update_key' );
				if ( $o && $o != $n ) {
					delete_option( 'wpc_' . $this->id . '_update_status' );
				}

				return $n;
			}
		);
	}

	public function admin_init_key_action() {
		if ( isset( $_POST[ 'wpc_' . $this->id . '_activate' ] ) || isset( $_POST[ 'wpc_' . $this->id . '_deactivate' ] ) ) {
			if ( check_admin_referer( 'wpc_' . $this->id . '_nonce', 'wpc_' . $this->id . '_nonce' ) ) {
				$api_request = $this->api_request( ( isset( $_POST[ 'wpc_' . $this->id . '_activate' ] ) ? 'activate_license' : 'deactivate_license' ), $_POST[ 'wpc_' . $this->id . '_update_key' ] );
				if ( ! empty( $api_request ) ) {
					update_option( 'wpc_' . $this->id . '_update_status', $api_request->license );
					$this->set_cached_key( 'check_license', $api_request );
				} else {
					delete_option( 'wpc_' . $this->id . '_update_status' );
				}

				update_option( 'wpc_' . $this->id . '_update_key', $_POST[ 'wpc_' . $this->id . '_update_key' ] );
				wp_redirect( admin_url( 'admin.php?page=wpc_updater' ) );
				exit();
			}
		}
	}

	public function admin_init_key_ping() {
		$status = get_option( 'wpc_' . $this->id . '_update_status' );
		if ( $status !== false && $status == 'valid' ) {
			$api_request = $this->get_cached_key( 'check_license' );
			if ( $api_request === false ) {
				$api_request = $this->api_request( 'check_license', $this->update_key );
				if ( ! empty( $api_request ) ) {
					$this->set_cached_key( 'check_license', $api_request );
				}
			}

			if ( $api_request->license != $status ) {
				update_option( 'wpc_' . $this->id . '_update_status', $api_request->license );
			}
		}
	}

	public function admin_notices_no_key() {
		$status = get_option( 'wpc_' . $this->id . '_update_status' );
		if ( $status === false || $status != 'valid' ) {
			echo '
				<div class="error">
					<p>
						' . sprintf( __( '<strong>%1$s</strong> is installed but not receiving updates from WPCommon.com. Please <a href="%2$s">activate</a> WPCUpdater or install the one without automatic updates <a href="%3$s" target="_blank">here</a>.', 'wpcommon' ), $this->fname, admin_url( 'admin.php?page=wpc_updater' ), 'https://wpcommon.com/my-account/downloads/' ) . '
					</p>
				</div>
			';
		}
	}

	private function convert_object_to_array( $_data ) {
		$_new_data = array();
		foreach ( $_data as $key => $value ) {
			$_new_data[ $key ] = is_object( $value ) ? $this->convert_object_to_array( $value ) : $value;
		}

		return $_new_data;
	}

	private function api_version_cache() {
		$api_request = $this->get_cached_key( 'get_version' );
		if ( $api_request === false ) {
			$api_request = $this->api_request( 'get_version', $this->update_key );
			if ( ! empty( $api_request ) ) {
				$api_request->plugin = $this->name;
				$api_request->id = $this->name;
				$this->set_cached_key( 'get_version', $api_request );
			}
		}

		return $api_request;
	}

	private function api_request( $_action, $_key ) {
		global $wp_version, $edd_plugin_url_available;
		$hashes = md5( $this->api_url );
		if ( ! is_array( $edd_plugin_url_available ) || ! isset( $edd_plugin_url_available[ $hashes ] ) ) {
			$url_parts = parse_url( $this->api_url );
			$scheme = ! empty( $url_parts['scheme'] ) ? $url_parts['scheme'] : 'http';
			$host = ! empty( $url_parts['host'] ) ? $url_parts['host'] : '';
			$port = ! empty( $url_parts['port'] ) ? ':' . $url_parts['port'] : '';
			if ( empty( $host ) ) {
				$edd_plugin_url_available[ $hashes ] = false;
			} else {
				$url = $scheme . '://' . $host . $port;
				$response = wp_remote_get(
					$url,
					array(
						'timeout' => 15,
						'sslverify' => true,
					)
				);
				$edd_plugin_url_available[ $hashes ] = is_wp_error( $response ) ? false : true;
			}
		}

		if ( $edd_plugin_url_available[ $hashes ] === false ) {
			return false;
		}

		$response = wp_remote_post(
			$this->api_url,
			array(
				'timeout' => 15,
				'sslverify' => true,
				'body' => array(
					'edd_action' => $_action,
					'license' => $_key,
					'item_name' => urlencode( $this->fname ),
					'url' => home_url(),
					'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				),
			)
		);

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			$response = json_decode( wp_remote_retrieve_body( $response ) );
		}

		if ( $response && isset( $response->sections ) ) {
			$response->sections = maybe_unserialize( $response->sections );
			if ( ! empty( $response->sections ) ) {
				foreach ( $response->sections as $key => $section ) {
					$response->$key = (array) $section;
				}
			}
		}

		if ( $response && isset( $response->banners ) ) {
			$response->banners = maybe_unserialize( $response->banners );
		}

		if ( $response && isset( $response->icons ) ) {
			$response->icons = maybe_unserialize( $response->icons );
		}

		return $response;
	}

	private function get_cached_key( $_cache_key = '' ) {
		return get_transient( $this->cache_key . '_' . $_cache_key );
	}

	private function set_cached_key( $_cache_key = '', $_value = '' ) {
		set_transient( $this->cache_key . '_' . $_cache_key, $_value, 10800 );
	}

	private function add_key_card() {
		add_filter(
			'wpc_updater_child',
			function() {
				$key = $this->update_key;
				$status = get_option( 'wpc_' . $this->id . '_update_status' );

				echo '
					<div class="card">
						<h2 class="title">' . $this->fname . '</h2>
						<form method="post" action="options.php">
				';

				wp_nonce_field( 'wpc_' . $this->id . '_nonce', 'wpc_' . $this->id . '_nonce' );

				echo '
							<table class="form-table">
								<tbody>
				';

				if ( $status !== false && $status == 'valid' ) {
					echo '
									<tr>
										<th scope="row"><label for="wpc_' . $this->id . '_update_key">' . __( 'Update Key', 'wpcommon' ) . '</label></th>
										<td>
											<input type="hidden" name="wpc_' . $this->id . '_update_key" value="' . $key . '" />
											<input type="text" class="regular-text" id="wpc_' . $this->id . '_update_key" value="' . $key . '" required="required" disabled="disabled" />
										</td>
									</tr>
									<tr>
											<th scope="row"><span style="color:green;">' . __( 'Active', 'wpcommon' ) . '</span></th>
											<td><input type="submit" class="button" name="wpc_' . $this->id . '_deactivate" value="' . __( 'Deactivate', 'wpcommon' ) . '"/></td>
									</tr>
					';
				} else {
					echo '
									<tr>
										<th scope="row"><label for="wpc_' . $this->id . '_update_key">' . __( 'Update Key', 'wpcommon' ) . '</label></th>
										<td><input type="text" class="regular-text" id="wpc_' . $this->id . '_update_key" name="wpc_' . $this->id . '_update_key" value="' . $key . '" required="required" /></td>
									</tr>
									<tr>
										<th scope="row"><span style="color:red;">' . __( 'Inactive', 'wpcommon' ) . '</span></th>
										<td><input type="submit" class="button" name="wpc_' . $this->id . '_activate" value="' . __( 'Activate Key', 'wpcommon' ) . '"/></td>
									</tr>
					';
				}

				echo '
								</tbody>
							</table>
						</form>
					</div>
				';
			}
		);
	}
}

if ( ! class_exists( 'WPCUpdater_Admin' ) ) {
	class WPCUpdater_Admin {
		public function __construct() {
			add_action(
				'admin_menu',
				function() {
					add_menu_page(
						'WPCUpdater',
						'WPCUpdater',
						'manage_options',
						'wpc_updater',
						function() {
							echo '<div class="wrap"><h1>' . __( 'WPCUpdater', 'wpcommon' ) . '</h1><p>' . sprintf( __( 'Enter update key below to receive automatic updates from WPCommon.com. You may find your key <a href="%1$s" target="_blank">here.</a>', 'wpcommon' ), 'https://wpcommon.com/my-account/keys/' ) . '</p>';
							apply_filters( 'wpc_updater_child', null );
							echo '</div>';
						},
						'dashicons-wordpress',
						2
					);
				}
			);
		}
	}

	$WPCUpdater_Admin = new WPCUpdater_Admin();
}
