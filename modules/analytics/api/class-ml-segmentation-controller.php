<?php
/**
 * ML Visitor Segmentation REST API Controller
 *
 * @package Peanut_Suite
 * @subpackage Analytics
 */

namespace PeanutSuite\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints for ML visitor segmentation.
 */
class ML_Segmentation_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected string $namespace = 'peanut/v1';

	/**
	 * Segmentation service instance.
	 *
	 * @var \Peanut_ML_Visitor_Segmentation|null
	 */
	private ?\Peanut_ML_Visitor_Segmentation $segmentation = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->segmentation = new \Peanut_ML_Visitor_Segmentation();
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		// Get visitor segments
		register_rest_route(
			$this->namespace,
			'/analytics/segments',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_segments' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get individual visitor profile
		register_rest_route(
			$this->namespace,
			'/analytics/visitor-profile/(?P<visitor_id>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_visitor_profile' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'visitor_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// Get segmentation stats
		register_rest_route(
			$this->namespace,
			'/analytics/segmentation-stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_segmentation_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Train segmentation model (admin only)
		register_rest_route(
			$this->namespace,
			'/analytics/segments/train',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'train_model' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Check segmentation service health
		register_rest_route(
			$this->namespace,
			'/analytics/segments/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_service_health' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get visitor segments.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_segments(): \WP_REST_Response {
		$segments = $this->segmentation->segment_visitors();

		if ( null === $segments ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Unable to fetch segments',
					'message' => 'The ML service is unavailable or returned invalid data.',
				),
				500
			);
		}

		return new \WP_REST_Response( $segments, 200 );
	}

	/**
	 * Get visitor profile for a specific visitor ID.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_visitor_profile( \WP_REST_Request $request ): \WP_REST_Response {
		$visitor_id = sanitize_text_field( $request->get_param( 'visitor_id' ) );

		if ( empty( $visitor_id ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Missing visitor_id',
					'message' => 'visitor_id parameter is required.',
				),
				400
			);
		}

		$profile = $this->segmentation->get_visitor_profile( $visitor_id );

		if ( null === $profile ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Unable to fetch profile',
					'message' => 'The ML service is unavailable or the visitor was not found.',
				),
				404
			);
		}

		return new \WP_REST_Response( $profile, 200 );
	}

	/**
	 * Get segmentation model statistics.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_segmentation_stats(): \WP_REST_Response {
		$stats = $this->segmentation->get_stats();

		if ( null === $stats ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Unable to fetch stats',
					'message' => 'The ML service is unavailable.',
				),
				500
			);
		}

		return new \WP_REST_Response( $stats, 200 );
	}

	/**
	 * Train the segmentation model.
	 *
	 * @return \WP_REST_Response
	 */
	public function train_model(): \WP_REST_Response {
		$result = $this->segmentation->train_model();

		if ( null === $result ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Training failed',
					'message' => 'The ML service encountered an error during training.',
				),
				500
			);
		}

		return new \WP_REST_Response(
			array(
				'status'  => 'success',
				'message' => 'Model training started.',
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Check service health.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_service_health(): \WP_REST_Response {
		$available = $this->segmentation->is_available();

		return new \WP_REST_Response(
			array(
				'available' => $available,
				'status'    => $available ? 'healthy' : 'unavailable',
			),
			$available ? 200 : 503
		);
	}

	/**
	 * Check if user has permission to access the endpoint.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Check if user is admin.
	 *
	 * @return bool
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}
}
