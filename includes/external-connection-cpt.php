<?php

namespace Distributor\ExternalConnectionCPT;

/**
 * Setup actions and filters
 *
 * @since 0.8
 */
function setup() {
	add_action( 'plugins_loaded', function() {
		add_action( 'init', __NAMESPACE__ . '\setup_cpt' );
		add_filter( 'enter_title_here', __NAMESPACE__ . '\filter_enter_title_here', 10, 2 );
		add_filter( 'post_updated_messages', __NAMESPACE__ . '\filter_post_updated_messages' );
		add_action( 'save_post', __NAMESPACE__ . '\save_post' );
		add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\admin_enqueue_scripts' );
		add_action( 'wp_ajax_dt_verify_external_connection', __NAMESPACE__ . '\ajax_verify_external_connection' );
		add_action( 'wp_ajax_dt_verify_external_connection_endpoint', __NAMESPACE__ . '\ajax_verify_external_connection_endpoint' );
		add_filter( 'manage_dt_ext_connection_posts_columns', __NAMESPACE__ . '\filter_columns' );
		add_action( 'manage_dt_ext_connection_posts_custom_column', __NAMESPACE__ . '\action_custom_columns', 10, 2 );
		add_action( 'admin_menu', __NAMESPACE__ . '\add_menu_item' );
		add_action( 'admin_menu', __NAMESPACE__ . '\add_submenu_item', 11 );
		add_action( 'load-toplevel_page_distributor', __NAMESPACE__ . '\setup_list_table' );
		add_filter( 'set-screen-option', __NAMESPACE__ . '\set_screen_option', 10, 3 );
	} );
}

/**
 * Set screen option for posts per page
 *
 * @param  string $status
 * @param  string $option
 * @param  mixed  $value
 * @since  0.8
 * @return mixed
 */
function set_screen_option( $status, $option, $value ) {
	return $value;
}

/**
 * Setup list table and process actions
 *
 * @since  0.8
 */
