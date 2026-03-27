<?php
/**
 * ML Lead Scoring REST API Controller
 *
 * @package Peanut_Suite
 * @subpackage Contacts
 */

namespace PeanutSuite\Contacts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints for ML lead scoring.
 */
class ML_Lead_Scoring_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected string $namespace = 'peanut/v1';

	/**
	 * Lead scoring service instance.
	 *
	 * @var \Peanut_ML_Lead_Scoring|null
	 */
	private ?\Peanut_ML_Lead_Scoring $scorer = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->scorer = new \Peanut_ML_Lead_Scoring();
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		// Score a single contact
		register_rest_route(
			$this->namespace,
			'/contacts/lead-score',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'score_lead' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'email'   => array(
						'type' => 'string',
					),
					'name'    => array(
						'type' => 'string',
					),
					'company' => array(
						'type' => 'string',
					),
				),
			)
		);

		// Batch score multiple contacts
		register_rest_route(
			$this->namespace,
			'/contacts/lead-scores',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'batch_score_leads' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'contacts' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		// Get top-scoring leads
		register_rest_route(
			$this->namespace,
			'/contacts/top-leads',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_top_leads' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			)
		);

		// Train lead scoring model (admin only)
		register_rest_route(
			$this->namespace,
			'/contacts/lead-scores/train',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'train_model' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Check lead scoring service health
		register_rest_route(
			$this->namespace,
			'/contacts/lead-scores/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_service_health' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Score a single lead.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function score_lead( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_data = array();

		// Extract contact data from request
		foreach ( array( 'email', 'name', 'company', 'phone', 'location', 'industry' ) as $field ) {
			$value = $request->get_param( $field );
			if ( ! empty( $value ) ) {
				$contact_data[ $field ] = sanitize_text_field( $value );
			}
		}

		if ( empty( $contact_data ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'No contact data provided',
					'message' => 'At least one contact field is required.',
				),
				400
			);
		}

		$score = $this->scorer->score_lead( $contact_data );

		if ( null === $score ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Unable to score lead',
					'message' => 'The ML service is unavailable or returned invalid data.',
				),
				500
			);
		}

		return new \WP_REST_Response( $score, 200 );
	}

	/**
	 * Batch score multiple leads.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function batch_score_leads( \WP_REST_Request $request ): \WP_REST_Response {
		$contacts = $request->get_param( 'contacts' );

		if ( ! is_array( $contacts ) || empty( $contacts ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Invalid contacts array',
					'message' => 'contacts parameter must be a non-empty array.',
				),
				400
			);
		}

		// Sanitize contact data
		$sanitized_contacts = array();
		foreach ( $contacts as $contact ) {
			if ( ! is_array( $contact ) ) {
				continue;
			}

			$sanitized_contact = array();
			foreach ( array( 'email', 'name', 'company', 'phone', 'location', 'industry' ) as $field ) {
				if ( isset( $contact[ $field ] ) && ! empty( $contact[ $field ] ) ) {
					$sanitized_contact[ $field ] = sanitize_text_field( $contact[ $field ] );
				}
			}

			if ( ! empty( $sanitized_contact ) ) {
				$sanitized_contacts[] = $sanitized_contact;
			}
		}

		if ( empty( $sanitized_contacts ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'No valid contact data',
					'message' => 'None of the provided contacts contained valid data.',
				),
				400
			);
		}

		$scores = $this->scorer->batch_score( $sanitized_contacts );

		if ( null === $scores ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Batch scoring failed',
					'message' => 'The ML service is unavailable or returned invalid data.',
				),
				500
			);
		}

		return new \WP_REST_Response( $scores, 200 );
	}

	/**
	 * Get top-scoring leads.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_top_leads( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? $limit : 20;

		$leads = $this->scorer->get_top_leads( $limit );

		if ( null === $leads ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'Unable to fetch leads',
					'message' => 'The ML service is unavailable or returned invalid data.',
				),
				500
			);
		}

		return new \WP_REST_Response( $leads, 200 );
	}

	/**
	 * Train the lead scoring model.
	 *
	 * @return \WP_REST_Response
	 */
	public function train_model(): \WP_REST_Response {
		$result = $this->scorer->train_model();

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
		$available = $this->scorer->is_available();

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
