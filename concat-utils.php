<?php

class Concatenator_Utils {
	public function is_internal_url( $test_url, $site_url ) {
		$test_url_parsed = parse_url( $test_url );
		$site_url_parsed = parse_url( $site_url );

		if ( isset( $test_url_parsed['host'] )
		     && $test_url_parsed['host'] !== $site_url_parsed['host']
		) {
			return false;
		}

		if ( isset( $site_url_parsed['path'] )
		     && 0 !== strpos( $test_url_parsed['path'], $site_url_parsed['path'] )
		     && isset( $test_url_parsed['host'] ) //and if the URL of enqueued style is not relative
		) {
			return false;
		}

		return true;
	}

	public function realpath( $url, $site_url ) {

		if ( filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
			$url = $site_url . $url;
		}
		$path = str_replace( WP_PLUGIN_URL, WP_PLUGIN_DIR, $url );
		$path = str_replace( WPMU_PLUGIN_URL, WPMU_PLUGIN_DIR, $path );
		$path = str_replace( get_template_directory_uri(), TEMPLATEPATH, $path );
		$path = str_replace( get_stylesheet_directory_uri(), STYLESHEETPATH, $path );
		$path = str_replace( includes_url(), ABSPATH . WPINC . '/', $path );
		$path = str_replace( admin_url(), ABSPATH . 'wp-admin/', $path );

		return realpath( $path );
	}

	public function relative_path_replace( $buf, $dirpath ) {
		// url(relative/path/to/file) -> url(/absolute/and/not/relative/path/to/file)
		$buf = preg_replace(
			'/(:?\s*url\s*\()\s*(?:\'|")?\s*([^\/\'"\s\)](?:(?<!data:|http:|https:|[\(\'"]#|%23).)*)[\'"\s]*\)/isU',
			'$1' . ( $dirpath == '/' ? '/' : $dirpath . '/' ) . '$2)',
			$buf
		);

		return $buf;
	}
}