function setup_list_table() {
	global $connection_list_table;

	$connection_list_table = new \Distributor\ExternalConnectionListTable();

	$pagenum = $connection_list_table->get_pagenum();

	$doaction = $connection_list_table->current_action();

	if ( ! empty( $doaction ) ) {
		check_admin_referer( 'bulk-posts' );

		if ( 'bulk-delete' === $doaction ) {
			$sendback = remove_query_arg( array( 'trashed', 'untrashed', 'deleted', 'locked', 'ids' ), wp_get_referer() );

			$deleted = 0;
			$post_ids = array_map( 'intval', $_REQUEST['post'] );

			foreach ( (array) $post_ids as $post_id ) {
				wp_delete_post( $post_id );

				$deleted++;
			}
			$sendback = add_query_arg( 'deleted', $deleted, $sendback );

			$sendback = remove_query_arg( array( 'action', 'action2', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status', 'post', 'bulk_edit', 'post_view' ), $sendback );

			wp_redirect( $sendback );
		}

		exit;
	}
}

/**
 * Add url column to posts table
 *
 * @param  array $columns
 * @since  0.8
 * @return array
 */
function filter_columns( $columns ) {
	$columns['dt_external_connection_url'] = esc_html__( 'URL', 'distributor' );

	unset( $columns['date'] );
	return $columns;
}

/**
 * Output url column
 *
 * @param  string $column
 * @param  int    $post_id
 * @since  0.8
 */
function action_custom_columns( $column, $post_id ) {
	if ( 'dt_external_connection_url' == $column ) {
		$url = get_post_meta( $post_id, 'dt_external_connection_url', true );

		if ( ! empty( $url ) ) {
			echo esc_url( $url );
		} else {
			esc_html_e( 'None', 'distributor' );
		}
	}
}

/**
 * Check push and pull connections via AJAX
 *
 * @since  0.8
 */
function ajax_verify_external_connection() {
	if ( ! check_ajax_referer( 'dt-verify-ext-conn', 'nonce', false ) ) {
		wp_send_json_error();
		exit;
	}

	if ( empty( $_POST['url'] ) || empty( $_POST['type'] ) ) {
		wp_send_json_error();
		exit;
	}

	$auth = array();
	if ( ! empty( $_POST['auth'] ) ) {
		$auth = $_POST['auth'];
	}

	$current_auth = get_post_meta( $_POST['endpoint_id'], 'dt_external_connection_auth', true );

	if ( ! empty( $current_auth ) ) {
		$auth = array_merge( $auth, (array) $current_auth );
	}

	// Create an instance of the connection to test connections
	$external_connection_class = \Distributor\Connections::factory()->get_registered()[ $_POST['type'] ];

	$auth_handler = new $external_connection_class::$auth_handler_class( $auth );

	// Init with placeholders since we haven't created yet
	$external_connection = new $external_connection_class( 'connection-test', $_POST['url'], 0, $auth_handler );

	$external_connections = $external_connection->check_connections();

	wp_send_json_success( $external_connections );
	exit;
}

/**
 * Enqueue admin scripts for external connection editor
 *
 * @param  string $hook
 * @since  0.8
 */
function admin_enqueue_scripts( $hook ) {
	if ( ( 'post.php' === $hook && 'dt_ext_connection' === get_post_type() ) || ( 'post-new.php' === $hook && ! empty( $_GET['post_type'] ) && 'dt_ext_connection' === $_GET['post_type'] ) ) {

	    if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$js_path = '/assets/js/src/admin-external-connection.js';
			$css_path = '/assets/css/admin-external-connection.css';
		} else {
			$js_path = '/assets/js/admin-external-connection.min.js';
			$css_path = '/assets/css/admin-external-connection.min.css';
		}

		wp_enqueue_style( 'dt-admin-external-connection', plugins_url( $css_path, __DIR__ ), array(), DT_VERSION );
	    wp_enqueue_script( 'dt-admin-external-connection', plugins_url( $js_path, __DIR__ ), array( 'jquery', 'underscore' ), DT_VERSION, true );

	    wp_localize_script( 'dt-admin-external-connection', 'dt', array(
	    	'nonce' => wp_create_nonce( 'dt-verify-ext-conn' ),
	    	'bad_connection' => esc_html__( 'No connection found.', 'distributor' ),
	    	'good_connection' => esc_html__( 'Connection established.', 'distributor' ),
	    	'limited_connection' => esc_html__( 'Limited connection established.', 'distributor' ),
	    	'endpoint_suggestion' => esc_html__( 'Did you mean: ', 'distributor' ),
	    	'endpoint_checking_message' => esc_html__( 'Checking endpoint...', 'distributor' ),
	    	'no_push' => esc_html__( 'Push unavailable.', 'distributor' ),
	    	'change' => esc_html__( 'Change', 'distributor' ),
	    	'cancel' => esc_html__( 'Cancel', 'distributor' ),
	    	'no_distributor' => esc_html__( 'Distributor not installed on remote site.', 'distributor' ),
	    	'roles_warning' => esc_html__( 'Be careful assigning less trusted roles push privileges as they will inherit the capabilities of the user on the remote site.', 'distributor' ),
	    ) );

		wp_dequeue_script( 'autosave' );
	}

	if ( ! empty( $_GET['page'] ) && 'distributor' === $_GET['page'] ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$css_path = '/assets/css/admin-external-connections.css';
		} else {
			$css_path = '/assets/css/admin-external-connections.min.css';
		}

		wp_enqueue_style( 'dt-admin-external-connections', plugins_url( $css_path, __DIR__ ), array(), DT_VERSION );
	}

	if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
		$css_path = '/assets/css/admin.css';
	} else {
		$css_path = '/assets/css/admin.min.css';
	}

	wp_enqueue_style( 'dt-admin', plugins_url( $css_path, __DIR__ ), array(), DT_VERSION );
}

/**
 * Change title text box label
 *
 * @param  string $label
 * @param  int    $post
 * @since  0.8
 * @return string
 */
function filter_enter_title_here( $label, $post = 0 ) {
	if ( 'dt_ext_connection' !== get_post_type( $post->ID ) ) {
		return $label;
	}

	return esc_html__( 'Enter external connection name', 'distributor' );
}

/**
 * Save external connection stuff
 *
 * @param int $post_id
 * @since 0.8
 */
