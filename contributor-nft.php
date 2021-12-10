<?php
/**
 * Plugin Name:     Core Contributor NFT API
 * Plugin URI:      https://web3wp.com
 * Description:     App for handling the WordPress Contributor NFT drop
 * Author:          Aaron Edwards
 * Text Domain:     contributor-nft
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Contributor_Nft
 *                  Copyright 2021 UglyRobot, LLC
 */

define( 'CONTRIBUTOR_NFT_VERSION', '0.1.0' );

class Contributor_NFT {

	private static $instance;

	public function __construct() {

		add_action( 'admin_menu', array( $this, 'plug_pages' ) );

		//change api url for REST API (requires manual flush of rewrite rules)
		add_filter( 'rest_url_prefix', function ( $endpoint ) {
			return 'api';
		} );

		include_once( 'rest_api.php' );
	}

	/**
	 * @return Contributor_NFT
	 */
	public static function instance() {

		if ( ! self::$instance ) {
			self::$instance = new Contributor_NFT();
		}

		return self::$instance;
	}

	function plug_pages() {
		$page = add_management_page( __( 'Contributor NFTs', 'iup' ), __( 'Contributor NFTs', 'iup' ), 'manage_options', "nfts", [ $this, 'admin_page'] );
	}

	/**
	 * Admin page for parsing HTML into our db format for older WP versions.
	 *
	 * @return void
	 */
	function admin_page() {
		global $wpdb;
		?>
		<div class="wrap">
			<?php
			if ( isset( $_POST['wp_version'] ) ) {
				$data = wp_filter_nohtml_kses( $_POST['version_html'] );
				$data = explode( ', ', $data );
				foreach ( $data as $person ) {
					if ( preg_match( '/([\w.-]+)(?:\/([\w-]+))?(?:\s\(([^)]+)\))?/', $person, $matches ) ) {
						if ( $matches[3] == 'prof' || empty( $matches[3] ) ) $matches[3] = $matches[1];
						$name = !empty($matches[2]) ? "$matches[3] ($matches[2])" : ltrim($matches[3], '(');
						$username = str_replace( ' ', '-', strtolower( trim( $matches[1] ) ) );
						$exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}core_contributors WHERE username = %s AND wp_version = %s", $username, stripslashes( $_POST['wp_version'] ) ) );
						if ( $exists ) {
							$wpdb->update( "{$wpdb->prefix}core_contributors", [
								'type'       => $_POST['type'],
							], [
								'username'   => $username,
								'wp_version' => stripslashes( $_POST['wp_version'] )
							]  );
							$this->maybe_update_name( $username, $name );
							echo "UPDATED $username <strong>$name</strong><br>";
						} else {
							$wpdb->insert( "{$wpdb->prefix}core_contributors", [
								'username'   => $username,
								'wp_version' => stripslashes( $_POST['wp_version'] ),
								'type'       => $_POST['type'],
							] );
							$this->maybe_update_name( $username, $name );
							echo "INSERTED $username <strong>$name</strong><br>";
						}
					} else {
						echo "FAIL $person<br>";
					}
				}
			}

			if ( isset( $_POST['submit_names'] ) ) {
				$data = wp_filter_nohtml_kses( $_POST['version_html'] );
				$data = explode( ', ', $data );
				foreach ( $data as $person ) {
					$person = trim( $person );
					$exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE username = %s OR name = %s", $person, $person ) );
					if ( $exists ) {
						$matches[] = "$exists->username ($exists->name)";
					} else {
						$exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE name LIKE %s", "%$person%" ) );
						if ( $exists ) {
							$maybe[] = "$person == $exists->username ($exists->name)";
						} else {
							$names = explode( ' ', $person);
							$last = end($names);
							$exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE name LIKE %s", "%$last%" ) );
							if ( $exists ) {
								$maybe[] = "$person == $exists->username ($exists->name),";
							} else {
								$unknown[] = $person;
							}
						}
					}
				}
				echo "<h3>MATCHES</h3>";
				echo implode( ', ', $matches );


				echo "<h3>MAYBE</h3>";
				echo implode( '<br>', $maybe );


				echo "<h3>UNKNOWN</h3>";
				echo implode( ', ', $unknown ).'<br>';

			}
			?>
			<h1 class="wp-heading-inline">
				<?php _e( 'Contributor NFTs' ); ?>
			</h1>
			<form method="post">
			<table class="form-table" role="presentation">

				<tbody><tr>
					<th scope="row"><label for="wp_version">WordPress Version</label></th>
					<td>
						<select name="wp_version" id="wp_version" class="postform">
							<?php
							$versions = $wpdb->get_col( "SELECT wp_version FROM {$wpdb->prefix}core_versions WHERE wp_version < '3.2'" );
							foreach ($versions as $version) { ?>
								<option class="level-0" value="<?php echo esc_attr( $version ); ?>"><?php echo esc_html( stripslashes($version) ); ?></option>
							<?php } ?>
						</select>
				</tr>
				<tr>
					<th scope="row"><label for="type">Contributor Type</label></th>
					<td>
						<select name="type" id="type" class="postform">
							<option class="level-0" value="noteworthy">Noteworthy</option>
							<option class="level-0" value="core" selected="selected">Core</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="version_html">Contributors List</label></th>
					<td><textarea id="version_html" name="version_html" class="large-text" rows="20" placeholder="Version HTML list of contributors"></textarea></td>
				</tr>

