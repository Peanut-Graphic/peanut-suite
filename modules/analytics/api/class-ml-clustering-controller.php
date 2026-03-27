<?php
/**
 * ML Conversion Path Clustering REST API Controller
 *
 * @package Peanut_Suite
 * @subpackage Analytics
 */

namespace PeanutSuite\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints for ML conversion path clustering.
 */
class ML_Clustering_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected string $namespace = 'peanut/v1';

	/**
	 * Clustering service instance.
	 *
	 * @var \Peanut_ML_Conversion_Clustering|null
	 */
	private ?\Peanut_ML_Conversion_Clustering $clustering = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->clustering = new \Peanut_ML_Conversion_Clustering();
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		// Get conversion path clusters
		register_rest_route(
			$this->namespace,
			'/analytics/conversion-paths',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_clusters' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'date_from'    => array(
						'required' => false,
						'type'     => 'string',
						'description' => 'Start date (YYYY-MM-DD)',
					),
					'date_to'      => array(
						'required' => false,
						'type'     => 'string',
						'description' => 'End date (YYYY-MM-DD)',
					),
					'source'       => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'suite',
						'enum'        => array( 'suite', 'formflow', 'both' ),
						'description' => 'Data source',
					),
					'max_clusters' => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => 'Maximum clusters to test',
					),
				),
			)
		);

		// Get individual visitor segment assignment
		register_rest_route(
			$this->namespace,
			'/analytics/visitor-segment/(?P<visitor_id>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_visitor_segment' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'visitor_id' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => 'Visitor identifier',
					),
					'source'     => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'suite',
						'enum'        => array( 'suite', 'formflow' ),
						'description' => 'Data source',
					),
				),
			)
		);

		// Train clustering model
		register_rest_route(
			$this->namespace,
			'/analytics/conversion-paths/train',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'train_model' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'source'    => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'suite',
						'enum'        => array( 'suite', 'formflow', 'both' ),
						'description' => 'Data source',
					),
					'date_from' => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Start date (YYYY-MM-DD)',
					),
					'date_to'   => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'End date (YYYY-MM-DD)',
					),
				),
			)
		);

		// Get clustering model stats
		register_rest_route(
			$this->namespace,
			'/analytics/conversion-paths/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get conversion path clusters.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_clusters( \WP_REST_Request $request ) {
		$date_from    = $request->get_param( 'date_from' ) ?? '';
		$date_to      = $request->get_param( 'date_to' ) ?? '';
		$source       = $request->get_param( 'source' ) ?? 'suite';
		$max_clusters = $request->get_param( 'max_clusters' );

		try {
			$result = $this->clustering->get_clusters( $date_from, $date_to, $source, $max_clusters );

			if ( ! $result ) {
				return new \WP_Error(
					'ml_service_error',
					'Failed to get conversion path clusters',
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'ml_service_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get visitor's cluster assignment.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_visitor_segment( \WP_REST_Request $request ) {
		$visitor_id = $request->get_param( 'visitor_id' );
		$source     = $request->get_param( 'source' ) ?? 'suite';

		if ( empty( $visitor_id ) ) {
			return new \WP_Error(
				'invalid_visitor_id',
				'Visitor ID is required',
				array( 'status' => 400 )
			);
		}

		try {
			$result = $this->clustering->get_visitor_segment( $visitor_id, $source );

			if ( ! $result ) {
				return new \WP_Error(
					'visitor_not_found',
					"Visitor {$visitor_id} not found",
					array( 'status' => 404 )
				);
			}

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'ml_service_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Train clustering model.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function train_model( \WP_REST_Request $request ) {
		$source    = $request->get_param( 'source' ) ?? 'suite';
		$date_from = $request->get_param( 'date_from' ) ?? '';
		$date_to   = $request->get_param( 'date_to' ) ?? '';

		try {
			$result = $this->clustering->train_model( $source, $date_from, $date_to );

			if ( ! $result ) {
				return new \WP_Error(
					'ml_service_error',
					'Failed to train clustering model',
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'ml_service_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get clustering model statistics.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_stats( \WP_REST_Request $request ) {
		try {
			$result = $this->clustering->get_stats();

			if ( ! $result ) {
				return new \WP_Error(
					'ml_service_error',
					'Failed to get clustering model statistics',
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'ml_service_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Check permission for API access.
	 *
	 * @return bool True if user has permission, false otherwise.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Check permission for admin-only operations.
	 *
	 * @return bool True if user is admin, false otherwise.
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}
}
