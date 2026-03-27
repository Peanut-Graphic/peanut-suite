<?php
/**
 * ML Visitor Segmentation Integration
 *
 * Integrates the visitor segmentation ML module (K-means clustering) into
 * Peanut Suite's analytics module. Provides visitor segmentation based on
 * behavioral data and clustering ML models.
 *
 * @package Peanut_Suite
 * @subpackage Analytics
 * @since 4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visitor Segmentation ML Integration
 *
 * @class Peanut_ML_Visitor_Segmentation
 */
class Peanut_ML_Visitor_Segmentation {

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
	 * Initialize the segmentation service.
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
	 * Check if the ML segmentation service is reachable.
	 *
	 * @return bool True if service is available, false otherwise.
	 */
	public function is_available(): bool {
		$cached = get_transient( 'peanut_ml_segmentation_available' );
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
		set_transient( 'peanut_ml_segmentation_available', $available ? 'yes' : 'no', 120 );

		return $available;
	}

	/**
	 * Segment visitors using K-means clustering.
	 *
	 * Calls the ML service to cluster visitors based on their behavioral data.
	 *
	 * @return array|null Array of visitor segments or null on failure.
	 */
	public function segment_visitors(): ?array {
		$cache_key = 'peanut_ml_visitor_segments';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$response = $this->make_request( 'POST', 'segmentation/segment', array() );

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, $this->cache_ttl );

		return $response;
	}

	/**
	 * Get the visitor profile for a specific visitor ID.
	 *
	 * @param string $visitor_id Visitor identifier.
	 *
	 * @return array|null Visitor profile data or null on failure.
	 */
	public function get_visitor_profile( string $visitor_id ): ?array {
		if ( empty( $visitor_id ) ) {
			return null;
		}

		$cache_key = 'peanut_ml_visitor_profile_' . md5( $visitor_id );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$response = $this->make_request( 'GET', 'segmentation/visitor-profile/' . urlencode( $visitor_id ) );

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, $this->cache_ttl );

		return $response;
	}

	/**
	 * Train the segmentation model.
	 *
	 * Triggers model retraining on the ML service. Call this from a cron event.
	 *
	 * @return array|null Training result or null on failure.
	 */
	public function train_model(): ?array {
		$response = $this->make_request( 'POST', 'segmentation/train', array() );

		if ( $response ) {
			// Clear segments cache after training
			delete_transient( 'peanut_ml_visitor_segments' );
		}

		return $response;
	}

	/**
	 * Get segmentation model statistics.
	 *
	 * Returns stats like number of clusters, silhouette score, etc.
	 *
	 * @return array|null Stats data or null on failure.
	 */
	public function get_stats(): ?array {
		$cache_key = 'peanut_ml_segmentation_stats';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$response = $this->make_request( 'GET', 'segmentation/stats' );

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, $this->cache_ttl );

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
			error_log( 'Peanut ML Segmentation Error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			error_log( "Peanut ML Segmentation Error: HTTP {$code} - {$body}" );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			error_log( 'Peanut ML Segmentation Error: Invalid JSON response' );
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
		if ( ! wp_next_scheduled( 'peanut_ml_segmentation_train' ) ) {
			wp_schedule_event( time(), 'weekly', 'peanut_ml_segmentation_train' );
		}
	}

	/**
	 * Unschedule model training.
	 *
	 * @return void
	 */
	public static function unschedule_training(): void {
		$timestamp = wp_next_scheduled( 'peanut_ml_segmentation_train' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'peanut_ml_segmentation_train' );
		}
	}
}
