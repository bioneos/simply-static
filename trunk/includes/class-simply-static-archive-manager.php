<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Simply Static URL fetcher class
 *
 * @package Simply_Static
 */
class Simply_Static_Archive_Manager {

	private static $states = array(
		'idle' => [
			'type' => 'final',
			'transitions' => [
				'start' => 'setup',
				'error' => 'error'
			]
		],
		'setup' => [
			'type' => 'normal',
			'transitions' => [
				'next' => 'fetching',
				'cancel' => 'cancelled',
				'error' => 'error'
			]
		],
		'fetching' => [
			'type' => 'normal',
			'transitions' => [
				'next' => 'transferring',
				'cancel' => 'cancelled',
				'error' => 'error'
			]
		],
		'transferring' => [
			'type' => 'normal',
			'transitions' => [
				'next' => 'wrapup',
				'cancel' => 'cancelled',
				'error' => 'error'
			]
		],
		'wrapup' => [
			'type' => 'normal',
			'transitions' => [
				'next' => 'finished',
				'cancel' => 'cancelled',
				'error' => 'error'
			]
		],
		'finished' => [
			'type' => 'normal',
			'transitions' => [
				'next' => 'idle',
				'cancel' => 'cancel',
				'error' => 'error'
			]
		],
		'cancelled' => [
			'type' => 'final',
			'transitions' => [
				'start' => 'setup',
				'error' => 'error'
			]
		],
		'error' => [
			'type' => 'final',
			'transitions' => [
				'start' => 'setup'
			]
		]
	);

	/**
	 * Stores options for the archive manager using the options class
	 * @var Simply_Static_Options
	 */
	protected $options = null;

    /**
	 * Performs initializion of the options structure
	 * @param string $option_key The options key name
	 */
	public function __construct( $options ) {
		$this->options = $options;

		if ( $this->options->get( 'archive_state_name' ) === null ) {
			$this->init();
		}
	}

	private function init() {
		$this->options
			->set( 'archive_state_name', 'idle' )
			->save();
	}

	public function perform( $action ) {
		try {
			$function_name = 'handle_ajax_' . $action;
			$this->$function_name();
		} catch ( Exception $e ) {
			error_log('--1--');
			$this->error_occurred( new WP_Error( 'unexpected_error', __( 'An unknown error has occurred' ) ) );
		}
	}

	// true = state completed successfully
	// false = state not yet done
	// WP_Error = set error state
	private function next_or_error( $result ) {
		if ( is_wp_error( $result ) ) {
			error_log('--2--');
			$this->error_occurred( $result );
		} else {
			if ( $result == true && ! $this->has_finished() ) {
				$this->apply( 'next' );
			} // else: keep the same state
		}
	}

	private function handle_ajax_start() {
		if ( $this->can( 'start' ) ) {
			$this->apply( 'start' );
			$this->next_or_error( $this->handle_setup_state() );
		} else {
			// unknown action or transition to wrong state
			error_log('--3--');
			$this->error_occurred( new WP_Error( 'invalid_state_transition' ) );
		}
	}

	private function handle_ajax_continue() {
		$state_name = $this->get_state_name();
		$function_name = 'handle_' . $state_name . '_state';
		$this->next_or_error( $this->$function_name() );
	}

	private function handle_ajax_cancel() {
		$this->apply( 'cancel' );
		$this->handle_cancelled_state();
	}

	public function get_status_messages() {
		return $this->options->get( 'archive_status_messages' );
	}

	private function save_status_message( $message ) {
		$state_name = $this->get_state_name();
		$messages = $this->get_status_messages();
		$messages[ $state_name ] = $message;
		$this->options
			->set( 'archive_status_messages', $messages )
			->save();
	}

	public function get_state_name() {
		return $this->options->get( 'archive_state_name' );
	}

	private function get_state() {
		return self::$states[ $this->get_state_name() ];
	}

	private function get_archive_dir() {
		return sist_add_trailing_directory_separator( $this->options->get( 'temp_files_dir' ) . $this->options->get( 'archive_name' )  );
	}

	private function get_start_time() {
		return $this->options->get( 'archive_start_time' );
	}

	public function has_finished() {
		$state = $this->get_state();
		return $state['type'] == 'final';
	}

	public function ready_to_start() {
		$state = $this->get_state();
		return $this->has_finished();
	}

	private function can( $transition_name ) {
		$state = $this->get_state();
		return isset( $state['transitions'][ $transition_name ] );
	}

	private function apply( $transition_name ) {
		$state = $this->get_state();
		sist_error_log( $state['transitions'] );
		$new_state_name = $state['transitions'][ $transition_name ];
		return $this->options->set( 'archive_state_name', $new_state_name )->save();
	}