function save_post( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || 'revision' == get_post_type( $post_id ) ) {
		return;
	}

	if ( empty( $_POST['dt_external_connection_details'] ) || ! wp_verify_nonce( $_POST['dt_external_connection_details'], 'dt_external_connection_details_action' ) ) {
		return;
	}

	if ( empty( $_POST['dt_external_connection_type'] ) ) {
		delete_post_meta( $post_id, 'dt_external_connection_type' );
	} else {
		update_post_meta( $post_id, 'dt_external_connection_type', sanitize_text_field( $_POST['dt_external_connection_type'] ) );
	}

	if ( empty( $_POST['dt_external_connection_allowed_roles'] ) ) {
		delete_post_meta( $post_id, 'dt_external_connection_allowed_roles' );
	} else {
		update_post_meta( $post_id, 'dt_external_connection_allowed_roles', array_map( 'sanitize_text_field', $_POST['dt_external_connection_allowed_roles'] ) );
	}

	if ( empty( $_POST['dt_external_connection_url'] ) ) {
		delete_post_meta( $post_id, 'dt_external_connection_url' );
		delete_post_meta( $post_id, 'dt_external_connections' );
		delete_post_meta( $post_id, 'dt_external_connection_check_time' );
	} else {
		update_post_meta( $post_id, 'dt_external_connection_url', sanitize_text_field( $_POST['dt_external_connection_url'] ) );

		// Create an instance of the connection to test connections
		$external_connection_class = \Distributor\Connections::factory()->get_registered()[ $_POST['dt_external_connection_type'] ];

		$auth = array();
		if ( ! empty( $_POST['dt_external_connection_auth'] ) ) {
			$auth = $_POST['dt_external_connection_auth'];
		}

		$current_auth = get_post_meta( $post_id, 'dt_external_connection_auth', true );

		if ( ! empty( $current_auth ) ) {
			$auth = array_merge( $auth, (array) $current_auth );
		}

		$auth_handler = new $external_connection_class::$auth_handler_class( $auth );

		$external_connection = new $external_connection_class( get_the_title( $post_id ), $_POST['dt_external_connection_url'], $post_id, $auth_handler );

		$external_connections = $external_connection->check_connections();

		update_post_meta( $post_id, 'dt_external_connections', $external_connections );
		update_post_meta( $post_id, 'dt_external_connection_check_time', time() );
	}

	if ( ! empty( $_POST['dt_external_connection_auth'] ) ) {
		$current_auth = get_post_meta( $post_id, 'dt_external_connection_auth', true );
		if ( empty( $current_auth ) ) {
			$current_auth = array();
		}

		$connection_class = \Distributor\Connections::factory()->get_registered()[ $_POST['dt_external_connection_type'] ];
		$auth_handler_class_again = $connection_class::$auth_handler_class;

		$auth_creds = $auth_handler_class_again::prepare_credentials( array_merge( (array) $current_auth, $_POST['dt_external_connection_auth'] ) );

		$auth_handler_class_again::store_credentials( $post_id, $auth_creds );
	}
}

/**
 * Register meta boxes
 *
 * @since 0.8
 */
function add_meta_boxes() {
	add_meta_box( 'dt_external_connection_details', esc_html__( 'External Connection Details', 'distributor' ), __NAMESPACE__ . '\meta_box_external_connection_details', 'dt_ext_connection', 'normal', 'core' );
}

/**
 * Output connection options meta box
 *
 * @since 0.8
 * @param $post
 */
