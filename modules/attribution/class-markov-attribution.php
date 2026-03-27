<?php
/**
 * Markov Chain Attribution Model
 *
 * Adds a 6th attribution model to Peanut Suite's existing 5 static models.
 * Unlike the deterministic models (First Touch, Last Touch, Linear, Time Decay,
 * Position-Based), this model uses probabilistic channel removal effects
 * computed by the Peanut ML microservice.
 *
 * Integration points:
 *   - Attribution_Calculator: Add 'markov' to the models array
 *   - Attribution_Controller: New endpoint + model comparison
 *   - Frontend Attribution.tsx: New model option in dropdown
 *
 * @package Peanut_Suite
 * @subpackage Attribution
 * @since 4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Peanut_Markov_Attribution {

    /**
     * ML service base URL.
     */
    private static $service_url = null;

    /**
     * ML service API key.
     */
    private static $api_key = null;

    /**
     * Model identifier (used in attribution_results table).
     */
    const MODEL_ID = 'markov';

    /**
     * Human-readable model name.
     */
    const MODEL_NAME = 'Markov Chain (Removal Effect)';

    /**
     * Model description for the frontend.
     */
    const MODEL_DESCRIPTION = 'Probabilistic attribution based on channel removal effects. Each channel\'s credit reflects how much overall conversion probability would drop if that channel were removed from all customer journeys.';

    /**
     * Initialize ML service settings.
     */
    private static function init() {
        if ( self::$service_url === null ) {
            $settings = get_option( 'peanut_ml_settings', array() );
            self::$service_url = isset( $settings['service_url'] )
                ? trailingslashit( $settings['service_url'] )
                : 'http://127.0.0.1:8100/';
            self::$api_key = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        }
    }

    /**
     * Check if the Markov model is available.
     *
     * @return bool
     */
    public static function is_available(): bool {
        self::init();

        $cached = get_transient( 'peanut_markov_available' );
        if ( $cached !== false ) {
            return $cached === 'yes';
        }

        $response = wp_remote_get( self::$service_url . 'health', array(
            'timeout' => 3,
            'sslverify' => false,
        ) );

        $available = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
        set_transient( 'peanut_markov_available', $available ? 'yes' : 'no', 120 );

        return $available;
    }

    /**
     * Get Markov attribution report for a date range.
     *
     * This is the primary method for fetching Markov results.
     * Unlike the static models that calculate per-conversion,
     * Markov computes at the aggregate level across all journeys.
     *
     * @param string $date_from Start date (Y-m-d).
     * @param string $date_to   End date (Y-m-d).
     * @return array|null Attribution report or null on failure.
     */
    public static function get_report( string $date_from, string $date_to ): ?array {
        self::init();

        $cache_key = 'peanut_markov_report_' . md5( $date_from . $date_to );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $response = wp_remote_post( self::$service_url . 'attribution/markov', array(
            'timeout' => 30,
            'sslverify' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-ML-API-Key' => self::$api_key,
            ),
            'body' => wp_json_encode( array(
                'date_from' => $date_from,
                'date_to' => $date_to,
                'min_touches' => 1,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $body ) {
            return null;
        }

        // Cache for 15 minutes
        set_transient( $cache_key, $body, 900 );

        return $body;
    }

    /**
     * Compare Markov attribution with all static models.
     *
     * @param string $date_from
     * @param string $date_to
     * @return array|null Comparison data or null on failure.
     */
    public static function compare_models( string $date_from, string $date_to ): ?array {
        self::init();

        $response = wp_remote_get(
            add_query_arg(
                array( 'date_from' => $date_from, 'date_to' => $date_to ),
                self::$service_url . 'attribution/compare'
            ),
            array(
                'timeout' => 30,
                'sslverify' => false,
                'headers' => array(
                    'X-ML-API-Key' => self::$api_key,
                ),
            )
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /**
     * Trigger Markov model training.
     * Call from a daily/weekly cron event.
     *
     * @return array Training result from ML service.
     */
    public static function train(): array {
        self::init();

        $response = wp_remote_post( self::$service_url . 'attribution/train', array(
            'timeout' => 120,
            'sslverify' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-ML-API-Key' => self::$api_key,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'error', 'message' => $response->get_error_message() );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? array( 'status' => 'error' );
    }

    /**
     * Get the channel performance data formatted for the existing
     * Attribution.tsx frontend component.
     *
     * Matches the shape of Attribution_Database::get_channel_performance()
     * so it can be used interchangeably in the frontend.
     *
     * @param string $date_from
     * @param string $date_to
     * @return array Channel performance data compatible with existing API format.
     */
    public static function get_channel_performance( string $date_from, string $date_to ): array {
        $report = self::get_report( $date_from, $date_to );
        if ( ! $report || ! isset( $report['channels'] ) ) {
            return array();
        }

        // Map ML service response to match existing frontend expectations
        $channels = array();
        foreach ( $report['channels'] as $ch ) {
            $channels[] = array(
                'channel'           => $ch['channel'],
                'conversions'       => $ch['conversions_attributed'],
                'attributed_credit' => $ch['credit'],
                'attributed_value'  => $ch['value_attributed'],
                'touches'           => $ch['total_touches'],
            );
        }

        return $channels;
    }

    /**
     * Register the model in the available models list.
     *
     * Hook into the 'peanut_attribution_models' filter to add
     * Markov alongside the existing 5 models.
     *
     * @param array $models Existing models.
     * @return array Models with Markov added.
     */
    public static function register_model( array $models ): array {
        if ( self::is_available() ) {
            $models[] = array(
                'id'          => self::MODEL_ID,
                'name'        => self::MODEL_NAME,
                'description' => self::MODEL_DESCRIPTION,
            );
        }
        return $models;
    }

    /**
     * Schedule the daily training cron.
     */
    public static function schedule_training(): void {
        if ( ! wp_next_scheduled( 'peanut_markov_train' ) ) {
            wp_schedule_event( time(), 'daily', 'peanut_markov_train' );
        }
    }

    /**
     * Unschedule the training cron.
     */
    public static function unschedule_training(): void {
        $timestamp = wp_next_scheduled( 'peanut_markov_train' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'peanut_markov_train' );
        }
    }
}
