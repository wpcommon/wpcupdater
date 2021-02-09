# WPCUpdater Class
Although we maintain a separated versioning controls for each themes and plugins, the core files are always original and untouched.

We included this WPCUpdater class in the main plugin file (or functions.php for theme) for the purposes of automatic updates only. You may also choose to download the original source without our updater.

## WPCUpdater_Plugin
This code will be included at the beginning of the main plugin file.

```php
// Start of WPCUpdater
if ( ! class_exists( 'WPCUpdater_Plugin' ) ) {
	include( dirname( __FILE__ ) . '/WPCUpdater.Plugin.php' );
}

add_action(
	'init',
	function() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		$WPCUpdater_Plugin = new WPCUpdater_Plugin(
			__FILE__,
			array(
				'id' => 0, // The ID of the product to receive updates.
				'fname' => 'Hello Dolly', // The name of the product to receive updates.
				'version' => get_plugin_data( __FILE__ )['Version'],
			)
		);
	}
);
// End of WPCUpdater
```