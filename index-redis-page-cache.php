<?php
/*
Plugin Name: Redis Page Cache
Plugin URI: http://eth.pw/rpc
Version: 1.0
Description: Manage settings for full-page caching powered by Redis.
Author: Erick Hitter
Author URI: https://ethitter.com/

This software is based on WP Redis Cache by Benjamin Adams, copyright 2013.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * GLOBAL CONFIGURATION
 */
global $redis_page_cache_config;

$redis_page_cache_config = array(
	'debug'                   => false,
	'debug_messages'          => '',
	'stats'                   => false,
	'cached'                  => false,
	'server_ip'               => '127.0.0.1',
	'secret_string'           => 'changeme',
	'redis_server'            => '127.0.0.1',
	'redis_port'              => 6379,
	'redis_socket'            => null,
	'redis_db'                => 0,
	'cache_version'           => 0,
	'cache_headers'           => true,
	'additional_headers'      => array( 'link', 'x-hacker', 'x-pingback' ),
	'query_strings_to_ignore' => array(), // common tracking strings are automatically excluded
	'minify'                  => false,
);

// Uncomment either option below to fix the values here and disable the admin UI
// $redis_page_cache_config['cache_duration'] = 43200;
// $redis_page_cache_config['unlimited']      = false;

// Modify this function to introduce custom handling when exceptions occur
function redis_page_cache_exception_handler( $exception ) {
	return;
}

/**
 * END GLOBAL CONFIGURATION
 *
 * DO NOT EDIT BELOW THIS LINE!
 */

// Start the timer so we can track the page load time
if ( $redis_page_cache_config['debug'] || $redis_page_cache_config['stats'] ) {
	$start = microtime();
}

// Make run-time additions to configuration
$redis_page_cache_config['current_url'] = redis_page_cache_get_clean_url();
$redis_page_cache_config['redis_key']   = md5( 'v' . $redis_page_cache_config['cache_version'] . '-' . $redis_page_cache_config['current_url'] );
$redis_page_cache_config['redis_key']   = redis_page_cache_set_device_key( $redis_page_cache_config['redis_key'] );

/**
 * UTILITY FUNCTIONS
 */

/**
 * Compute microtime from a timestamp
 *
 * @return float
 */
function redis_page_cache_get_micro_time( $time ) {
	list( $usec, $sec ) = explode( " ", $time );
	return ( (float) $usec + (float) $sec );
}

/**
 * Count seconds elapsed between two microtime() timestampes
 *
 * @param string $start
 * @param string $end
 * @param int $precision
 * @return float
 */
function redis_page_cache_time_elapsed( $start, $end ) {
	return round( @redis_page_cache_get_micro_time( $end ) - @redis_page_cache_get_micro_time( $start ), 5 );
}

/**
 * Is the current request a refresh request with the correct secret key?
 *
 * @return bool
 */
function redis_page_cache_refresh_has_secret( $secret ) {
	return isset( $_GET['refresh'] ) && $secret == $_GET['refresh'];
}

/**
 * Does current request include a refresh request?
 *
 * @return bool
 */
function redis_page_cache_request_has_secret( $secret ) {
	return false !== strpos( $_SERVER['REQUEST_URI'], "refresh=${secret}" );
}

/**
 * Set proper IP address for proxied requests
 *
 * @return null
 */
function redis_page_cache_handle_cdn_remote_addressing() {
	// so we don't confuse the cloudflare server
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
	}
}

/**
 * Prepare a URL for use as a cache key
 *
 * If the URL is too malformed to parse, a one-time cache is set using microtime().
 *
 * @return string
 */
function redis_page_cache_get_clean_url() {
	static $url;

	if ( ! $url ) {
		global $redis_page_cache_config;

		$proto = 'http';
		if ( isset( $_SERVER['HTTPS'] ) && ( 'on' === strtolower( $_SERVER['HTTPS'] ) || '1' === $_SERVER['HTTPS'] ) ) {
			$proto .= 's';
		} elseif ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
			$proto .= 's';
		}

		$url = parse_url( $proto . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
		if ( $url ) {
			// Query strings create their own caches, so we reduce proliferation by ignoring certain common strings
			$qs = '';
			if ( ! empty( $_GET ) ) {
				$ignore = array( 'c', 'flush', 'secret', 'redis-page-cache-purge', 'utm_source', 'utm_medium', 'utm_term', 'utm_content', 'utm_campaign', 'fb_action_ids', 'fb_action_types', 'fb_ref', 'fb_source', 'fb_aggregation_id', );
				$ignore = array_merge( $ignore, $redis_page_cache_config['query_strings_to_ignore'] );
				$ignore = array_flip( $ignore );

				$_qs = array_diff_key( $_GET, $ignore );

				if ( ! empty( $_qs ) ) {
					$qs = '?';
					foreach ( $_qs as $key => $value ) {
						if ( strlen( $qs ) > 1 ) {
							$qs .= '&';
						}

						$qs .= "{$key}={$value}";
					}

					$qs = preg_replace( '#[^A-Z0-9=\-\?\&]#i', '', $qs );
				}
			}

			$url = $url['scheme'] . '://' . $url['host'] . $url['path'] . $qs;
		} else {
			$url = microtime();
		}
	}

	return $url;
}

