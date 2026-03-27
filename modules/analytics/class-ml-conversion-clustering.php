<?php
/**
 * ML Conversion Path Clustering Integration
 *
 * Integrates the conversion path clustering ML module into Peanut Suite's
 * analytics. Clusters customer journeys into natural segments (quick converters,
 * researchers, multi-device journeys, etc.) based on attribution touch data.
 *
 * @package Peanut_Suite
 * @subpackage Analytics
 * @since 4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversion Path Clustering ML Integration
 *
 * @class Peanut_ML_Conversion_Clustering
 */
class Peanut_ML_Conversion_Clustering {

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
	 * Cache TTL in seconds (30 minutes).
	 *
	 * @var int
	 */
	private $cache_ttl = 1800;

	/**
	 * Initialize the clustering service.
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
	 * Check if the ML clustering service is reachable.
	 *
	 * @return bool True if service is available, false otherwise.
	 */
	public function is_available(): bool {
		$cached = get_transient( 'peanut_ml_clustering_available' );
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
		set_transient( 'peanut_ml_clustering_available', $available ? 'yes' : 'no', 120 );

		return $available;
	}

	/**
	 * Get conversion path clusters for a date range.
	 *
	 * Calls the ML service to cluster conversion paths based on journey data.
	 *
	 * @param string $date_from    Start date (YYYY-MM-DD).
	 * @param string $date_to      End date (YYYY-MM-DD).
	 * @param string $source       Data source ('suite', 'formflow', or 'both').
	 * @param int    $max_clusters Maximum clusters to test (auto if null).
	 *
	 * @return array|null Array of clusters or null on failure.
	 */
	public function get_clusters(
		string $date_from = '',
		string $date_to = '',
		string $source = 'suite',
		?int $max_clusters = null
	): ?array {
		if ( empty( $date_from ) ) {
			$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}
		if ( empty( $date_to ) ) {
			$date_to = gmdate( 'Y-m-d' );
		}

		$cache_key = 'peanut_ml_conversion_clusters_' . md5( "$date_from:$date_to:$source" );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$params = array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'source'    => $source,
		);

		if ( $max_clusters ) {
			$params['max_clusters'] = $max_clusters;
		}

		$response = $this->make_request( 'POST', 'paths/cluster', $params );

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, $this->cache_ttl );

		return $response;
	}

	/**
	 * Get the cluster assignment for a specific visitor.
	 *
	 * @param string $visitor_id Visitor identifier.
	 * @param string $source     Data source ('suite' or 'formflow').
	 *
	 * @return array|null Visitor segment data or null on failure.
	 */
	public function get_visitor_segment(
		string $visitor_id,
		string $source = 'suite'
	): ?array {
		if ( empty( $visitor_id ) ) {
			return null;
		}

		$cache_key = 'peanut_ml_visitor_segment_' . md5( "$visitor_id:$source" );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$response = $this->make_request(
			'GET',
			'paths/visitor-segment/' . urlencode( $visitor_id ) . '?source=' . urlencode( $source )
		);

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, $this->cache_ttl );

		return $response;
	}

	/**
	 * Train the clustering model.
	 *
	 * Triggers model retraining on the ML service. Call this from a cron event.
	 *
	 * @param string $source     Data source ('suite', 'formflow', or 'both').
	 * @param string $date_from  Start date (YYYY-MM-DD).
	 * @param string $date_to    End date (YYYY-MM-DD).
	 *
	 * @return array|null Training result or null on failure.
	 */
	public function train_model(
		string $source = 'suite',
		string $date_from = '',
		string $date_to = ''
	): ?array {
		if ( empty( $date_from ) ) {
			$date_from = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
		}
		if ( empty( $date_to ) ) {
			$date_to = gmdate( 'Y-m-d' );
		}

		$params = array(
			'source'    => $source,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);

		$response = $this->make_request( 'POST', 'paths/train', $params );

		if ( $response ) {
			// Clear clusters cache after training
			delete_transient( 'peanut_ml_conversion_clusters_' . md5( "$date_from:$date_to:$source" ) );
		}

		return $response;
	}

	/**
	 * Get clustering model statistics.
	 *
	 * Returns stats like number of clusters, silhouette score, etc.
	 *
	 * @return array|null Stats data or null on failure.
	 */
	public function get_stats(): ?array {
		$cache_key = 'peanut_ml_clustering_stats';
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$response = $this->make_request( 'GET', 'paths/stats' );

		if ( ! $response ) {
			return null;
		}

		set_transient( $cache_key, $response, 3600 );

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
			error_log( 'Peanut ML Clustering Error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			error_log( "Peanut ML Clustering Error: HTTP {$code} - {$body}" );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			error_log( 'Peanut ML Clustering Error: Invalid JSON response' );
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
		if ( ! wp_next_scheduled( 'peanut_ml_conversion_clustering_train' ) ) {
			wp_schedule_event( time(), 'weekly', 'peanut_ml_conversion_clustering_train' );
		}
	}

	/**
	 * Unschedule model training.
	 *
	 * @return void
	 */
	public static function unschedule_training(): void {
		$timestamp = wp_next_scheduled( 'peanut_ml_conversion_clustering_train' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'peanut_ml_conversion_clustering_train' );
		}
	}
}
