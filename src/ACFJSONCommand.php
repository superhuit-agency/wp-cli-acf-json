<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Process;
use WP_CLI\Utils;

class ACFJSONCommand {

	/**
	 * Import ACF field groups from local files to database
	 *
	 * ## OPTIONS
	 *
	 * [--all_sites]
	 * : Import in all sites of the network
	 *
	 * @subcommand import
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ) {

		$wpcli_config = WP_CLI::get_config();

		if( is_multisite() && ! isset( $wpcli_config['url'] ) && ! isset( $assoc_args['all_sites'] ) ) {

			WP_CLI::error( 'Use the --url=<url> parameter in a multisite, or use the parameter --all_sites (type `wp acf-json help sync` for more info).' );

		} elseif( is_multisite() && isset( $assoc_args['all_sites'] ) ) {

			// loop through sites and sync each
			$sites = get_sites();

			if( $sites ) {

				foreach( $sites as $site ) {

					// switch blog
					switch_to_blog( $site->blog_id );

					// sync
					$this->_sync_from_json();

					// restore
					restore_current_blog();

				}

			}

		} else {

			// sync
			$this->_sync_from_json();

		}

	}

	private function _sync_from_json() {

		// NOTE: the following is copied & adapted from the ACF source code, in 'admin-field-groups.php' function 'check_sync()'
		//       + delete part heavily inspired by https://gist.github.com/nkkollaw/8f0b0047fb6fc4a000bc975719fec682

		// vars
		$paths = acf_get_setting( 'load_json' );
		$groups = acf_get_field_groups();

		// bail if no load_json paths
		if( empty($paths) ) {
			WP_CLI::error( $this->_get_nice_message( "ACF option 'load_json' is disabled, we're not doing anything. See how to configure it here: https://www.advancedcustomfields.com/resources/acf-settings" ) );
			return;
		}

		// bail early if no field groups
		if( empty($groups) ) {
			WP_CLI::success( $this->_get_nice_message( "Nothing to sync!" ) );
			return;
		}

		// find JSON field groups which have not yet been imported
		$sync = array();
		foreach( $groups as $group ) {

			// vars
			$local = acf_maybe_get($group, 'local', false);
			$modified = acf_maybe_get($group, 'modified', 0);
			$private = acf_maybe_get($group, 'private', false);

			// ignore DB / PHP / private field groups
			if( $local !== 'json' || $private ) {
				// do nothing
			} elseif( !$group['ID'] ) {
				$sync[ $group['key'] ] = $group;
			} elseif( $modified && $modified > get_post_modified_time( 'U', true, $group['ID'], true ) ) {
				$sync[ $group['key'] ]  = $group;
			}

		}

		// bail if no sync needed
		if( empty($sync) ) {
			WP_CLI::success( $this->_get_nice_message( "Nothing to sync!" ) );
			return;
		}

		// disable filters to ensure ACF loads raw data from DB
		acf_disable_filters();
		acf_enable_filter( 'local' );

		// disable JSON
		// - this prevents a new JSON file being created and causing a 'change' to theme files - solves git anoyance
		acf_update_setting( 'json', false );

		// vars
		$imported = array();

		// loop
		foreach( $sync as $key => $group ) { //foreach( $keys as $key ) {

			// append fields
			if( acf_have_local_fields($key) ) {
				$group['fields'] = acf_get_local_fields( $key );
			}

			// import
			$field_group = acf_import_field_group( $group );

			// append
			$imported[] = $group['key'];

		}

		// sync groups that have been deleted
		$delete = array();
		foreach( $groups as $group ) {
			$found = false;
			foreach( $paths as $json_dir ) {
				$json_file = rtrim( $json_dir, '/' ) . '/' . $group['key'] . '.json';
				if( is_file( $json_file ) ) {
					$found = true;
					break;
				}
			}
			if( !$found ) {
				$delete[] = $group['key'];
			}
		}
		if( !empty($delete) ) {
			foreach( $delete as $group_key ) {
				acf_delete_field_group( $group_key );
			}
		}

		// print result
		$results_updated = implode( ', ', $imported );
		$results_deleted = implode( ', ', $delete );

		$result = "Finished sync.";

		if ( !empty( $results['updated'] ) ) {
			$result .= " Imported field groups: [$results_updated].";
		}

		if ( !empty( $results['deleted'] ) ) {
			$result .= " Deleted field groups: [$results_deleted].";
		}

		WP_CLI::success( $this->_get_nice_message( $result ) );

	}

	private function _get_nice_message( $msg ) {
		$prefix = "acf-json";
		if( is_multisite() ) {
			$site_id = get_site_url();
			$prefix .= " in $site_id";
		}
		return "$prefix — $msg";
	}
}