/**
 * Prefix cache key if device calls for separate caching
 *
 * @param string $key
 * @return $string
 */
function redis_page_cache_set_device_key( $key ) {
	switch ( redis_page_cache_get_device_type() ) {
		case 'tablet' :
			$prefix = 'T-';
			break;
		case 'mobile' :
			$prefix = 'M-';
			break;
		default :
		case 'desktop' :
			$prefix = '';
			break;
	}

	return $prefix . $key;
}

/**
 * Determine the current device type from its user agent
 * Allows for separate caches for tablet, mobile, and desktop visitors
 *
 * @return string
 */
function redis_page_cache_get_device_type() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

	if ( empty( $ua ) ) {
		return 'desktop';
	}

	// Tablet user agents
	if (
		false !== stripos( $ua, 'ipad'       ) ||
		( false !== stripos( $ua, 'Android'  ) && false === stripos( $ua, 'mobile' ) ) ||
		false !== stripos( $ua, 'tablet '    ) ||
		false !== stripos( $ua, 'Silk/'      ) ||
		false !== stripos( $ua, 'Kindle'     ) ||
		false !== stripos( $ua, 'PlayBook'   ) ||
		false !== stripos( $ua, 'RIM Tablet' )
	) {
		return 'tablet';
	}

	// Mobile user agents
	if (
		false !== stripos( $ua, 'Mobile'     ) || // many mobile devices (all iPhone, iPad, etc.)
		false !== stripos( $ua, 'Android'    ) ||
		false !== stripos( $ua, 'BlackBerry' ) ||
		false !== stripos( $ua, 'Opera Mini' ) ||
		false !== stripos( $ua, 'Opera Mobi' )
	) {
		return 'mobile';
	}

	return 'desktop';
}

/**
 * Establish a connection to the Redis server
 *
 * Will try the PECL module first, then fall back to PRedis
 *
 * @return object
 */
function redis_page_cache_connect_redis() {
	global $redis_page_cache_config;

	// check if PECL Extension is available
	if ( class_exists( 'Redis' ) ) {
		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- Redis: PECL -->\n";
		}

		$redis = new Redis();

		if ( $redis_page_cache_config['redis_socket'] ) {
			$redis->connect( $redis_page_cache_config['redis_socket'] );
		} else {
			$redis->connect( $redis_page_cache_config['redis_server'], $redis_page_cache_config['redis_port'] );
		}

		// Default DB is 0, so only need to SELECT if other
		if ( $redis_page_cache_config['redis_db'] ) {
			$redis->select( $redis_page_cache_config['redis_db'] );
		}
	// Fallback to predis5.2.php
	} else {
		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- Redis: Predis -->\n";
		}

		include_once dirname( __FILE__ ) . '/wp-content/plugins/redis-page-cache/predis5.2.php'; //we need this to use Redis inside of PHP
		$redis = array(
			'host' => $redis_page_cache_config['redis_server'],
			'port' => $redis_page_cache_config['redis_port'],
		);

		// Default DB is 0, so only need to SELECT if other
		if ( $redis_page_cache_config['redis_db'] ) {
			$redis['database'] = $redis_page_cache_config['redis_db'];
		}

		$redis = new Predis_Client( $redis );
	}

	return $redis;
}

/**
 * BEGIN CACHING LOGIC
 */

// Set proper IP for proxied requests
redis_page_cache_handle_cdn_remote_addressing();

// Ensure WP uses a theme (this is normally set in index.php)
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', true );
}

// Set a header advertising the cache engine
header( 'X-Redis-Page-Cache: Redis Page Cache for WordPress by Erick Hitter (http://eth.pw/rpc)', true );

