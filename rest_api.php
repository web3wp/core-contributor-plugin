<?php

// API Version 1.0
class Contributor_NFT_API {

	public $version = '1';
	public $namespace = "contributor";

	//initialize
	public function __construct() {
		add_action( 'rest_api_init', array( &$this, 'register_routes' ) );
	}

	protected function get_namespace() {
		return $this->namespace . '/v' . $this->version;
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {

		// Contribs by WP version.
		register_rest_route( $this->get_namespace(), '/version/(?P<wp_version>\d+\.\d+)', array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'by_version' ),
				'args'     => array(
					'wp_version' => array(
						'required'          => true,
						'sanitize_callback' => function($version) {
							if ( preg_match( '/^\d+.\d+/', $version, $update_major ) ) {
								return $update_major[0];
							} else {
								return new WP_Error( 'invalid_wp_version', 'Invalid WordPress major core version.' );
							}

						},
					),
				),
				'permission_callback' => '__return_true',
			),
		) );

		// NFTs by username.
		register_rest_route( $this->get_namespace(), '/user/(?P<username>[a-z0-9.\-]+)', array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'by_user' ),
				'args'     => array(
					'username' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
				'permission_callback' => '__return_true',
			),
		) );

		// List WP versions.
		register_rest_route( $this->get_namespace(), '/versions', array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'wp_versions' ),
				'args'     => array(
				),
				'permission_callback' => '__return_true',
			),
		) );

		// Contributor search by username or name.
		register_rest_route( $this->get_namespace(), '/search', array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'search' ),
				'args'     => array(
					's' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'permission_callback' => '__return_true',
			),
		) );
	}

	/**
	 * Validates a REST request
	 *
	 * @param WP_REST_Request
	 *
	 * @return bool|WP_Error
	 */
	public function by_version( WP_REST_Request $request ) {
		global $wpdb;

		$wp_version = $request->get_param( 'wp_version' );

		$contributors = $wpdb->get_results( $wpdb->prepare("SELECT c.token_id, c.username, n.name, n.gravatar, c.type, c.title, c.minted FROM {$wpdb->prefix}core_contributors c JOIN {$wpdb->prefix}core_contributor_names n ON c.username = n.username WHERE c.wp_version = %s", $wp_version ) );
		if ( $contributors ) {
			$meta = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}core_versions WHERE wp_version = %s", $wp_version ) );
			$meta->total = 0;
			$meta->minted = 0;
			$meta->noteworthy = 0;
			$meta->core = 0;
			foreach ( $contributors as $row ) {
				$meta->total++;
				$meta->minted += $row->minted;
				if ( 'noteworthy' == $row->type ) {
					$meta->noteworthy++;
				} else {
					$meta->core++;
				}
				$row->minted = (bool)$row->minted;
			}
			$result = (object) [
				'meta'   => $meta,
				'tokens' => $contributors,
			];
			return rest_ensure_response( $result );
		} else {
			return new WP_Error( 'invalid_wp_version', 'Invalid WordPress major core version.', array( 'status' => 404 ) );
		}
	}

	/**
	 * Validates a REST request
	 *
	 * @param WP_REST_Request
	 *
	 * @return bool|WP_Error
	 */
	public function by_user( WP_REST_Request $request ) {
		global $wpdb;

		$username = $request->get_param( 'username' );

		$contributors = $wpdb->get_results( $wpdb->prepare("SELECT c.token_id, v.*, c.type, c.title, c.minted FROM {$wpdb->prefix}core_contributors c JOIN {$wpdb->prefix}core_contributor_names n ON c.username = n.username JOIN {$wpdb->prefix}core_versions v ON c.wp_version = v.wp_version WHERE c.username = %s", $username ) );
		if ( $contributors ) {
			$meta = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE username = %s", $username ) );
			$meta->total = 0;
			$meta->minted = 0;
			$meta->noteworthy = 0;
			$meta->core = 0;
			foreach ( $contributors as $row ) {
				$meta->total++;
				$meta->minted += $row->minted;
				if ( 'noteworthy' == $row->type ) {
					$meta->noteworthy++;
				} else {
					$meta->core++;
				}
				$row->minted = (bool)$row->minted;
			}
			$result = (object) [
				'meta'   => $meta,
				'tokens' => $contributors,
			];
			return rest_ensure_response( $result );
		} else {
			return new WP_Error( 'invalid_username', 'Invalid username.', array( 'status' => 404 ) );
		}
	}

	/**
	 * Validates a REST request
	 *
	 * @param WP_REST_Request
	 *
	 * @return bool|WP_Error
	 */
	public function wp_versions( WP_REST_Request $request ) {
		global $wpdb;

		$versions = $wpdb->get_results( "SELECT v.*, COUNT(token_id) as contributors, SUM(c.minted) as minted FROM {$wpdb->prefix}core_contributors c JOIN {$wpdb->prefix}core_versions v ON c.wp_version = v.wp_version WHERE 1 GROUP BY v.wp_version ORDER BY wp_version DESC" );
		if ( $versions ) {
			return rest_ensure_response( $versions );
		}
	}

	/**
	 * Validates a REST request
	 *
	 * @param WP_REST_Request
	 *
	 * @return bool|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		global $wpdb;

		$search = $request->get_param( 's' );
		$search_esc = '%' . $wpdb->esc_like( $search ) . '%';
		$contributors = $wpdb->get_results($wpdb->prepare("SELECT c.username, n.name, COUNT(c.wp_version) as versions, SUM(c.minted) as minted FROM {$wpdb->prefix}core_contributors c JOIN {$wpdb->prefix}core_contributor_names n ON c.username = n.username WHERE c.username LIKE %s OR n.name LIKE %s GROUP BY c.username", $search_esc, $search_esc ) );
		if ( $contributors ) {
			return rest_ensure_response( $contributors );
		}
	}


}

new Contributor_NFT_API();
