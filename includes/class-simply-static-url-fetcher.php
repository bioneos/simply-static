<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Simply Static URL fetcher class
 * @package Simply_Static
 */
class Simply_Static_Url_Fetcher {

	/**
	 * Timeout for fetching URLs
	 * @var string
	 */
	const TIMEOUT = 300;

    /**
	 * Fetch the URL and return a WP_Error if we get one, otherwise a Response class.
	 * @param Simply_Static_Page $static_page URL to fetch
	 * @param string             $archive_dir Archive directory to save page to
	 * @return boolean                        Was the fetch successful?
	 */
	public static function fetch( Simply_Static_Page $static_page, $archive_dir ) {
		$url = $static_page->url;

		// Don't process URLs that don't match the URL of this WordPress installation
		if ( ! sist_is_local_url( $url ) ) {
			return new WP_Error( 'remote_url', sprintf( __( "Attempting to fetch remote URL: %s", 'simply-static' ), $url ) );
		}

		$temp_filename = wp_tempnam();

		$response = wp_remote_get( $url, array(
			'timeout' => self::TIMEOUT,
			'sslverify' => false, // not verifying SSL because all calls are local
			'redirection' => 0, // disable redirection
			'blocking' => true, // do not execute code until this call is complete
			'stream' => true, // stream body content to a file
			'filename' => $temp_filename
		) );

		if ( is_wp_error( $response ) ) {
			$static_page->http_status_code = null;
			$static_page->last_checked_at = sist_formatted_datetime();
			$static_page->save();
			return false;
		} else {
			$static_page->http_status_code = $response['response']['code'];
			$static_page->content_type = $response['headers']['content-type'];
			$static_page->last_checked_at = sist_formatted_datetime();

			$relative_filename = null;
			if ( $static_page->http_status_code == 200 ) {
				$relative_filename = $this->get_filename_for_static_page( $static_page );
			}

			if ( $relative_filename ) {
				$static_page->file_path = $relative_filename;
				$file_path = $archive_dir . $relative_filename;
				rename( $temp_filename, $file_path );
			} else {
				unlink( $temp_filename );
			}

			$static_page->save();

			return true;
		}
	}

	/**
	 * Retrieve a (full) filename given a Static_Page
	 * @param Simply_Static_Page $static_page The Simply_Static_Page record
	 * @return string|null                The file path of the saved file
	 */
	private function get_filename_for_static_page( $static_page ) {
		$relative_filename = $this->create_directories_for_static_page( $static_page );

		if ( $relative_filename ) {
			$file_path = $this->archive_dir . $relative_filename;
			return $file_path;
		} else {
			return null;
		}
	}

	/**
	 * Given a Static_Page, return a relative filename based on the URL
	 *
	 * This will also create directories as needed so that a file could be
	 * created at the returned file path.
	 *
	 * @param Simply_Static_Page $static_page The Simply_Static_Page
	 * @return string|null                The file path of the file
	 */
	private function create_directories_for_static_page( $static_page ) {
		$url_parts = parse_url( $static_page->url );
		// a domain with no trailing slash has no path, so we're giving it one
		$path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';

		$origin_path_length = strlen( parse_url( sist_origin_url(), PHP_URL_PATH ) );
		if ( $origin_path_length > 1 ) { // prevents removal of '/'
			$path = substr( $path, $origin_path_length );
		}

		$path_info = sist_url_path_info( $path );

		$relative_file_dir = $path_info['dirname'];
		$relative_file_dir = sist_remove_leading_directory_separator( $relative_file_dir );

		// If there's no extension, we're going to create a directory with the
		// filename and place an index.html/xml file in there.
		if ( $path_info['extension'] === '' ) {
			if ( $path_info['filename'] !== '' ) {
				// the filename would be blank for the root url, in that
				// instance we don't want to add an extra slash
				$relative_file_dir .= $path_info['filename'];
				$relative_file_dir = sist_add_trailing_directory_separator( $relative_file_dir );
			}
			$path_info['filename'] = 'index';
			if ( $static_page->is_type( 'xml' ) ) {
				$path_info['extension'] = 'xml';
			} else {
				$path_info['extension'] = 'html';
			}
		}

		$create_dir = wp_mkdir_p( $this->archive_dir . $relative_file_dir );
		if ( $create_dir === false ) {
			$static_page->set_error_message( 'Unable to create temporary directory' );
		} else {
			$relative_filename = $relative_file_dir . $path_info['filename'] . '.' . $path_info['extension'];
			// check that file doesn't exist OR exists but is writeable
			// (generally, we'd expect it to never exist)
			if ( ! file_exists( $relative_filename ) || is_writable( $relative_filename ) ) {
				return $relative_filename;
			} else {
				$static_page->set_error_message( 'Temporary file exists and is unwriteable' );
			}
		}

		return null;
	}

}