function meta_box_external_connection_details( $post ) {
	wp_nonce_field( 'dt_external_connection_details_action', 'dt_external_connection_details' );

	$external_connection_type = get_post_meta( $post->ID, 'dt_external_connection_type', true );

	$auth = get_post_meta( $post->ID, 'dt_external_connection_auth', true );
	if ( empty( $auth ) ) {
		$auth = array();
	}

	$external_connection_url = get_post_meta( $post->ID, 'dt_external_connection_url', true );
	if ( empty( $external_connection_url ) ) {
		$external_connection_url = '';
	}

	$external_connections = get_post_meta( $post->ID, 'dt_external_connections', true );

	$registered_external_connection_types = \Distributor\Connections::factory()->get_registered();

	foreach ( $registered_external_connection_types as $slug => $class ) {
		$parent_class = get_parent_class( $class );

		if ( 'Distributor\ExternalConnection' !== get_parent_class( $class ) ) {
			unset( $registered_external_connection_types[ $slug ] );
		}
	}

	$allowed_roles = get_post_meta( $post->ID, 'dt_external_connection_allowed_roles', true );

	if ( empty( $allowed_roles ) ) {
		$allowed_roles = array( 'administrator', 'editor' );
	} else {
		$allowed_roles[] = 'administrator';
	}
	?>

	<?php if ( 1 === count( $registered_external_connection_types ) ) : $registered_connection_types_keys = array_keys( $registered_external_connection_types ); ?>
		<input id="dt_external_connection_type" class="external-connection-type-field" type="hidden" name="dt_external_connection_type" value="<?php echo esc_attr( $registered_connection_types_keys[0] ); ?>">
	<?php else : ?>
		<p>
			<label for="dt_external_connection_type"><?php esc_html_e( 'External Connection Type', 'distributor' ); ?></label><br>
			<select name="dt_external_connection_type" class="external-connection-type-field" id="dt_external_connection_type">
				<?php foreach ( $registered_connection_types as $slug => $external_connection_class ) : ?>
					<option <?php selected( $slug, $external_connection_type ); ?> value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_attr( $external_connection_class::$label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'We need to know what type of API we are communicating with.', 'distributor' ); ?></span>
		</p>
	<?php endif; ?>

	<?php foreach ( $registered_external_connection_types as $external_connection_class ) : $auth_handler_class_again = $external_connection_class::$auth_handler_class;
		if ( ! $auth_handler_class_again::$requires_credentials ) { continue; } ?>
		<div class="auth-credentials <?php echo esc_attr( $auth_handler_class_again::$slug ); ?>">
			<?php $auth_handler_class_again::credentials_form( $auth ); ?>
		</div>
	<?php endforeach; ?>
	<div class="connection-field-wrap">
		<label for="dt_external_connection_url"><?php esc_html_e( 'External Connection URL', 'distributor' ); ?></label><br>
		<span class="external-connection-url-field-wrapper">
			<input value="<?php echo esc_url( $external_connection_url ); ?>" type="text" name="dt_external_connection_url" id="dt_external_connection_url" class="widefat external-connection-url-field">
		</span>

		<span class="description endpoint-result"></span>
		<ul class="endpoint-errors"></ul>
	</div>

	<p class="dt-roles-allowed">
		<label><?php esc_html_e( 'Roles Allowed to Push', 'distributor' ); ?></label><br>

		<?php
		$editable_roles = get_editable_roles();
		foreach ( $editable_roles as $role => $details ) {
			$name = translate_user_role( $details['name'] );
			?>

			<label for="dt-role-<?php echo esc_attr( $role ); ?>"><input class="dt-role-checkbox" name="dt_external_connection_allowed_roles[]" id="dt-role-<?php echo esc_attr( $role ); ?>" type="checkbox" <?php checked( true, in_array( $role, $allowed_roles ) ); ?> value="<?php echo esc_attr( $role ); ?>"> <?php echo esc_html( $name ); ?></label><br>

			<?php
		}
		?>
		<span class="description"><?php esc_html_e( 'Please be warned all these users will inherit the permissions of the user on the remote site', 'distributor' ); ?></p>
	</p>

	<p>
		<input type="hidden" name="post_status" value="publish">
		<input type="hidden" name="original_post_status" value="<?php echo esc_attr( $post->post_status ); ?>">

		<?php if ( 0 < strtotime( $post->post_date_gmt . ' +0000' ) ) : ?>

			<input name="save" type="submit" class="button button-primary button-large" id="publish" value="<?php esc_attr_e( 'Update Connection', 'distributor' ) ?>">

			<a class="delete-link" href="<?php echo esc_url( get_delete_post_link( $post->ID ) ); ?> "><?php esc_html_e( 'Move to Trash', 'distributor' ); ?></a>
		<?php else : ?>
			<input name="publish" type="submit" class="button button-primary button-large" id="publish" value="<?php esc_attr_e( 'Create Connection', 'distributor' ) ?>">
		<?php endif; ?>
	</p>
	<?php
}

/**
 * Output pull dashboard with custom list table
 *
 * @since 0.8
 */
function dashboard() {
	global $connection_list_table;

	$_GET['post_type'] = 'dt_ext_connection';

	$post_type_object = get_post_type_object( 'dt_ext_connection' );

	$connection_list_table->prepare_items();
	?>

	<div class="wrap">
		<h1><?php esc_html_e( 'External Connections', 'distributor' ); ?> <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dt_ext_connection' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'distributor' ); ?></a></h1>

		<?php $connection_list_table->views(); ?>

		<form id="posts-filter" method="get">

		<input type="hidden" name="post_status" class="post_status_page" value="<?php echo ! empty( $_REQUEST['post_status'] ) ? esc_attr( $_REQUEST['post_status'] ) : 'all'; ?>">
		<input type="hidden" name="post_type" class="post_type_page" value="dt_ext_connection">
		<input type="hidden" name="page" value="distributor">

		<?php $connection_list_table->display(); ?>

		</form>
	</div>
	<?php
}

