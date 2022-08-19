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

const CONTRIBUTOR_NFT_VERSION = '0.1.0';

class Contributor_NFT {

	private static $instance;

	public function __construct() {

		add_action( 'admin_menu', array( $this, 'plug_pages' ) );

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

	/**
	 * Activation hook install routine.
	 */
	public static function install() {
		global $wpdb;

		$sql1 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}core_contributors` (
		  `token_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
		  `username` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `wp_version` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `minted` tinyint(1) NOT NULL DEFAULT 0,
		  `title` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `type` enum('noteworthy','core') COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  PRIMARY KEY (`token_id`),
		  UNIQUE KEY `username` (`username`,`wp_version`),
		  KEY `minted` (`minted`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;";

		$sql2 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}core_contributor_names` (
		  `username` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `name` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `gravatar` varchar(32) COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  PRIMARY KEY (`username`),
		  KEY `name` (`name`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;";

		$sql3 = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}core_versions` (
		  `wp_version` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `release_date` date NOT NULL,
		  `musician` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  PRIMARY KEY (`wp_version`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql1 );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
	}

	function plug_pages() {
		$page = add_management_page( __( 'Contributor NFTs', 'iup' ), __( 'Contributor NFTs', 'iup' ), 'manage_options', "nfts", [ $this, 'admin_page' ] );
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

			if ( isset( $_POST['new_wp_version'] ) ) {
				$wp_version   = sanitize_text_field( $_POST['wp_version'] );
				$release_date = date( 'Y-m-d', strtotime( sanitize_text_field( $_POST['release_date'] ) ) );
				$musician     = sanitize_text_field( $_POST['musician'] );
				$result       = $wpdb->replace( $wpdb->prefix . 'core_versions', [
					'wp_version'   => $wp_version,
					'release_date' => $release_date,
					'musician'     => $musician,
				] );
				if ( $result ) {
					$imported = $this->get_credits( $wp_version );

					//import any new contributor missing gravatars
					$missing_gravatars = $wpdb->get_col( "SELECT username FROM {$wpdb->prefix}core_contributor_names WHERE gravatar = ''" );
					foreach ( $missing_gravatars as $username ) {
						$this->get_wporg_gravatar( $username );
					}

					echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( __( 'Version added successfully with %s contributors', 'iup' ), number_format_i18n( $imported ) ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p>' . __( 'Error adding version', 'iup' ) . '</p></div>';
				}
			}

			if ( isset( $_POST['submit_contributors'] ) ) {
				$data = wp_filter_nohtml_kses( $_POST['version_html'] );
				$data = explode( ', ', $data );
				foreach ( $data as $person ) {
					if ( preg_match( '/([\w.-]+)(?:\/([\w-]+))?(?:\s\(([^)]+)\))?/', $person, $matches ) ) {
						if ( $matches[3] == 'prof' || empty( $matches[3] ) ) {
							$matches[3] = $matches[1];
						}
						$name     = ! empty( $matches[2] ) ? "$matches[3] ($matches[2])" : ltrim( $matches[3], '(' );
						$username = str_replace( ' ', '-', strtolower( trim( $matches[1] ) ) );
						$exists   = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}core_contributors WHERE username = %s AND wp_version = %s", $username, stripslashes( $_POST['wp_version'] ) ) );
						if ( $exists ) {
							$wpdb->update( "{$wpdb->prefix}core_contributors", [
								'type' => $_POST['type'],
							], [
								'username'   => $username,
								'wp_version' => stripslashes( $_POST['wp_version'] ),
							] );
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
					$exists = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE username = %s OR name = %s", $person, $person ) );
					if ( $exists ) {
						$matches[] = "$exists->username ($exists->name)";
					} else {
						$exists = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE name LIKE %s", "%$person%" ) );
						if ( $exists ) {
							$maybe[] = "$person == $exists->username ($exists->name)";
						} else {
							$names  = explode( ' ', $person );
							$last   = end( $names );
							$exists = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE name LIKE %s", "%$last%" ) );
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
				echo implode( ', ', $unknown ) . '<br>';

			}
			if ( isset( $_GET['import_gravatars'] ) ) {
				$users = $wpdb->get_col( $wpdb->prepare( "SELECT username FROM {$wpdb->prefix}core_contributor_names WHERE gravatar = '' LIMIT %d", $_GET['import_gravatars'] ) );
				foreach ( $users as $username ) {
					$gravatar = $this->get_wporg_gravatar( $username );
					if ( $gravatar ) {
						?>
						<div style="float: left; margin: 5px;">
							<?php echo esc_html( $username ); ?><br/>
							<img src="https://www.gravatar.com/avatar/<?php echo $gravatar; ?>?s=75&d=mystery" alt="Avatar"/>
						</div>
						<?php
					} else {
						?>
						<div style="float: left; margin: 5px;">
							<?php echo esc_html( $username ); ?><br/>
							MISSING
						</div>
						<?php
					}
					//sleep( 1 );
				}
				echo '<div style="clear: both;"></div>';
			}
			?>
			<h1 class="wp-heading-inline">
				<?php _e( 'Core Contributor NFT Management' ); ?>
			</h1>

			<table class="wp-list-table widefat fixed striped table-view-list">
				<thead>
				<tr>
					<th scope="col" id="title" class="manage-column column-title column-primary">WordPress Version</th>
					<th scope="col" id="release" class="manage-column column-title">Release Date</th>
					<th scope="col" id="musician" class="manage-column column-title">Jazz Musician</th>
					<th scope="col" id="contributors" class="manage-column">Contributors</th>
					<th scope="col" id="minted" class="manage-column">NFTs Minted</th>
				</tr>
				</thead>

				<tbody>
				<?php
				$minted = 0;
				$versions = $wpdb->get_results( "SELECT v.*, count(c.token_id) as contributors, sum(c.minted) as minted FROM {$wpdb->prefix}core_versions v JOIN {$wpdb->prefix}core_contributors c ON v.wp_version = c.wp_version GROUP BY v.wp_version;" );
				$contributors = $wpdb->get_var( "SELECT count(DISTINCT username) FROM {$wpdb->prefix}core_contributors;" );
				foreach ( $versions as $version ) {
					$minted += $version->minted;
					?>
				<tr class="">
					<td class="title column-title column-primary page-title" data-colname="WordPress Version">
						<strong><?php echo esc_html( $version->wp_version ); ?></strong>
					</td>
					<td class="" data-colname="Release Date"><?php echo esc_html( $version->release_date ); ?></td>
					<td class="" data-colname="Musician"><?php echo esc_html( $version->musician ); ?></td>
					<td class="" data-colname="Contributors"><?php echo number_format_i18n( $version->contributors ); ?></td>
					<td class="" data-colname="Minted"><?php echo number_format_i18n( $version->minted ); ?></td>
				</tr>
					<?php
				}
				?>
				</tbody>

				<tfoot>
				<tr>
					<th scope="col" id="title" class="manage-column column-title column-primary">Totals:</th>
					<th colspan="2" scope="col" id="release" class="manage-column column-title"><?php echo number_format_i18n( count( $versions ) ); ?> Releases</th>
					<th scope="col" id="contributors" class="manage-column"><?php echo number_format_i18n( $contributors ); ?> Unique Contributors</th>
					<th scope="col" id="minted" class="manage-column"><?php echo number_format_i18n( $minted ); ?> NFTs Minted</th>
				</tr>
				</tfoot>
			</table>

			<h2>Import New WordPress Version</h2>
			<form method="post">
				<table class="form-table" role="presentation">

					<tbody>
					<tr>
						<th scope="row"><label for="wp_version">WordPress Version</label></th>
						<td>
							<select name="wp_version" id="wp_version" class="postform">
								<?php
								for ( $major = 6; $major < 10; $major ++ ) {
									for ( $minor = 0; $minor < 10; $minor ++ ) { ?>
										<option class="level-0" value="<?php echo esc_attr( $major . '.' . $minor ); ?>"><?php echo esc_html( $major . '.' . $minor ); ?></option>
									<?php }
								} ?>
							</select>
					</tr>
					<tr>
						<th scope="row"><label for="type">Release Date</label></th>
						<td>
							<input type="date" name="release_date" value=""/>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="type">Jazz Musician</label></th>
						<td>
							<input type="text" name="musician" value="" placeholder="i.e. Art Tatum"/>
						</td>
					</tr>

					</tbody>
				</table>
				<p class="submit"><input type="submit" name="new_wp_version" id="submit" class="button button-primary" value="Create WP Version"></p>
			</form>

			<hr/>
			<h2>Manually Add Contributors</h2>
			<form method="post">
				<table class="form-table" role="presentation">

					<tbody>
					<tr>
						<th scope="row"><label for="wp_version">WordPress Version</label></th>
						<td>
							<select name="wp_version" id="wp_version" class="postform">
								<?php
								$versions = $wpdb->get_col( "SELECT wp_version FROM {$wpdb->prefix}core_versions WHERE wp_version < '3.2'" );
								foreach ( $versions as $version ) { ?>
									<option class="level-0" value="<?php echo esc_attr( $version ); ?>"><?php echo esc_html( stripslashes( $version ) ); ?></option>
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

					</tbody>
				</table>
				<p class="submit"><input type="submit" name="submit_contributors" id="submit" class="button button-primary" value="Submit"></p>
			</form>

			<hr/>
			<form method="post">
				<table class="form-table" role="presentation">

					<tbody>
					<tr>
						<th scope="row"><label for="version_html">Translate Contributor names to usernames</label></th>
						<td><textarea id="version_html" name="version_html" class="large-text" rows="20" placeholder="Version list of contributors"></textarea></td>
					</tr>

					</tbody>
				</table>
				<p class="submit"><input type="submit" name="submit_names" id="submit" class="button button-primary" value="Translate Names"></p>
			</form>
		</div>
		<?php
	}

	function import_wp_versions() {
		global $wpdb;

		$versions = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}core_versions WHERE wp_version >= '3.2' ORDER BY wp_version ASC" );
		foreach ( $versions as $version ) {
			$this->get_credits( $version->wp_version );
		}

		return $versions;
	}

	/**
	 * Get the associated WordPress username for a given Github username.
	 *
	 * @param $github_username
	 *
	 * @return string|false
	 */
	function get_wporg_profile( $github_username ) {
		$response = wp_remote_get( "https://profiles.wordpress.org/wp-json/wporg-github/v1/lookup/$github_username", [ 'user-agent' => 'Web3WP Core Contributor NFT' ] );
		if ( is_array( $response ) && ! is_wp_error( $response ) && 200 == wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( $response['body'] ); // use the content

			return $data->slug;
		}

		return false;
	}

	function get_wporg_gravatar( $username ) {
		$response = wp_remote_get( "https://profiles.wordpress.org/$username/", [ 'user-agent' => 'Web3 WP Core Contributor NFT (https://web3wp.com)' ] );
		if ( is_array( $response ) && ! is_wp_error( $response ) && 200 == wp_remote_retrieve_response_code( $response ) ) {
			if ( preg_match( '/gravatar\.com\/avatar\/([a-f0-9]{32})/', $response['body'], $matches ) ) {
				//update the gravatar hash in the database
				$this->maybe_update_name( $username, '', $matches[1] );

				return $matches[1];
			}
		}

		return false;
	}

	function get_credits( $wp_version ) {
		$imported = 0;
		$response = wp_remote_get( "https://api.wordpress.org/core/credits/1.1/?version=$wp_version", [ 'user-agent' => 'Web3 WP Contributors NFT' ] );
		if ( is_array( $response ) && ! is_wp_error( $response ) && 200 == wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ) ); // use the content
			unset( $data->groups->libraries );
			//import props first
			foreach ( $data->groups->props->data as $contributor => $details ) {
				$this->update_group_credits( $wp_version, 'props', $contributor, $details );
				$imported ++;
			}
			//now import noteworthies
			foreach ( $data->groups as $group_name => $group ) {
				if ( 'props' == $group_name ) {
					continue;
				} //skip libs
				foreach ( $group->data as $contributor => $details ) {
					$this->update_group_credits( $wp_version, $group_name, $contributor, $details );
					$imported ++;
				}
			}
		}

		return $imported;
	}

	function update_group_credits( $wp_version, $group_name, $contributor, $details ) {
		global $wpdb;

		$type        = $group_name == 'props' ? 'core' : 'noteworthy'; //enum in db
		$contributor = str_replace( ' ', '-', strtolower( trim( $contributor ) ) );
		$exists      = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}core_contributors WHERE username = %s AND wp_version = %s", $contributor, $wp_version ) );
		if ( $exists ) {
			if ( is_array( $details ) ) {
				$wpdb->update( "{$wpdb->prefix}core_contributors", [
					'title' => $details[3],
					'type'  => $type,
				], [
					'username'   => $contributor,
					'wp_version' => $wp_version,
				] );
				$this->maybe_update_name( $contributor, $details[0], $details[1] );
			} else {
				$wpdb->update( "{$wpdb->prefix}core_contributors", [
					'type' => $type,
				], [
					'username'   => $contributor,
					'wp_version' => $wp_version,
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
		$name     = html_entity_decode( trim( $name ), ENT_QUOTES );
		$gravatar = trim( $gravatar );

		$exists = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}core_contributor_names WHERE username = %s", $username ) );
		if ( $exists ) {
			//only update if new name is set, and there is no name yet or current name is not the username
			if ( ! empty( $name ) && ( ( $exists->name == $username && $name != $username ) || empty( $exists->name ) ) ) {
				$wpdb->update( "{$wpdb->prefix}core_contributor_names", [
					'name' => $name,
				], [
					'username' => $username,
				] );
			}
			if ( ! empty( $gravatar ) ) {
				$wpdb->update( "{$wpdb->prefix}core_contributor_names", [
					'gravatar' => $gravatar,
				], [
					'username' => $username,
				] );
			}
		} else {
			$wpdb->insert( "{$wpdb->prefix}core_contributor_names", [
				'username' => $username,
				'name'     => $name,
				'gravatar' => $gravatar,
			] );
		}
	}
}

Contributor_NFT::instance();

register_activation_hook( __FILE__, array( 'Contributor_NFT', 'install' ) );
