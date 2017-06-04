<?php
/**
 * WP Scripts Concat
 *
 * Javascript concatenation of individual script files into one resource request.
 *
 * @package   WP_Scripts_Concat
 * @author    Jonathan Harris <jon@spacedmonkey.co.uk>
 * @license   GPL-2.0+
 * @link      http://www.spacedmonkey.com
 * @copyright 2017 Jonathan Harris
 *
 *
 * @wordpress-plugin
 * Plugin Name:       Script Concatenator
 * Plugin URI:        http://www.spacedmonkey.com/plugins
 * Description: 	  Javascript concatenation of individual script files into one resource request.
 * Version:           1.0.0
 * Author: 			  Jonathan Harris
 * Author URI: 		  http://www.spacedmonkey.com
 * Text Domain:       wp-concatenator-locale
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/spacedmonkey/wp-concatenator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'ALLOW_GZIP_COMPRESSION' ) ) {
	define( 'ALLOW_GZIP_COMPRESSION', false );
}
require_once( dirname( __FILE__ ) . '/concat-utils.php' );



class WP_Scripts_Concat extends WP_Scripts {
	private $old_scripts;
	public $allow_gzip_compression;
	private $utils;
	
	function __construct( $scripts ) {
		if ( empty( $scripts ) || ! ( $scripts instanceof WP_Scripts ) ) {
			$this->old_scripts = new WP_Scripts();
		} else {
			$this->old_scripts = $scripts;
		}

		$this->utils = new Concatenator_Utils();

		// Unset all the object properties except our private copy of the scripts object.
		// We have to unset everything so that the overload methods talk to $this->old_scripts->whatever
		// instead of $this->whatever.
		foreach ( array_keys( get_object_vars( $this ) ) as $key ) {
			if ( in_array( $key, array( 'old_scripts', 'utils' ) ) ) {
				continue;
			}
			unset( $this->$key );
		}
	}

	function do_items( $handles = false, $group = false ) {
		$handles     = false === $handles ? $this->queue : (array) $handles;
		$javascripts = array();
		$siteurl     = apply_filters( 'ngx_http_concat_site_url', $this->base_url );

		$this->all_deps( $handles );
		$level = 0;
		foreach ( $this->to_do as $key => $handle ) {
			if ( in_array( $handle, $this->done ) || ! isset( $this->registered[ $handle ] ) ) {
				continue;
			}

			if ( ! $this->registered[ $handle ]->src ) { // Defines a group.
				$this->done[] = $handle;
				continue;
			}

			if ( 0 === $group && $this->groups[ $handle ] > 0 ) {
				$this->in_footer[] = $handle;
				unset( $this->to_do[ $key ] );
				continue;
			}

			if ( false === $group && in_array( $handle, $this->in_footer, true ) ) {
				$this->in_footer = array_diff( $this->in_footer, (array) $handle );
			}

			$obj           = $this->registered[ $handle ];
			$js_url        = $obj->src;
			$js_url_parsed = parse_url( $js_url );
			$extra         = $obj->extra;
			// Check for scripts added from wp_add_inline_script()
			$before_handle = $this->print_inline_script( $handle, 'before', false );
			$after_handle  = $this->print_inline_script( $handle, 'after', false );
			if ( $before_handle ) {
				$before_handle = sprintf( "<script type='text/javascript'>\n%s\n</script>\n", $before_handle );
			}
			if ( $after_handle ) {
				$after_handle = sprintf( "<script type='text/javascript'>\n%s\n</script>\n", $after_handle );
			}

			// Don't concat by default
			$do_concat = false;

			// Only try to concat static js files
			if ( false !== strpos( $js_url_parsed['path'], '.js' ) ) {
				$do_concat = true;
			}


			if ( isset( $extra['conditional'] ) ) {
				$do_concat = false;
			}

			// Don't try to concat externally hosted scripts
			$is_internal_url = $this->utils->is_internal_url( $js_url, $siteurl );
			if ( ! $is_internal_url ) {
				$do_concat = false;
			}

			// Concat and canonicalize the paths only for
			// existing scripts that aren't outside ABSPATH
			$js_realpath = $this->utils->realpath( $js_url, $siteurl );

			if ( ! $js_realpath || 0 !== strpos( $js_realpath, ABSPATH ) ) {
				$do_concat = false;
			} else {
				$js_url_parsed['path'] = substr( $js_realpath, strlen( ABSPATH ) - 1 );
			}


			// Allow plugins to disable concatenation of certain scripts.
			$do_concat = apply_filters( 'js_do_concat', $do_concat, $handle );

			if ( true === $do_concat ) {
				if ( ! isset( $javascripts[ $level ] ) ) {
					$javascripts[ $level ]['type'] = 'concat';
				}

				$javascripts[ $level ]['paths'][]   = $js_url_parsed['path'];
				$javascripts[ $level ]['handles'][] = $handle;

				// Add inline scripts to Javascripts array for later processing
				if ( $before_handle ) {
					$javascripts[ $level ]['extras']['before'][] = $before_handle;
				}
				if ( $after_handle ) {
					$javascripts[ $level ]['extras']['after'][] = $after_handle;
				}

			} else {
				$level ++;
				$javascripts[ $level ]['type']   = 'do_item';
				$javascripts[ $level ]['handle'] = $handle;
				$level ++;
			}
			unset( $this->to_do[ $key ] );
		}

		if ( empty( $javascripts ) ) {
			return $this->done;
		}

		foreach ( $javascripts as $js_array ) {
			if ( 'do_item' == $js_array['type'] ) {
				if ( $this->do_item( $js_array['handle'], $group ) ) {
					$this->done[] = $js_array['handle'];
				}
			} else if ( 'concat' == $js_array['type'] ) {
				array_map( array( $this, 'print_extra_script' ), $js_array['handles'] );

				$js       = $js_array['paths'];
				$paths    = array_map( function ( $url ) {
					return ABSPATH . $url;
				}, $js );
				$mtime    = max( array_map( 'filemtime', $paths ) );
				$path_str = implode( $js, ',' );

				$path_64 = urlencode( base64_encode( $path_str ) );

				$href = add_query_arg( array(
					'load' => $path_64,
					'm'    => $mtime,
					'c'    => (int) $this->allow_gzip_compression
				), plugins_url( 'concat.php', __FILE__ ) );

				$this->done = array_merge( $this->done, $js_array['handles'] );

				// Print before/after scripts from wp_inline_scripts() and concatenated script tag
				if ( isset( $js_array['extras']['before'] ) ) {
					foreach ( $js_array['extras']['before'] as $inline_before ) {
						echo $inline_before;
					}
				}
				echo "<script type='text/javascript' src='$href'></script>\n";
				if ( isset( $js_array['extras']['after'] ) ) {
					foreach ( $js_array['extras']['after'] as $inline_after ) {
						echo $inline_after;
					}
				}
			}
		}

		parent::do_items( $handle, $group );

		return $this->done;
	}


	function __isset( $key ) {
		return isset( $this->old_scripts->$key );
	}

	function __unset( $key ) {
		unset( $this->old_scripts->$key );
	}

	function &__get( $key ) {
		return $this->old_scripts->$key;
	}

	function __set( $key, $value ) {
		$this->old_scripts->$key = $value;
	}
}

add_action( 'init', function () {
	global $wp_scripts;

	$wp_scripts                         = new WP_Scripts_Concat( $wp_scripts );
	$wp_scripts->allow_gzip_compression = ALLOW_GZIP_COMPRESSION;
} );