/**
 * Add a screen option for posts per page
 *
 * @since  0.8
 */
function screen_option() {

	$option = 'per_page';
	$args   = [
		'label'   => esc_html__( 'External connections per page: ', 'distributor' ),
		'default' => get_option( 'posts_per_page' ),
		'option'  => 'connections_per_page',
	];

	add_screen_option( $option, $args );
}

/**
 * Set up top menu item
 *
 * @since 0.8
 */
function add_menu_item() {
	$hook = add_menu_page(
		'Distributor',
		'Distributor',
		apply_filters( 'dt_capabilities', 'manage_options' ),
		'distributor',
		__NAMESPACE__ . '\dashboard',
		'dashicons-share-alt2'
	);

	add_action( "load-$hook", __NAMESPACE__ . '\screen_option' );
}

/**
 * Set up sub menu item to be last
 *
 * @since 0.8
 */
function add_submenu_item() {
	global $submenu;
	unset( $submenu['distributor'][0] );
	add_submenu_page( 'distributor', esc_html__( 'External Connections', 'distributor' ), esc_html__( 'External Connections', 'distributor' ), apply_filters( 'dt_external_capabilities', 'manage_options' ), 'distributor' );
}

/**
 * Register connection post type
 *
 * @since 0.8
 */
function setup_cpt() {

	$labels = array(
		'name'               => esc_html__( 'External Connections', 'distributor' ),
		'singular_name'      => esc_html__( 'External Connection', 'distributor' ),
		'add_new'            => esc_html__( 'Add New', 'distributor' ),
		'add_new_item'       => esc_html__( 'Add New External Connection', 'distributor' ),
		'edit_item'          => esc_html__( 'Edit External Connection', 'distributor' ),
		'new_item'           => esc_html__( 'New External Connection', 'distributor' ),
		'all_items'          => esc_html__( 'All External Connections', 'distributor' ),
		'view_item'          => esc_html__( 'View External Connection', 'distributor' ),
		'search_items'       => esc_html__( 'Search External Connections', 'distributor' ),
		'not_found'          => esc_html__( 'No external connections found.', 'distributor' ),
		'not_found_in_trash' => esc_html__( 'No external connections found in trash.', 'distributor' ),
		'parent_item_colon'  => '',
		'menu_name'          => esc_html__( 'Distributor', 'distributor' ),
	);

	$args = array(
		'labels'               => $labels,
		'public'               => true,
		'publicly_queryable'   => false,
		'show_ui'              => true,
		'show_in_menu'         => false,
		'query_var'            => false,
		'rewrite'              => false,
		'capability_type'      => 'post',
		'hierarchical'         => false,
		'supports'             => array( 'title' ),
		'register_meta_box_cb' => __NAMESPACE__ . '\add_meta_boxes',
	);

	register_post_type( 'dt_ext_connection', $args );
}

/**
 * Filter CPT messages
 *
 * @param  array $messages
 * @since  0.8
 * @return array
 */
function filter_post_updated_messages( $messages ) {
	global $post, $post_ID;

	$messages['dt_ext_connection'] = array(
		0 => '',
		1 => esc_html__( 'External connection updated.', 'distributor' ),
		2 => esc_html__( 'Custom field updated.', 'distributor' ),
		3 => esc_html__( 'Custom field deleted.', 'distributor' ),
		4 => esc_html__( 'External connection updated.', 'distributor' ),
		5 => isset( $_GET['revision'] ) ? sprintf( __( ' External connection restored to revision from %s', 'distributor' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
		6 => esc_html__( 'External connection created.', 'distributor' ),
		7 => esc_html__( 'External connection saved.', 'distributor' ),
		8 => esc_html__( 'External connection submitted.', 'distributor' ),
		9 => sprintf( __( 'External connection scheduled for: <strong>%1$s</strong>.', 'distributor' ),
		date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ) ),
		10 => esc_html__( 'External connection draft updated.', 'distributor' ),
	);

	return $messages;
}

