<?php
/**
 * REST API, namespace teatatu-data/v1. Nothing is public: every route needs
 * an authenticated user with read_teatatu_data, and writes need the
 * narrowest capability that fits (SPEC.md §8, §9).
 *
 * Reads work from any site (they go through the Master Site). Writes must
 * be sent to the Master Site's own REST URL, because roles are per site.
 *
 * GET    /dictionary
 * GET    /datasets/<key>/records                 search, status, page, per_page
 * POST   /datasets/<key>/records                 {values, reason}           edit_teatatu_data
 * GET    /datasets/<key>/records/<uuid>
 * PATCH  /datasets/<key>/records/<uuid>          {values, reason}           edit_teatatu_data
 * GET    /datasets/<key>/records/<uuid>/history
 * POST   /datasets/<key>/proposals               {uuid?, values, reason}    propose_teatatu_data
 * GET    /proposals                              state, dataset, batch, page, per_page
 * GET    /proposals/<id>
 * POST   /proposals/<id>/approve                 {note}                     approve_teatatu_data
 * POST   /proposals/<id>/reject                  {note}                     approve_teatatu_data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'teatatu_data_register_rest_routes' );

/**
 * Registers the routes.
 */
function teatatu_data_register_rest_routes() {
	$ns       = 'teatatu-data/v1';
	$dataset  = '/datasets/(?P<dataset>[a-z][a-z0-9_]*)';
	$uuid     = '(?P<uuid>[0-9a-fA-F-]{36})';
	$can      = function ( $cap ) {
		return function () use ( $cap ) {
			return current_user_can( $cap );
		};
	};
	$body_args = array(
		'values' => array(
			'type'     => 'object',
			'required' => true,
		),
		'reason' => array(
			'type'    => 'string',
			'default' => '',
		),
	);

	register_rest_route(
		$ns,
		'/dictionary',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_data_rest_dictionary',
			'permission_callback' => $can( 'read_teatatu_data' ),
		)
	);

	register_rest_route(
		$ns,
		$dataset . '/records',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'teatatu_data_rest_list_records',
				'permission_callback' => $can( 'read_teatatu_data' ),
				'args'                => array(
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'   => array(
						'type'    => 'string',
						'default' => 'active',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'teatatu_data_rest_create_record',
				'permission_callback' => $can( 'edit_teatatu_data' ),
				'args'                => $body_args,
			),
		)
	);

	register_rest_route(
		$ns,
		$dataset . '/records/' . $uuid,
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'teatatu_data_rest_get_record',
				'permission_callback' => $can( 'read_teatatu_data' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'teatatu_data_rest_update_record',
				'permission_callback' => $can( 'edit_teatatu_data' ),
				'args'                => $body_args,
			),
		)
	);

	register_rest_route(
		$ns,
		$dataset . '/records/' . $uuid . '/history',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_data_rest_record_history',
			'permission_callback' => $can( 'read_teatatu_data' ),
		)
	);

	register_rest_route(
		$ns,
		$dataset . '/proposals',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'teatatu_data_rest_propose',
			'permission_callback' => $can( 'propose_teatatu_data' ),
			'args'                => array_merge(
				$body_args,
				array(
					'uuid' => array(
						'type'    => 'string',
						'default' => '',
					),
				)
			),
		)
	);

	register_rest_route(
		$ns,
		'/proposals',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_data_rest_list_proposals',
			'permission_callback' => $can( 'propose_teatatu_data' ),
			'args'                => array(
				'state'    => array(
					'type'    => 'string',
					'default' => 'pending',
					'enum'    => array( 'pending', 'applied', 'rejected', 'all' ),
				),
				'dataset'  => array(
					'type'    => 'string',
					'default' => '',
				),
				'batch'    => array(
					'type'    => 'string',
					'default' => '',
				),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 50,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
		)
	);

	register_rest_route(
		$ns,
		'/proposals/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_data_rest_get_proposal',
			'permission_callback' => $can( 'propose_teatatu_data' ),
		)
	);

	foreach ( array( 'approve', 'reject' ) as $decision ) {
		register_rest_route(
			$ns,
			'/proposals/(?P<id>\d+)/' . $decision,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'teatatu_data_rest_' . $decision,
				'permission_callback' => $can( 'approve_teatatu_data' ),
				'args'                => array(
					'note' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}
}

/**
 * The data set named in the route, or an error.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function teatatu_data_rest_dataset( $request ) {
	$dataset = teatatu_data_get_dataset( $request['dataset'] );
	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		return new WP_Error( 'teatatu_data_unknown_dataset', __( 'Unknown data set.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	return $dataset;
}

/**
 * Writes must be made on the Master Site, where the user's role applies.
 *
 * @return true|WP_Error
 */
function teatatu_data_rest_require_master() {
	if ( teatatu_data_is_master_site() ) {
		return true;
	}
	return new WP_Error(
		'teatatu_data_not_master',
		__( 'Changes must be sent to the Master Site.', 'teatatu-data' ),
		array(
			'status'     => 409,
			'master_url' => get_rest_url( teatatu_data_get_master_site_id(), 'teatatu-data/v1/' ),
		)
	);
}

/**
 * Whether the current user may see every proposal (stewards) or only
 * their own (agents).
 *
 * @return bool
 */
function teatatu_data_rest_sees_all_proposals() {
	return current_user_can( 'approve_teatatu_data' );
}

/**
 * GET /dictionary.
 *
 * @return WP_REST_Response
 */
function teatatu_data_rest_dictionary() {
	return rest_ensure_response( teatatu_data_on_master( 'teatatu_data_get_dictionary' ) );
}

/**
 * GET /datasets/<key>/records.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_list_records( $request ) {
	$dataset = teatatu_data_rest_dataset( $request );
	if ( is_wp_error( $dataset ) ) {
		return $dataset;
	}
	$status = 'all' === $request['status'] ? 'all' : array_map( 'trim', explode( ',', (string) $request['status'] ) );
	$result = teatatu_data_find(
		$dataset['key'],
		array(
			'search'   => (string) $request['search'],
			'status'   => $status,
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		)
	);
	$response = rest_ensure_response( $result['records'] );
	$response->header( 'X-WP-Total', (string) $result['total'] );
	$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, (int) $request['per_page'] ) ) );
	return $response;
}

/**
 * GET /datasets/<key>/records/<uuid>.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_get_record( $request ) {
	$dataset = teatatu_data_rest_dataset( $request );
	if ( is_wp_error( $dataset ) ) {
		return $dataset;
	}
	$record = teatatu_data_get( $dataset['key'], strtolower( $request['uuid'] ) );
	if ( ! $record ) {
		return new WP_Error( 'teatatu_data_not_found', __( 'No such record.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( $record );
}

/**
 * POST /datasets/<key>/records — a steward's direct create.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_create_record( $request ) {
	return teatatu_data_rest_write( $request, null );
}

/**
 * PATCH /datasets/<key>/records/<uuid> — a steward's direct update.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_update_record( $request ) {
	return teatatu_data_rest_write( $request, strtolower( $request['uuid'] ) );
}

/**
 * Shared create/update handler.
 *
 * @param WP_REST_Request $request Request.
 * @param string|null     $uuid    Record UUID, or null to create.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_write( $request, $uuid ) {
	$dataset = teatatu_data_rest_dataset( $request );
	if ( is_wp_error( $dataset ) ) {
		return $dataset;
	}
	$master = teatatu_data_rest_require_master();
	if ( is_wp_error( $master ) ) {
		return $master;
	}
	$result = teatatu_data_apply(
		$dataset['key'],
		$uuid,
		(array) $request['values'],
		array(
			'channel' => 'rest',
			'reason'  => (string) $request['reason'],
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$response = rest_ensure_response(
		array(
			'changed'   => $result['changed'],
			'change_id' => $result['change_id'],
			'record'    => teatatu_data_get( $dataset['key'], $result['uuid'] ),
		)
	);
	if ( 'create' === $result['operation'] && $result['changed'] ) {
		$response->set_status( 201 );
	}
	return $response;
}

/**
 * GET /datasets/<key>/records/<uuid>/history.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_record_history( $request ) {
	$dataset = teatatu_data_rest_dataset( $request );
	if ( is_wp_error( $dataset ) ) {
		return $dataset;
	}
	$result = teatatu_data_get_changes(
		array(
			'dataset'  => $dataset['key'],
			'record'   => strtolower( $request['uuid'] ),
			'per_page' => 100,
		)
	);
	return rest_ensure_response( $result['changes'] );
}

/**
 * POST /datasets/<key>/proposals.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_propose( $request ) {
	$dataset = teatatu_data_rest_dataset( $request );
	if ( is_wp_error( $dataset ) ) {
		return $dataset;
	}
	$master = teatatu_data_rest_require_master();
	if ( is_wp_error( $master ) ) {
		return $master;
	}
	$uuid = (string) $request['uuid'];
	if ( '' !== $uuid && ! teatatu_data_is_uuid( $uuid ) ) {
		return new WP_Error( 'teatatu_data_invalid', __( 'uuid must be a record UUID.', 'teatatu-data' ), array( 'status' => 400 ) );
	}
	$change_id = teatatu_data_propose(
		$dataset['key'],
		'' === $uuid ? null : strtolower( $uuid ),
		(array) $request['values'],
		array(
			'channel' => 'rest',
			'reason'  => (string) $request['reason'],
		)
	);
	if ( is_wp_error( $change_id ) ) {
		return $change_id;
	}
	$response = rest_ensure_response( teatatu_data_get_change( $change_id ) );
	$response->set_status( 201 );
	return $response;
}

/**
 * GET /proposals.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function teatatu_data_rest_list_proposals( $request ) {
	$result = teatatu_data_get_changes(
		array(
			'state'    => 'all' === $request['state'] ? '' : $request['state'],
			'dataset'  => (string) $request['dataset'],
			'batch'    => (string) $request['batch'],
			'author'   => teatatu_data_rest_sees_all_proposals() ? 0 : get_current_user_id(),
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		)
	);
	$response = rest_ensure_response( $result['changes'] );
	$response->header( 'X-WP-Total', (string) $result['total'] );
	return $response;
}

/**
 * GET /proposals/<id>.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_get_proposal( $request ) {
	$change = teatatu_data_get_change( (int) $request['id'] );
	if ( ! $change || ( ! teatatu_data_rest_sees_all_proposals() && get_current_user_id() !== $change['author_id'] ) ) {
		return new WP_Error( 'teatatu_data_not_found', __( 'No such proposal.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( $change );
}

/**
 * POST /proposals/<id>/approve.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_approve( $request ) {
	$master = teatatu_data_rest_require_master();
	if ( is_wp_error( $master ) ) {
		return $master;
	}
	$result = teatatu_data_approve_change( (int) $request['id'], (string) $request['note'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( teatatu_data_get_change( (int) $request['id'] ) );
}

/**
 * POST /proposals/<id>/reject.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_data_rest_reject( $request ) {
	$master = teatatu_data_rest_require_master();
	if ( is_wp_error( $master ) ) {
		return $master;
	}
	$result = teatatu_data_reject_change( (int) $request['id'], (string) $request['note'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( teatatu_data_get_change( (int) $request['id'] ) );
}
