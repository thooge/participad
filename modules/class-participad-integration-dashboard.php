<?php

/**
 * Dashboard integration class
 *
 * This is the class that enables the use of Participad on the Edit Post scren
 *
 * @since 1.0
 */

class Participad_Integration_Dashboard extends Participad_Integration {

	/**
	 * @var The strings that will be passed to a javascript object
	 *
	 * @since 1.0
	 */
	public $localize_script = array();

	function __construct() {
		$this->init();

		// Todo: Move this somewhere else?
		if ( $this->ep_post_id ) {
			add_action( 'get_post_metadata', array( $this, 'prevent_check_edit_lock' ), 10, 4 );
			add_action( 'admin_enqueue_scripts', array( $this, 'disable_autosave' ) );
			add_filter( 'wp_insert_post_data', array( $this, 'sync_etherpad_content_to_wp' ), 10, 2 );
			add_filter( 'wp_insert_post', array( $this, 'catch_dummy_post' ), 10, 2 );
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Will an Etherpad instance appear on this page?
	 *
	 * No need to initialize the API client on every pageload
	 *
	 * Must be overridden in a module class
	 *
	 * @return bool
	 */
	public function load_on_page() {
		$request_uri = $_SERVER['REQUEST_URI'];
		$qpos = strpos( $request_uri, '?' );
		if ( false !== $qpos ) {
			$request_uri = substr( $request_uri, 0, $qpos );
		}

		$retval = false;
		$filename = substr( $request_uri, strrpos( $request_uri, '/' ) + 1 );

		if ( 'post.php' == $filename || 'post-new.php' == $filename ) {
			$retval = true;
		}

		return $retval;
	}

	public function set_wp_post_id() {
		global $post;

		$wp_post_id = 0;

		if ( isset( $_GET['post'] ) ) {
			$wp_post_id = $_GET['post'];
		} else if ( isset( $_POST['post_ID'] ) ) {        // saving post
			$wp_post_id = $_POST['post_ID'];
		} else if ( !empty( $post->ID ) ) {
			$wp_post_id = $post->ID;
		}

		// If we still have no post ID, we're probably in the post
		// creation process. We have to get weird.
		// 1) Create a dummy post for use throughout the process
		// 2) Dynamically add a field to the post creation page that
		//    contains the id of the dummy post
		// 3) When the post is finally created, hook in, look for the
		//    dummy post data, copy it to the new post, and delete the
		//    dummy
		if ( ! $wp_post_id ) {
			$wp_post_id = wp_insert_post( array(
				'post_title'   => 'Participad_Dummy_Post',
				'post_content' => '',
				'post_status'  => 'auto-draft'
			) );

			$this->localize_script['dummy_post_ID'] = $wp_post_id;
		}

		$this->wp_post_id = (int) $wp_post_id;

	}

	/**
	 * Prevents setting WP's _edit_lock when on an Etherpad page
	 *
	 * Works a little funky because of the filters available in WP.
	 * Summary:
	 * 1) Filter get_post_metadata
	 * 2) If the key is '_edit_lock', and if the $object_id is the
	 *    current post, return an empty value
	 * 3) The "empty value" must be cast as an array if $single is false
	 * 4) When get_metadata() sees the empty string come back, it returns
	 *    it, thus tricking the checker into thinking that there's no lock
	 * 5) A side effect is that edit locks can't be set either, because
	 *    this filter kills the duplicate check in update_metadata()
	 */
	public function prevent_check_edit_lock( $retval, $object_id, $meta_key, $single ) {
		if ( '_edit_lock' == $meta_key && ! empty( $this->wp_post_id ) && $this->wp_post_id == $object_id ) {
			$retval = $single ? '' : array( '' );
		}

		return $retval;
	}

	/**
	 * Dequeues WP's autosave script, thereby disabling the feature
	 *
	 * No need for autosave here
	 */
	public function disable_autosave() {
		wp_dequeue_script( 'autosave' );
	}

	/**
	 * On WP post save, look to see whether there's a corresponding EP post,
	 * and if found, sync the EP content into the WP post
	 *
	 * Note that this will overwrite local modifications.
	 * @todo Do more conscientious syncing
	 */
	public function sync_etherpad_content_to_wp( $postdata ) {
		try {
			// We have to concatenaty the getText source
			// differently depending on whether this is a new or
			// existing post
			if ( isset( $_POST['participad_dummy_post_ID'] ) ) {
				$post_id = (int) $_POST['participad_dummy_post_ID'];
				$ep_post_id = get_post_meta( $post_id, 'ep_post_group_id', true ) . '$' . get_post_meta( $post_id, 'ep_post_id', true );
			} else {
				$ep_post_id = $this->ep_post_id_concat;
			}

			$text = participad_client()->getText( $ep_post_id );
			$postdata['post_content'] = $text->text;
		} catch ( Exception $e ) {}

		return $postdata;
	}

	/**
	 * When creating a new post, we need to copy over the metadata from
	 * the dummy WP post into the actual WP post
	 */
	function catch_dummy_post( $post_ID, $post ) {
		if ( isset( $_POST['participad_dummy_post_ID'] ) ) {
			$dummy_post = get_post( $_POST['participad_dummy_post_ID'] );
			update_post_meta( $post_ID, 'ep_post_id', get_post_meta( $dummy_post->ID, 'ep_post_id', true ) );
			update_post_meta( $post_ID, 'ep_post_group_id', get_post_meta( $dummy_post->ID, 'ep_post_group_id', true ) );

			$dummy_session_key = 'ep_group_session_id-post_' . $dummy_post->ID;
			$post_session_key = 'ep_group_session_id-post_' . $post_ID;
			update_user_meta( $this->wp_user_id, $post_session_key, get_user_meta( $this->wp_user_id, $dummy_session_key, true ) );
		}
	}

	public function enqueue_scripts() {
		wp_enqueue_style( 'participad_editor', PARTICIPAD_PLUGIN_URL . '/assets/css/editor.css' );
		wp_enqueue_script( 'participad_editor', PARTICIPAD_PLUGIN_URL . '/assets/js/editor.js', array( 'jquery', 'editor' ) );

		$ep_url = add_query_arg( array(
			'showControls' => 'true',
			'showChat'     => 'false',
			'showLineNumbers' => 'false',
			'useMonospaceFont' => 'false',
		), participad_api_endpoint() . '/p/' . $this->ep_post_group_id . '%24' . $this->ep_post_id );

		$this->localize_script['url'] = $ep_url;
		wp_localize_script( 'participad_editor', 'Participad_Editor', $this->localize_script );
	}
}
