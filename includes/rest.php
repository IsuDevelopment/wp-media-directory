<?php
/**
 * REST API used by the folder sidebar.
 *
 * Every write answers with the fresh tree, so counts in the UI never drift,
 * and with whatever the client needs to undo it.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\REST;

use IsuDevMediaDirectory\Folders;
use IsuDevMediaDirectory\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

const NAMESPACE_V1 = 'isudev-media-directory/v1';

/**
 * Hook route registration.
 *
 * @return void
 */
function boot(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
}

/**
 * Who may use the folders.
 *
 * @return bool
 */
function can_manage(): bool {
	return current_user_can( 'upload_files' );
}

/**
 * Register the routes.
 *
 * @return void
 */
function register_routes(): void {
	$id_arg = array(
		'type'     => 'integer',
		'required' => true,
	);

	register_rest_route(
		NAMESPACE_V1,
		'/folders',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\get_tree',
				'permission_callback' => __NAMESPACE__ . '\\can_manage',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\create_folder',
				'permission_callback' => __NAMESPACE__ . '\\can_manage',
				'args'                => array(
					'name'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'parent' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/folders/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => __NAMESPACE__ . '\\update_folder',
				'permission_callback' => __NAMESPACE__ . '\\can_manage',
				'args'                => array(
					'id'     => $id_arg,
					'name'   => array( 'type' => 'string' ),
					'parent' => array( 'type' => 'integer' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => __NAMESPACE__ . '\\delete_folder',
				'permission_callback' => __NAMESPACE__ . '\\can_manage',
				'args'                => array(
					'id'     => $id_arg,
					'files'  => array(
						'type'    => 'string',
						'enum'    => array( 'move', 'delete' ),
						'default' => 'move',
					),
					'target' => array(
						'description' => 'Folder that receives the files; -1 for the parent, 0 for none.',
						'type'        => 'integer',
						'default'     => -1,
					),
				),
			),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/folders/restore',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\restore_folder',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array(
				'snapshot' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/items',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\file_items',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array(
				'ids'    => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
					'required' => true,
				),
				'folder' => array(
					'type'    => 'integer',
					'default' => 0,
				),
				'mode'   => array(
					'type'    => 'string',
					'enum'    => array( 'move', 'add', 'remove' ),
					'default' => 'move',
				),
				'from'   => array(
					'type'    => 'integer',
					'default' => 0,
				),
				'nested' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/items/set',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\set_items',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array(
				'map' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/preferences',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\save_preferences',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array(
				'nested' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			),
		)
	);
}

/**
 * GET /folders
 *
 * @return WP_REST_Response
 */
function get_tree(): WP_REST_Response {
	return new WP_REST_Response( Folders\tree() );
}

/**
 * Clean a folder name.
 *
 * @param mixed $name Raw name.
 * @return string
 */
function clean_name( $name ): string {
	return mb_substr( trim( sanitize_text_field( (string) $name ) ), 0, 200 );
}

/**
 * POST /folders
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function create_folder( WP_REST_Request $request ) {
	$name   = clean_name( $request['name'] );
	$parent = (int) $request['parent'];

	if ( '' === $name ) {
		return new WP_Error( 'imd_empty_name', __( 'Folder name cannot be empty.', 'isudev-media-directory' ), array( 'status' => 400 ) );
	}

	if ( $parent && ! Folders\exists( $parent ) ) {
		return new WP_Error( 'imd_no_parent', __( 'The parent folder no longer exists.', 'isudev-media-directory' ), array( 'status' => 404 ) );
	}

	$id = Folders\create( $name, $parent );

	return new WP_REST_Response(
		array(
			'id'   => $id,
			'tree' => Folders\tree(),
		),
		201
	);
}

/**
 * POST /folders/{id} — rename or move.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function update_folder( WP_REST_Request $request ) {
	$id = (int) $request['id'];

	if ( ! Folders\exists( $id ) ) {
		return new WP_Error( 'imd_not_found', __( 'Folder not found.', 'isudev-media-directory' ), array( 'status' => 404 ) );
	}

	$folder   = Folders\all()[ $id ];
	$fields   = array();
	$previous = array(
		'name'   => $folder['name'],
		'parent' => $folder['parent'],
	);

	if ( null !== $request['name'] ) {
		$fields['name'] = clean_name( $request['name'] );

		if ( '' === $fields['name'] ) {
			return new WP_Error( 'imd_empty_name', __( 'Folder name cannot be empty.', 'isudev-media-directory' ), array( 'status' => 400 ) );
		}
	}

	if ( null !== $request['parent'] ) {
		$parent = (int) $request['parent'];

		if ( $parent && ( ! Folders\exists( $parent ) || in_array( $parent, Folders\subtree( $id ), true ) ) ) {
			return new WP_Error( 'imd_bad_parent', __( 'A folder cannot be moved into itself.', 'isudev-media-directory' ), array( 'status' => 400 ) );
		}

		$fields['parent'] = $parent;
	}

	Folders\update( $id, $fields );

	return new WP_REST_Response(
		array(
			'previous' => $previous,
			'tree'     => Folders\tree(),
		)
	);
}

/**
 * DELETE /folders/{id}
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function delete_folder( WP_REST_Request $request ) {
	$id = (int) $request['id'];

	if ( ! Folders\exists( $id ) ) {
		return new WP_Error( 'imd_not_found', __( 'Folder not found.', 'isudev-media-directory' ), array( 'status' => 404 ) );
	}

	$subtree     = Folders\subtree( $id );
	$attachments = Folders\attachments_in( $subtree );
	$target      = (int) $request['target'];

	if ( -1 === $target ) {
		$target = Folders\all()[ $id ]['parent'];
	}

	if ( $target && ( ! Folders\exists( $target ) || in_array( $target, $subtree, true ) ) ) {
		return new WP_Error( 'imd_bad_target', __( 'Choose a folder outside the one being deleted.', 'isudev-media-directory' ), array( 'status' => 400 ) );
	}

	if ( 'delete' === $request['files'] ) {
		foreach ( $attachments as $attachment ) {
			if ( ! current_user_can( 'delete_post', $attachment ) ) {
				return new WP_Error( 'imd_cannot_delete', __( 'You are not allowed to delete some of these files.', 'isudev-media-directory' ), array( 'status' => 403 ) );
			}
		}
	}

	$previous = Folders\of( $attachments );
	$snapshot = Folders\delete( $id );
	$deleted  = array();

	if ( 'delete' === $request['files'] ) {
		foreach ( $attachments as $attachment ) {
			if ( wp_delete_attachment( $attachment, true ) ) {
				$deleted[] = $attachment;
			}
		}
	} elseif ( $target ) {
		Folders\insert_items(
			array_map(
				static function ( $attachment ) use ( $target ) {
					return array( $target, $attachment );
				},
				$attachments
			)
		);
	}

	return new WP_REST_Response(
		array(
			'snapshot' => $snapshot,
			'previous' => $previous,
			'target'   => $target,
			'moved'    => 'delete' === $request['files'] ? 0 : count( $attachments ),
			'deleted'  => $deleted,
			'tree'     => Folders\tree(),
		)
	);
}

/**
 * POST /folders/restore — undo a delete.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function restore_folder( WP_REST_Request $request ): WP_REST_Response {
	Folders\restore( (array) $request['snapshot'] );

	return new WP_REST_Response( array( 'tree' => Folders\tree() ) );
}

/**
 * Attachment IDs the current user may file.
 *
 * @param mixed $ids Raw IDs.
 * @return int[]
 */
function editable_attachments( $ids ): array {
	return array_values(
		array_filter(
			array_unique( array_map( 'absint', (array) $ids ) ),
			static function ( $id ) {
				return $id && 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
			}
		)
	);
}

/**
 * POST /items — move, add or remove files.
 *
 * `move` takes the files out of what the user is looking at (`from`, with
 * its subfolders when `nested`) and puts them in `folder`; folders outside
 * that view are kept. From "All files" or "Unfiled" it replaces every folder.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function file_items( WP_REST_Request $request ) {
	$ids    = editable_attachments( $request['ids'] );
	$folder = (int) $request['folder'];
	$mode   = (string) $request['mode'];
	$from   = (int) $request['from'];

	if ( ! $ids ) {
		return new WP_Error( 'imd_no_files', __( 'No files you can edit were selected.', 'isudev-media-directory' ), array( 'status' => 400 ) );
	}

	if ( 'remove' !== $mode && ! Folders\exists( $folder ) ) {
		return new WP_Error( 'imd_not_found', __( 'Folder not found.', 'isudev-media-directory' ), array( 'status' => 404 ) );
	}

	$previous = Folders\of( $ids );
	$scope    = $from && Folders\exists( $from ) ? Folders\scope( $from, (bool) $request['nested'] ) : null;
	$next     = array();

	foreach ( $previous as $id => $current ) {
		if ( 'add' === $mode ) {
			$next[ $id ] = array_merge( $current, array( $folder ) );
			continue;
		}

		$kept = null === $scope ? array() : array_diff( $current, $scope );

		$next[ $id ] = 'remove' === $mode ? $kept : array_merge( $kept, array( $folder ) );
	}

	Folders\set( $next );

	return new WP_REST_Response(
		array(
			'previous' => $previous,
			'current'  => Folders\of( $ids ),
			'tree'     => Folders\tree(),
		)
	);
}

/**
 * POST /items/set — put files back into exact folders (undo).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function set_items( WP_REST_Request $request ): WP_REST_Response {
	$map     = (array) $request['map'];
	$allowed = editable_attachments( array_keys( $map ) );
	$clean   = array();

	foreach ( $allowed as $id ) {
		$clean[ $id ] = array_map( 'absint', (array) $map[ $id ] );
	}

	Folders\set( $clean );

	return new WP_REST_Response(
		array(
			'current' => Folders\of( $allowed ),
			'tree'    => Folders\tree(),
		)
	);
}

/**
 * POST /preferences
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function save_preferences( WP_REST_Request $request ): WP_REST_Response {
	update_user_meta( get_current_user_id(), Settings\USER_NESTED_META, $request['nested'] ? '1' : '0' );

	return new WP_REST_Response( array( 'nested' => (bool) $request['nested'] ) );
}
