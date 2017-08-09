<?php
/*
 * It will also replace the relative paths in CSS files with absolute paths.
 */


require_once( __DIR__ . '/concat-utils.php' );

/* Config */
$concat_max_files = 150;
$concat_unique    = true;
$concat_types     = array(
	'css' => 'text/css',
	'js'  => 'application/x-javascript'
);

/* Constants */
// By default determine the document root from this scripts path in the plugins dir (you can hardcode this define)
define( 'CONCAT_FILES_ROOT', substr( dirname( __DIR__ ), 0, strpos( dirname( __DIR__ ), '/wp-content' ) ) );

function concat_http_status_exit( $status ) {
	switch ( $status ) {
		case 200:
			$text = 'OK';
			break;
		case 400:
			$text = 'Bad Request';
			break;
		case 403:
			$text = 'Forbidden';
			break;
		case 404:
			$text = 'Not found';
			break;
		case 500:
			$text = 'Internal Server Error';
			break;
		default:
			$text = '';
	}

	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if ( 'HTTP/1.1' != $protocol && 'HTTP/1.0' != $protocol ) {
		$protocol = 'HTTP/1.0';
	}

	@header( "$protocol $status $text", true, $status );
	exit();
}

function concat_get_mtype( $file ) {
	global $concat_types;

	$lastdot_pos = strrpos( $file, '.' );
	if ( false === $lastdot_pos ) {
		return false;
	}

	$ext = substr( $file, $lastdot_pos + 1 );

	return isset( $concat_types[ $ext ] ) ? $concat_types[ $ext ] : false;
}

function concat_get_path( $uri ) {
	if ( ! strlen( $uri ) ) {
		concat_http_status_exit( 400 );
	}

	if ( false !== strpos( $uri, '..' ) || false !== strpos( $uri, "\0" ) ) {
		concat_http_status_exit( 400 );
	}

	return CONCAT_FILES_ROOT . ( '/' != $uri[0] ? '/' : '' ) . $uri;
}

/* Main() */
if ( ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ) ) ) {
	concat_http_status_exit( 400 );
}

// /_static/??/foo/bar.css,/foo1/bar/baz.css?m=293847g
// or
// /_static/??-eJzTT8vP109KLNJLLi7W0QdyDEE8IK4CiVjn2hpZGluYmKcDABRMDPM=
$load = $_GET['load'];

$args = base64_decode( urldecode( $load ) );

$compress       = ( isset( $_GET['c'] ) && $_GET['c'] );
$force_gzip     = ( $compress && 'gzip' == $_GET['c'] );
$expires_offset = 31536000; // 1 year

// /foo/bar.css,/foo1/bar/baz.css
$args = explode( ',', $args );
if ( ! $args ) {
	concat_http_status_exit( 400 );
}
// array( '/foo/bar.css', '/foo1/bar/baz.css' )
if ( 0 == count( $args ) || count( $args ) > $concat_max_files ) {
	concat_http_status_exit( 400 );
}

$last_modified = 0;
$pre_output    = '';
$output        = '';
$utils         = new Concatenator_Utils();

foreach ( $args as $uri ) {
	$fullpath = concat_get_path( $uri );
	if ( ! file_exists( $fullpath ) ) {
		concat_http_status_exit( 404 );
	}

	$mime_type = concat_get_mtype( $fullpath );
	if ( ! in_array( $mime_type, $concat_types ) ) {
		concat_http_status_exit( 400 );
	}

	if ( $concat_unique ) {
		if ( ! isset( $last_mime_type ) ) {
			$last_mime_type = $mime_type;
		}

		if ( $last_mime_type != $mime_type ) {
			concat_http_status_exit( 400 );
		}
	}

	$stat = stat( $fullpath );
	if ( false === $stat ) {
		concat_http_status_exit( 500 );
	}

	if ( $stat['mtime'] > $last_modified ) {
		$last_modified = $stat['mtime'];
	}

	$buf = file_get_contents( $fullpath );
	if ( false === $buf ) {
		concat_http_status_exit( 500 );
	}

	if ( 'text/css' == $mime_type ) {
		$dirpath = dirname( $uri );

		// url(relative/path/to/file) -> url(/absolute/and/not/relative/path/to/file)
		$buf = $utils->relative_path_replace( $buf, $dirpath );

		// AlphaImageLoader(...src='relative/path/to/file'...) -> AlphaImageLoader(...src='/absolute/path/to/file'...)
		$buf = preg_replace(
			'/(Microsoft.AlphaImageLoader\s*\([^\)]*src=(?:\'|")?)([^\/\'"\s\)](?:(?<!http:|https:).)*)\)/isU',
			'$1' . ( $dirpath == '/' ? '/' : $dirpath . '/' ) . '$2)',
			$buf
		);

		// The @charset rules must be on top of the output
		if ( 0 === strpos( $buf, '@charset' ) ) {
			preg_replace_callback(
				'/(?P<charset_rule>@charset\s+[\'"][^\'"]+[\'"];)/i',
				function ( $match ) {
					global $pre_output;

					if ( 0 === strpos( $pre_output, '@charset' ) ) {
						return '';
					}

					$pre_output = $match[0] . "\n" . $pre_output;

					return '';
				},
				$buf
			);
		}

		// Move the @import rules on top of the concatenated output.
		// Only @charset rule are allowed before them.
		if ( false !== strpos( $buf, '@import' ) ) {
			$buf = preg_replace_callback(
				'/(?P<pre_path>@import\s+(?:url\s*\()?[\'"\s]*)(?P<path>[^\'"\s](?:https?:\/\/.+\/?)?.+?)(?P<post_path>[\'"\s\)]*;)/i',
				function ( $match ) use ( $dirpath ) {
					global $pre_output;

					if ( 0 !== strpos( $match['path'], 'http' ) && '/' != $match['path'][0] ) {
						$pre_output .= $match['pre_path'] . ( $dirpath == '/' ? '/' : $dirpath . '/' ) .
						               $match['path'] . $match['post_path'] . "\n";
					} else {
						$pre_output .= $match[0] . "\n";
					}

					return '';
				},
				$buf
			);
		}
	}

	// Remove comments
	$buf = preg_replace( '#^\s*//.+$#m', "", $buf );
	$buf = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $buf );

	$buf = trim( $buf );

	if ( 'application/x-javascript' == $mime_type ) {
		$output .= "$buf;\n";
	} else {

		// Remove space after colons
		$buf = str_replace( ': ', ':', $buf );
		// Remove whitespace
		$buf = str_replace( array( "\r\n", "\r", "\n", "\t", '  ', '    ', '    ' ), '', $buf );

		$output .= "$buf";
	}
}

$output = $pre_output . $output;

header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
header( 'Content-Length: ' .  strlen( $output ) );
header( "Content-Type: $mime_type" );
header( 'Expires: ' . gmdate( "D, d M Y H:i:s", time() + $expires_offset ) . ' GMT' );
header( "Cache-Control: public, max-age=$expires_offset" );

if ( $compress && ! ini_get( 'zlib.output_compression' ) && 'ob_gzhandler' != ini_get( 'output_handler' ) && isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) {
	header( 'Vary: Accept-Encoding' ); // Handle proxies
	if ( false !== stripos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'deflate' ) && function_exists( 'gzdeflate' ) && ! $force_gzip ) {
		header( 'Content-Encoding: deflate' );
		$output = gzdeflate( $output, 3 );
	} elseif ( false !== stripos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) && function_exists( 'gzencode' ) ) {
		header( 'Content-Encoding: gzip' );
		$output = gzencode( $output, 3 );
	}
}


echo $output;
