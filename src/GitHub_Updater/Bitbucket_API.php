<?php
/**
 * GitHub Updater
 *
 * @package   GitHub_Updater
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 */

namespace Fragen\GitHub_Updater;

/**
 * Get remote data from a Bitbucket repo.
 *
 * Class    Bitbucket_API
 * @package Fragen\GitHub_Updater
 * @author  Andy Fragen
 */
class Bitbucket_API extends Base {

	/**
	 * Constructor.
	 *
	 * @param object $type
	 */
	public function __construct( $type ) {
		$this->type  = $type;
		parent::$hours = 12;
		add_filter( 'http_request_args', array( $this, 'maybe_authenticate_http' ), 10, 2 );

		if ( ! isset( self::$options['bitbucket_username'] ) ) {
			self::$options['bitbucket_username'] = null;
		}
		if ( ! isset( self::$options['bitbucket_password'] ) ) {
			self::$options['bitbucket_password'] = null;
		}
		add_site_option( 'github_updater', self::$options );
	}

	/**
	 * Read the remote file and parse headers.
	 * Saves headers to transient.
	 *
	 * Uses a transient to limit the calls to the API.
	 */
	public function get_remote_info( $file ) {
		$response = $this->get_transient( $file );

		if ( ! $response ) {
			if ( empty( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}
			$response = $this->api( '/1.0/repositories/:owner/:repo/src/' . trailingslashit( $this->type->branch ) . $file );

			if ( $response ) {
				$contents = $response->data;
				$response = $this->get_file_headers( $contents, $this->type->type );
				$this->set_transient( $file, $response );
			}
		}

		if ( $this->validate_response( $response ) || ! is_array( $response ) ) {
			return false;
		}

		$this->type->transient            = $response;
		$this->type->remote_version       = strtolower( $response['Version'] );
		$this->type->branch               = ! empty( $response['Bitbucket Branch'] ) ? $response['Bitbucket Branch'] : 'master';
		$this->type->requires_php_version = ! empty( $response['Requires PHP'] ) ? $response['Requires PHP'] : $this->type->requires_php_version;
		$this->type->requires_wp_version  = ! empty( $response['Requires WP'] ) ? $response['Requires WP'] : $this->type->requires_wp_version;

		return true;
	}

	/**
	 * Parse the remote info to find most recent tag if tags exist
	 *
	 * Uses a transient to limit the calls to the API.
	 *
	 * @return string latest tag.
	 */
	public function get_remote_tag() {
		$download_link_base = 'https://bitbucket.org/' . trailingslashit( $this->type->owner ) . $this->type->repo . '/get/';
		$response           = $this->get_transient( 'tags' );

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/:owner/:repo/tags' );
			$arr_resp = (array) $response;

			if ( ! $response || ! $arr_resp ) {
				$response->message = 'No tags found';
			}

			if ( $response ) {
				$this->set_transient( 'tags', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		/**
		 * Sort and get newest tag.
		 */
		$tags     = array();
		$rollback = array();
		if ( false !== $response ) {
			foreach ( (array) $response as $num => $tag ) {
				if ( isset( $num ) ) {
					$tags[]           = $num;
					$rollback[ $num ] = $download_link_base . $num . '.zip';
				}
			}
		}

		// no tags are present, exit early
		if ( empty( $tags ) ) {
			return false;
		}

		usort( $tags, 'version_compare' );
		krsort( $rollback );

		$newest_tag             = null;
		$newest_tag_key         = key( array_slice( $tags, -1, 1, true ) );
		$newest_tag             = $tags[ $newest_tag_key ];

		$this->type->newest_tag = $newest_tag;
		$this->type->tags       = $tags;
		$this->type->rollback   = $rollback;
	}

	/**
	 * Construct $download_link
	 *
	 * @param boolean $rollback for theme rollback
	 * @param boolean $branch_switch for direct branch changing
	 * 
	 * @return string URI
	 */
	public function construct_download_link( $rollback = false, $branch_switch = false ) {
		$download_link_base = 'https://bitbucket.org/' . trailingslashit( $this->type->owner ) . $this->type->repo . '/get/';
		$endpoint           = '';

		/**
		 * Check for rollback.
		 */
		if ( ! empty( $_GET['rollback'] ) && 'upgrade-theme' === $_GET['action'] && $_GET['theme'] === $this->type->repo ) {
			$endpoint .= $rollback . '.zip';
		
		// for users wanting to update against branch other than master or not using tags, else use newest_tag
		} elseif ( 'master' != $this->type->branch || empty( $this->type->tags ) ) {
			$endpoint .= $this->type->branch . '.zip';
		} else {
			$endpoint .= $this->type->newest_tag . '.zip';
		}

		/**
		 * Create endpoint for branch switching.
		 */
		if ( $branch_switch ) {
			$endpoint = $branch_switch . '.zip';
		}

		return $download_link_base . $endpoint;
	}

	/**
	 * Read the remote CHANGES.md file
	 *
	 * Uses a transient to limit calls to the API.
	 *
	 * @param $changes
	 *
	 * @return bool
	 */
	public function get_remote_changes( $changes ) {
		$response = $this->get_transient( 'changes' );

		if ( ! $response ) {
			if ( ! isset( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}
			$response = $this->api( '/1.0/repositories/:owner/:repo/src/' . trailingslashit( $this->type->branch ) . $changes );

			if ( ! $response ) {
				$response['message'] = 'No changelog found';
				$response = (object) $response;
			}

			if ( $response ) {
				$this->set_transient( 'changes', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$changelog = $this->get_transient( 'changelog' );

		if ( ! $changelog ) {
			$parser    = new \Parsedown;
			$changelog = $parser->text( $response->data );
			$this->set_transient( 'changelog', $changelog );
		}

		$this->type->sections['changelog'] = $changelog;
	}

	/**
	 * Read and parse remote readme.txt.
	 *
	 * @return bool
	 */
	public function get_remote_readme() {
		if ( ! file_exists( $this->type->local_path . 'readme.txt' ) ) {
			return false;
		}

		$response = $this->get_transient( 'readme' );

		if ( ! $response ) {
			if ( ! isset( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}
			$response = $this->api( '/1.0/repositories/:owner/:repo/src/' . trailingslashit( $this->type->branch ) . 'readme.txt' );

			if ( ! $response ) {
				$response['message'] = 'No readme found';
				$response = (object) $response;
			}

		}

		if ( $response && isset( $response->data ) ) {
			$parser   = new Readme_Parser;
			$response = $parser->parse_readme( $response->data );
			$this->set_transient( 'readme', $response );
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		/**
		 * Set plugin data from readme.txt.
		 * Prefer changelog from CHANGES.md.
		 */
		$readme = array();
		foreach ( $this->type->sections as $section => $value ) {
			if ( 'description' === $section ) {
				continue;
			}
			$readme['sections/' . $section ] = $value;
		}
		foreach ( $readme as $key => $value ) {
			$key = explode( '/', $key );
			if ( ! empty( $value ) && 'sections' === $key[0] ) {
				unset( $response['sections'][ $key[1] ] );
			}
		}

		unset( $response['sections']['screenshots'] );
		unset( $response['sections']['installation'] );
		$this->type->sections     = array_merge( (array) $this->type->sections, (array) $response['sections'] );
		$this->type->tested       = $response['tested_up_to'];
		$this->type->requires     = $response['requires_at_least'];
		$this->type->donate       = $response['donate_link'];
		$this->type->contributors = $response['contributors'];

		return true;
	}

	/**
	 * Read the repository meta from API
	 *
	 * Uses a transient to limit calls to the API
	 *
	 * @return base64 decoded repository meta data
	 */
	public function get_repo_meta() {
		$response = $this->get_transient( 'meta' );

		if ( ! $response ) {
			$response = $this->api( '/2.0/repositories/:owner/:repo' );

			if ( $response ) {
				$this->set_transient( 'meta', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->repo_meta = $response;
		$this->_add_meta_repo_object();
		$this->get_branches();
	}

	/**
	 * Create array of branches and download links as array.
	 * @return bool
	 */
	public function get_branches() {
		$branches = array();
		$response = $this->get_transient( 'branches' );

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/:owner/:repo/branches' );

			if ( $response ) {
				foreach ( $response as $branch ) {
					$branches[ $branch->branch ] = $this->construct_download_link( false, $branch->branch );
				}
				$this->type->branches = $branches;
				$this->set_transient( 'branches', $branches );
				return true;
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->branches = $response;

		return true;
	}

	/**
	 * Add remote data to type object
	 */
	private function _add_meta_repo_object() {
		$this->type->rating       = $this->make_rating( $this->type->repo_meta );
		$this->type->last_updated = $this->type->repo_meta->updated_on;
		$this->type->num_ratings  = $this->type->watchers;
		$this->type->private      = $this->type->repo_meta->is_private;
	}

	/**
	 * Add Basic Authentication $args to http_request_args filter hook
	 * for private Bitbucket repositories only.
	 *
	 * @param  $args
	 * @param  $url
	 *
	 * @return mixed
	 */
	public function maybe_authenticate_http( $args, $url ) {
		if ( ! isset( $this->type ) || false === stristr( $url, 'bitbucket' ) ) {
			return $args;
		}

		$bitbucket_private         = false;
		$bitbucket_private_install = false;

		/**
		 * Check whether attempting to update private Bitbucket repo.
		 */
		if ( isset( $this->type->repo ) &&
			! empty( parent::$options[ $this->type->repo ] ) &&
		     false !== strpos( $url, $this->type->repo )
		) {
			$bitbucket_private = true;
		}

		/**
		 * Check whether attempting to install private Bitbucket repo
		 * and abort if Bitbucket user/pass not set.
		 */
		if ( isset( $_POST['option_page'] ) &&
		     'github_updater_install' === $_POST['option_page'] &&
		     'bitbucket' === $_POST['github_updater_api'] &&
		     isset( $_POST['is_private'] ) &&
		     ( ! empty( parent::$options['bitbucket_username'] ) || ! empty( parent::$options['bitbucket_password'] ) )
		) {
			$bitbucket_private_install = true;
		}

		if ( $bitbucket_private || $bitbucket_private_install ) {
			$username = parent::$options['bitbucket_username'];
			$password = parent::$options['bitbucket_password'];
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( "$username:$password" );
		}

		return $args;
	}

}