				</tbody></table>
				<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Submit"></p>
			</form>

			<form method="post">
				<table class="form-table" role="presentation">

					<tbody>
					<tr>
						<th scope="row"><label for="version_html">Translate Contributor names to usernames</label></th>
						<td><textarea id="version_html" name="version_html" class="large-text" rows="20" placeholder="Version list of contributors"></textarea></td>
					</tr>

					</tbody></table>
				<p class="submit"><input type="submit" name="submit_names" id="submit" class="button button-primary" value="Translate Names"></p>
			</form>
		</div>
		<?php
	}

	function import_wp_versions() {
		global $wpdb;

		$versions = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}core_versions WHERE wp_version >= '3.2' ORDER BY wp_version ASC" );
		foreach ( $versions as $version ) {
			$this->get_credits( $version->wp_version);
		}
		return $versions;
	}

	function get_wporg_profile( $github_username ) {
		$response = wp_remote_get( "https://profiles.wordpress.org/wp-json/wporg-github/v1/lookup/$github_username", [ 'user-agent' => 'Web3WP Core Contributor NFT' ] );
		if ( is_array( $response ) && ! is_wp_error( $response ) && 200 == wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( $response['body'] ); // use the content
			return $data->slug;
		}

		return false;
	}

	function get_credits( $wp_version ) {
		$response = wp_remote_get( "https://api.wordpress.org/core/credits/1.1/?version=$wp_version", [ 'user-agent' => 'Web3 WP Contributors NFT' ] );
		if ( is_array( $response ) && ! is_wp_error( $response ) ) {
			$data = json_decode( $response['body'] ); // use the content
			unset( $data->groups->libraries );
			//import props first
			foreach ( $data->groups->props->data as $contributor => $details ) {
				$this->update_group_credits($wp_version, 'props', $contributor, $details);
			}
			//now import noteworthies
			foreach ( $data->groups as $group_name => $group ) {
				if ( 'props' == $group_name ) {
					continue;
				} //skip libs
				foreach ( $group->data as $contributor => $details ) {
					$this->update_group_credits($wp_version, $group_name, $contributor, $details);
				}
			}
		}
	}

	function update_group_credits($wp_version, $group_name, $contributor, $details) {
		global $wpdb;

		$type = $group_name == 'props' ? 'core' : 'noteworthy'; //enum in db
		$contributor = str_replace( ' ', '-', strtolower( trim( $contributor ) ) );
		$exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}core_contributors WHERE username = %s AND wp_version = %s", $contributor, $wp_version ) );
		if ( $exists ) {
			if ( is_array( $details ) ) {
				$wpdb->update( "{$wpdb->prefix}core_contributors", [
					'title'      => $details[3],
					'type'       => $type,
				], [
					'username'   => $contributor,
					'wp_version' => $wp_version
				] );
				$this->maybe_update_name( $contributor, $details[0], $details[1] );
			} else {
				$wpdb->update( "{$wpdb->prefix}core_contributors", [
					'type'       => $type,
				], [
					'username'   => $contributor,
					'wp_version' => $wp_version
				] );
				$this->maybe_update_name( $contributor, $details );
			}
		} else {
			if ( is_array( $details ) ) {
				$wpdb->insert( "{$wpdb->prefix}core_contributors", [
					'username'   => $contributor,
					'wp_version' => $wp_version,
					'title'      => $details[3],
					'type'       => $type,
				] );
				$this->maybe_update_name( $contributor, $details[0], $details[1] );
			} else {
				$wpdb->insert( "{$wpdb->prefix}core_contributors", [
					'username'   => $contributor,
					'wp_version' => $wp_version,
					'type'       => $type,
				] );
				$this->maybe_update_name( $contributor, $details );
			}
		}
	}
	function maybe_update_name( $username, $name = '', $gravatar = '' ) {
		global $wpdb;

		$username = str_replace( ' ', '-', strtolower( trim( $username ) ) );
		$name = trim( $name );
		$gravatar = trim( $gravatar );

		$exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE username = %s", $username ) );
		if ( $exists ) {
			//only update if new name is set, and there is no name yet or current name is not the username
			if ( ! empty( $name ) && ( ( $exists->name == $username && $name != $username ) || empty( $exists->name ) ) ) {
				$wpdb->update( "{$wpdb->prefix}core_contributor_names", [
					'name'     => $name
				], [
					'username' => $username
				] );
			}
			if ( ! empty( $gravatar ) ) {
				$wpdb->update( "{$wpdb->prefix}core_contributor_names", [
					'gravatar' => $gravatar,
				], [
					'username' => $username
				] );
			}
		} else {
			$wpdb->insert( "{$wpdb->prefix}core_contributor_names", [
				'username'   => $username,
				'name'       => $name,
				'gravatar'   => $gravatar,
			] );
		}
	}
}

Contributor_NFT::instance();
