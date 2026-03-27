<?php
/**
 * ML Lead Scoring Integration
 *
 * Integrates the lead scoring ML module (logistic regression) into
 * Peanut Suite's contacts module. Provides lead quality prediction and
 * scoring based on contact attributes and behavior.
 *
 * @package Peanut_Suite
 * @subpackage Contacts
 * @since 4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lead Scoring ML Integration
 *
 * @class Peanut_ML_Lead_Scoring
 */
class Peanut_ML_Lead_Scoring {

	/**
	 * ML service base URL.
	 *
	 * @var string|null
	 */
	private $ml_url = null;

	/**
	 * ML service API key.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Cache TTL in seconds (15 minutes).
	 *
	 * @var int
	 */
	private $cache_ttl = 900;

	/**
	 * Batch cache TTL in seconds (5 minutes).
	 *
	 * @var int
	 */
	private $batch_cache_ttl = 300;

	/**
	 * Initialize the lead scoring service.
	 */
	public function __construct() {
		$this->init_service();
	}

	/**
	 * Initialize ML service settings from WordPress options.
	 *
	 * @return void
	 */
	private function init_service(): void {
		$settings = get_option( 'peanut_ml_settings', array() );

		$this->ml_url = isset( $settings['service_url'] )
			? trailingslashit( $settings['service_url'] )
			: 'http://127.0.0.1:8100/';

		$this->api_key = isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';
	}

	/**
	 * Check if the ML lead scoring service is reachable.
	 *
	 * @return bool True if service is available, false otherwise.
	 */
	public function is_available(): bool {
		$cached = get_transient( 'peanut_ml_lead_scoring_available' );
		if ( $cached !== false ) {
			return $cached === 'yes';
		}

		$response = wp_remote_get(
			$this->ml_url . 'health',
			array(
				'timeout'   => 3,
				'sslverify' => false,
			)
		);

		$available = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
		set_transient( 'peanut_ml_lead_scoring_available', $available ? 'yes' : 'no', 120 );

		return $available;
	}

	/**
	 * Score a single lead/contact.
	 *
	 * Returns a score from 0-100 with explanation and key factors.
	 *
	 * @param array $contact_data Contact attributes to score.
	 *                            Expected keys: email, name, company, etc.
	 *
	 * @return array|null Array with 'score', 'explanation', 'factors' or null on failure.
	 */
	public function score_lead( array $contact_data ): ?array {
		if ( empty( $contact_data ) ) {
			return null;
		}

		$cache_key = 'peanut_ml_lead_score_' . md5( wp_json_encode( $contact_data ) );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$response = $this->make_request( 'POST', 'leads/score', $contact_data );

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, $this->cache_ttl );

		return $response;
	}

	/**
	 * Score multiple leads in batch.
	 *
	 * More efficient than calling score_lead() multiple times.
	 *
	 * @param array $contacts Array of contact data arrays to score.
	 *
	 * @return array|null Array of scores or null on failure.
	 */
	public function batch_score( array $contacts ): ?array {
		if ( empty( $contacts ) ) {
			return null;
		}

		$cache_key = 'peanut_ml_batch_scores_' . md5( wp_json_encode( $contacts ) );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$response = $this->make_request( 'POST', 'leads/batch-score', array( 'contacts' => $contacts ) );

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, $this->batch_cache_ttl );

		return $response;
	}

	/**
	 * Get the top-scoring leads.
	 *
	 * Useful for identifying highest-quality prospects.
	 *
	 * @param int $limit Maximum number of leads to return. Default 20.
	 *
	 * @return array|null Array of top leads or null on failure.
	 */
	public function get_top_leads( int $limit = 20 ): ?array {
		if ( $limit < 1 ) {
			$limit = 20;
		}

		$cache_key = 'peanut_ml_top_leads_' . intval( $limit );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$response = $this->make_request(
			'GET',
			'leads/top?limit=' . intval( $limit )
		);

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, $this->cache_ttl );

		return $response;
	}

	/**
	 * Train the lead scoring model.
	 *
	 * Triggers model retraining on the ML service. Call this from a cron event.
	 *
	 * @return array|null Training result or null on failure.
	 */
	public function train_model(): ?array {
		$response = $this->make_request( 'POST', 'leads/train', array() );

		if ( $response ) {
			// Clear top leads cache after training
			delete_transient( 'peanut_ml_top_leads_20' );
		}

		return $response;
	}

	/**
	 * Make an HTTP request to the ML service.
	 *
	 * Shared helper for all HTTP communication with the ML microservice.
	 * Handles authentication via X-ML-API-Key header and proper error handling.
	 *
	 * @param string $method   HTTP method (GET, POST).
	 * @param string $endpoint API endpoint (without base URL).
	 * @param array  $data     Request body data (for POST requests).
	 *
	 * @return array|null Decoded response body or null on error.
	 */
	private function make_request( string $method, string $endpoint, array $data = array() ): ?array {
		$url     = $this->ml_url . $endpoint;
		$headers = array(
			'Content-Type'   => 'application/json',
			'X-ML-API-Key'   => $this->api_key,
		);

		$args = array(
			'timeout'   => 30,
			'sslverify' => false,
			'headers'   => $headers,
		);

		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $data );
			$response     = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			error_log( 'Peanut ML Lead Scoring Error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			error_log( "Peanut ML Lead Scoring Error: HTTP {$code} - {$body}" );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			error_log( 'Peanut ML Lead Scoring Error: Invalid JSON response' );
			return null;
		}

		return $body;
	}

	/**
	 * Schedule weekly model training.
	 *
	 * @return void
	 */
	public static function schedule_training(): void {
		if ( ! wp_next_scheduled( 'peanut_ml_lead_scoring_train' ) ) {
			wp_schedule_event( time(), 'weekly', 'peanut_ml_lead_scoring_train' );
		}
	}

	/**
	 * Unschedule model training.
	 *
	 * @return void
	 */
	public static function unschedule_training(): void {
		$timestamp = wp_next_scheduled( 'peanut_ml_lead_scoring_train' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'peanut_ml_lead_scoring_train' );
		}
	}
}