	private function handle_setup_state() {
		global $blog_id;

		$current_user = wp_get_current_user();
		$archive_name = join( '-', array( Simply_Static::SLUG, $blog_id, time(), $current_user->user_login ) );

		$this->options
			->set( 'archive_status_messages', array() )
			->set( 'archive_name', $archive_name )
			->set( 'archive_creator_id', $current_user->ID )
			->set( 'archive_blog_id', $blog_id )
			->set( 'archive_start_time', sist_formatted_datetime() )
			->save();

		$message = __( 'Setting up', Simply_Static::SLUG );
		$this->save_status_message( $message );

		$archive_dir = $this->get_archive_dir();

		// create temp archive directory
		if ( ! file_exists( $archive_dir ) ) {
			$create_dir = wp_mkdir_p( $archive_dir );
			if ( $create_dir === false ) {
				return new WP_Error( 'cannot_create_archive_dir' );
			}
		}

		// clear out any saved error messages on pages
		Simply_Static_Page::update_all( 'error_message', NULL );

		// add origin url and additional urls/files to database
		Simply_Static_Archive_Creator::add_origin_and_additional_urls_to_db( $this->options->get( 'additional_urls' ) );
		Simply_Static_Archive_Creator::add_additional_files_to_db( $this->options->get( 'additional_files' ) );

		return true;
	}

	private function handle_fetching_state() {
		$archive_creator = new Simply_Static_Archive_Creator(
			$this->options->get( 'destination_scheme' ),
			$this->options->get( 'destination_host' ),
			$this->get_archive_dir(),
			$this->get_start_time()
		);

		list( $pages_processed, $total_pages ) = $archive_creator->fetch_pages();

		$message = sprintf( __( "Fetched %d of %d pages/files", Simply_Static::SLUG ), $pages_processed, $total_pages );
		$this->save_status_message( $message );

		if ( is_wp_error( $pages_processed ) ) {
			return $pages_processed;
		} else {
			// return true when done (no more pages)
			return $pages_processed == $total_pages;
		}
	}

	private function handle_transferring_state() {
		$archive_creator = new Simply_Static_Archive_Creator(
			$this->options->get( 'destination_scheme' ),
			$this->options->get( 'destination_host' ),
			$this->get_archive_dir(),
			$this->get_start_time()
		);

		if ( $this->options->get( 'delivery_method' ) == 'zip' ) {

			$download_url = $archive_creator->create_zip();
			if ( is_wp_error( $download_url ) ) {
				return $download_url;
			} else {
				$message = __( 'ZIP archive created: ', Simply_Static::SLUG );
				$message .= ' <a href="' . $download_url . '">' . __( 'Click here to download', Simply_Static::SLUG ) . '</a>';
				$this->save_status_message( $message );
				return true;
			}

		} elseif ( $this->options->get( 'delivery_method' ) == 'local' ) {

			$local_dir = $this->options->get( 'local_dir' );

			list( $pages_processed, $total_pages ) = $archive_creator->copy_static_files( $local_dir );

			if ( $pages_processed !== 0 ) {
				$message = sprintf( __( "Copied %d of %d files", Simply_Static::SLUG ), $pages_processed, $total_pages );
				$this->save_status_message( $message );
			}


			if ( is_wp_error( $pages_processed ) ) {
				return $pages_processed;
			} else {
				// return true when done (no more pages)
				return $pages_processed == $total_pages;
			}
		}
	}

	private function handle_wrapup_state() {
		$this->save_status_message( __( 'Wrapping up', Simply_Static::SLUG ) );

		$archive_creator = new Simply_Static_Archive_Creator(
			$this->options->get( 'destination_scheme' ),
			$this->options->get( 'destination_host' ),
			$this->get_archive_dir(),
			$this->get_start_time()
		);

		if ( $this->options->get( 'delete_temp_files' ) === '1' ) {
			$deleted_successfully = $archive_creator->delete_temp_static_files();
		}

		return true;
	}

	private function handle_finished_state() {
		$this->save_status_message( __( 'Done!', Simply_Static::SLUG ) );

		return true;
	}

	private function handle_cancelled_state() {
		$this->save_status_message( __( 'Cancelled', Simply_Static::SLUG ) );

		return false;
	}

	private function error_occurred( $wp_error ) {
		$this->apply( 'error' );
		$this->handle_error_state( $wp_error );
	}

	private function handle_error_state( $wp_error ) {
		error_log('----------------');
		sist_error_log( $wp_error );

		$message = sprintf( __( "Error: %s", Simply_Static::SLUG ), $wp_error->get_error_message() );
		$this->save_status_message( $message );

		return false;
	}

}