try {
	// Establish connection with Redis server
	$redis = redis_page_cache_connect_redis();

	// Whether we need to load WP
	$load_wp = true;

	// Relevant details on the current request
	$is_post_request = ( ! empty( $GLOBALS['HTTP_RAW_POST_DATA'] ) || ! empty( $_POST ) );
	$is_cache_exempt = (bool) preg_match( "#(wordpress_(logged|sec)|wp\-postpass|comment_author)#", var_export( $_COOKIE, true ) );

	if ( $redis_page_cache_config['debug'] ) {
		$redis_page_cache_config['debug_messages'] .= "<!-- POST request: " . ( $is_post_request ? 'yes' : 'no' ) . " -->\n";
		$redis_page_cache_config['debug_messages'] .= "<!-- Cache exexmpt (logged in, password-protected post, commenter): " . ( $is_cache_exempt ? 'yes' : 'no' ) . " -->\n";
	}

	// Refresh request, deletes cache: either manual refresh cache by adding ?refresh=secret_string after the URL or somebody posting a comment
	if ( redis_page_cache_refresh_has_secret( $redis_page_cache_config['secret_string'] ) || redis_page_cache_request_has_secret( $redis_page_cache_config['secret_string'] ) ) {
		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- Manual refresh requested -->\n";
		}

		$redis->del( $redis_page_cache_config['redis_key'] );
	// This page is cached, the user isn't exempted from cache, and it isn't a POST request, so let's use the cache
	} elseif ( ! $is_post_request && ! $is_cache_exempt && $redis->exists( $redis_page_cache_config['redis_key'] ) ) {
		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- Serving page from cache -->\n";
		}

		// Page is served from cache, so we don't need WP
		$load_wp = false;
		$redis_page_cache_config['cached'] = true;

		// Retrieve cached page, which is an array that includes meta data along with the page output
		$cache = unserialize( $redis->get( $redis_page_cache_config['redis_key'] ) );

		// Set headers related to content type
		header( 'Content-Type: ' . $cache['content_type'] . '; charset=' . $cache['content_encoding'], true );

		// Output cached headers from original page
		if ( ! empty( $cache['headers'] ) ) {
			foreach ( $cache['headers'] as $key => $value ) {
				header( "{$key}: {$value}", true );
			}
		}

		// Output cache headers if desired
		if ( $redis_page_cache_config['cache_headers'] ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $cache['time'] ) . ' GMT', true );
			header( 'Cache-Control: max-age=' . $cache['age'] . ', must-revalidate', false );
		}

		// Output page content
		echo trim( $cache['output'] );

		// Display generation stats if requested
		if ( 'application/xml' !== $cache['content_type'] && $redis_page_cache_config['stats'] ) {
			echo "\n<!-- Page cached via Redis using the Redis Page Cache plugin (http://eth.pw/rpc). -->";
			echo "\n<!-- Retrieved from cache in " . redis_page_cache_time_elapsed( $start, microtime() ) . " seconds. -->";
		}

		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- Last Modified: " . gmdate( 'D, d M Y H:i:s', $cache['time'] ) . " GMT . -->\n";
			$redis_page_cache_config['debug_messages'] .= "<!-- Max Age: " . $cache['age'] . " -->\n";
		}
	// If the cache does not exist lets display the user the normal page without cache, and then fetch a new cache page
	} elseif ( $_SERVER['REMOTE_ADDR'] != $redis_page_cache_config['server_ip'] ) {
		if ( false === strstr( $redis_page_cache_config['current_url'], 'preview=true' ) ) {
			if ( $redis_page_cache_config['debug'] ) {
				$redis_page_cache_config['debug_messages'] .= "<!-- Displaying page without cache -->\n";
			}

			// If user isn't exempt from caching and this isn't a post request, render the requested page and cache if appropriate.
			if ( ! $is_post_request && ! $is_cache_exempt ) {
				if ( $redis_page_cache_config['debug'] ) {
					$redis_page_cache_config['debug_messages'] .= "<!-- Adding page to cache -->\n";
				}

				// We load WP to generate the cached output, so no need to load again
				$load_wp = false;

				// Render page into an output buffer and display
				ob_start();
				require_once dirname( __FILE__ ) . '/wp-blog-header.php';
				$output = trim( ob_get_clean() );
				echo $output;

				// Display generation stats if requested
				if ( ! is_feed() && $redis_page_cache_config['stats'] ) {
					echo "\n<!-- Page NOT cached via Redis using the Redis Page Cache plugin (http://eth.pw/rpc). -->";
					echo "\n<!-- Generated and cached in " . redis_page_cache_time_elapsed( $start, microtime() ) . " seconds. -->";
				}

				// Cache rendered page if appropriate
				if ( ! is_404() && ! is_search() ) {
					// Default cache payload
					$cache = array(
						'output'           => $output,
						'time'             => time(),
						'age'              => 31536000, // one year in seconds
						'content_type'     => is_feed() ? 'application/xml' : 'text/html',
						'content_encoding' => get_option( 'blog_charset', 'UTF-8' ),
						'headers'          => array(),
					);

					// Minify cached content
					if ( $redis_page_cache_config['minify'] ) {
						$search = array( '#\>[^\S ]+#s', '#[^\S ]+\<#s', '#(\s)+#s' );
						$replace = array( '>', '<', '\\1' );

						$cache['output'] = preg_replace( $search, $replace, $cache['output'] );
					}

					// Capture certain headers
					// Props to @andy and Batcache (http://wordpress.org/plugins/batcache/) for this code
					if ( ! empty( $redis_page_cache_config['additional_headers' ] ) ) {
						if ( function_exists( 'headers_list' ) ) {
							foreach ( headers_list() as $header ) {
								list( $key, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );
								$cache['headers'][ $key ] = $value;
							}
						} elseif ( function_exists( 'apache_response_headers' ) ) {
							$cache['headers'] = apache_response_headers();
						}

						if ( $cache['headers'] ) {
							foreach ( $cache['headers'] as $key => $value ) {
								if ( ! in_array( strtolower( $key ), $redis_page_cache_config['additional_headers' ] ) )
									unset( $cache['headers'][$key] );
							}
						}

						unset( $key );
						unset( $value );
					}

					// Is unlimited cache life requested?
					if ( ! isset( $redis_page_cache_config['unlimited'] ) ) {
						$redis_page_cache_config['unlimited'] = (bool) get_option( 'redis-page-cache-debug', false );
					}

					// Cache the page for the chosen duration
					if ( $redis_page_cache_config['unlimited'] ) {
						$redis->set( $redis_page_cache_config['redis_key'], serialize( $cache ) );
					} else {
						if ( ! isset( $redis_page_cache_config['cache_duration'] ) ) {
							$redis_page_cache_config['cache_duration'] = (int) get_option( 'redis-page-cache-seconds', 43200 );
						}

						if ( ! is_numeric( $redis_page_cache_config['cache_duration'] ) ) {
							$redis_page_cache_config['cache_duration'] = 43200;
						}

						$cache['age'] = $redis_page_cache_config['cache_duration'];

						$redis->setex( $redis_page_cache_config['redis_key'], $redis_page_cache_config['cache_duration'], serialize( $cache ) );
					}
				}
			}
		}
	}

	// The current request wasn't served from cache or isn't cacheable, so we pass off to WP
	if ( $load_wp ) {
		require_once dirname( __FILE__ ) . '/wp-blog-header.php';
	}
} catch ( Exception $e ) {
	require_once dirname( __FILE__ ) . '/wp-blog-header.php';
	redis_page_cache_exception_handler( $e );
}

/**
 * DEBUGGING OUTPUT
 */
if ( $redis_page_cache_config['debug'] ) {
	$redis_page_cache_config['debug_messages'] .= "<!-- Redis Page Cache by Erick Hitter (http://eth.pw/rpc). Page generated in " . redis_page_cache_time_elapsed( $start, microtime() ) . " seconds. -->\n";
	$redis_page_cache_config['debug_messages'] .= "<!-- Cache key: " . $redis_page_cache_config['redis_key'] . " -->\n";
	$redis_page_cache_config['debug_messages'] .= "<!-- Cached URL: " . redis_page_cache_get_clean_url() . " -->\n";

	if ( isset( $redis_page_cache_config['unlimited'] ) && $redis_page_cache_config['unlimited'] ) {
		$cache_duration = 'infinite';
	} elseif ( isset( $redis_page_cache_config['cache_duration'] ) ) {
		$cache_duration = $redis_page_cache_config['cache_duration'];
	} else {
		$cache_duration = 'unknown';
	}
	$redis_page_cache_config['debug_messages'] .= "<!-- Cache duration in seconds: " . $cache_duration . " -->\n";

	$redis_page_cache_config['debug_messages'] .= "<!-- Server IP: " . $redis_page_cache_config['server_ip'] . " -->\n";

	echo "\n" . $redis_page_cache_config['debug_messages'];
}
