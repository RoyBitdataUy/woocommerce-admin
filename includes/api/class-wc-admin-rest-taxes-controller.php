<?php
/**
 * REST API Taxes Controller
 *
 * Handles requests to /taxes/*
 *
 * @package WooCommerce Admin/API
 */

defined( 'ABSPATH' ) || exit;

/**
 * Taxes controller.
 *
 * @package WooCommerce Admin/API
 * @extends WC_REST_Taxes_Controller
 */
class WC_Admin_REST_Taxes_Controller extends WC_REST_Taxes_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v4';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_tax_query', array( __CLASS__, 'add_tax_code_query_args' ), 10, 2 );
		add_filter( 'woocommerce_rest_tax_query_string', array( __CLASS__, 'add_tax_code_filter' ), 10, 2 );
	}

	/**
	 * Get all taxes and allow filtering by tax code.
	 *
	 * @todo This is mostly copied from
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		global $wpdb;

		$prepared_args           = array();
		$prepared_args['order']  = $request['order'];
		$prepared_args['number'] = $request['per_page'];
		if ( ! empty( $request['offset'] ) ) {
			$prepared_args['offset'] = $request['offset'];
		} else {
			$prepared_args['offset'] = ( $request['page'] - 1 ) * $prepared_args['number'];
		}
		$orderby_possibles        = array(
			'id'    => 'tax_rate_id',
			'order' => 'tax_rate_order',
		);
		$prepared_args['orderby'] = $orderby_possibles[ $request['orderby'] ];
		$prepared_args['class']   = $request['class'];

		/**
		 * Filter arguments, before passing to $wpdb->get_results(), when querying taxes via the REST API.
		 *
		 * @param array           $prepared_args Array of arguments for $wpdb->get_results().
		 * @param WP_REST_Request $request       The current request.
		 */
		$prepared_args = apply_filters( 'woocommerce_rest_tax_query', $prepared_args, $request );

		$query = "
			SELECT *
			FROM {$wpdb->prefix}woocommerce_tax_rates
			WHERE 1 = 1
		";

		// Filter by tax class.
		if ( ! empty( $prepared_args['class'] ) ) {
			$class  = 'standard' !== $prepared_args['class'] ? sanitize_title( $prepared_args['class'] ) : '';
			$query .= " AND tax_rate_class = '$class'";
		}

		/**
		 * Filter the query string to conditionally return tax codes in the REST API.
		 *
		 * @todo Remove this if https://github.com/woocommerce/woocommerce/pull/22813 gets merged.
		 * @param string $query         Query string used to look up tax codes.
		 * @param array  $prepared_args Array of arguments for $wpdb->get_results().
		 */
		$query = apply_filters( 'woocommerce_rest_tax_query_string', $query, $prepared_args );

		// Order tax rates.
		$order_by = sprintf( ' ORDER BY %s', sanitize_key( $prepared_args['orderby'] ) );

		// Pagination.
		$pagination = sprintf( ' LIMIT %d, %d', $prepared_args['offset'], $prepared_args['number'] );

		// Query taxes.
		$results = $wpdb->get_results( $query . $order_by . $pagination ); // @codingStandardsIgnoreLine.

		$taxes = array();
		foreach ( $results as $tax ) {
			$data    = $this->prepare_item_for_response( $tax, $request );
			$taxes[] = $this->prepare_response_for_collection( $data );
		}

		$response = rest_ensure_response( $taxes );

		// Store pagination values for headers then unset for count query.
		$per_page = (int) $prepared_args['number'];
		$page = ceil( ( ( (int) $prepared_args['offset'] ) / $per_page ) + 1 );

		// Query only for ids.
		$wpdb->get_results( str_replace( 'SELECT *', 'SELECT tax_rate_id', $query ) ); // @codingStandardsIgnoreLine.

		// Calculate totals.
		$total_taxes = (int) $wpdb->num_rows;
		$response->header( 'X-WP-Total', (int) $total_taxes );
		$max_pages = ceil( $total_taxes / $per_page );
		$response->header( 'X-WP-TotalPages', (int) $max_pages );

		$base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );
		if ( $page > 1 ) {
			$prev_page = $page - 1;
			if ( $prev_page > $max_pages ) {
				$prev_page = $max_pages;
			}
			$prev_link = add_query_arg( 'page', $prev_page, $base );
			$response->link_header( 'prev', $prev_link );
		}
		if ( $max_pages > $page ) {
			$next_page = $page + 1;
			$next_link = add_query_arg( 'page', $next_page, $base );
			$response->link_header( 'next', $next_link );
		}

		return $response;
	}

	/**
	 * Add tax code query arguments from request.
	 *
	 * @param array $prepared_args Array of arguments used for querying taxes.
	 * @param array $request Array of request parameters.
	 * @return array
	 */
	public static function add_tax_code_query_args( $prepared_args, $request ) {
		if ( $request['code'] ) {
			$prepared_args['code'] = $request['code'];
		}
		return $prepared_args;
	}

	/**
	 * Filter tax codes by code.
	 *
	 * @param string $query Sql query string.
	 * @param array  $prepared_args Array of arguments.
	 * @return string
	 */
	public static function add_tax_code_filter( $query, $prepared_args ) {
		global $wpdb;

		$tax_code_search = $prepared_args['code'];
		if ( $tax_code_search ) {
			$tax_code_search = $wpdb->esc_like( $tax_code_search );
			$tax_code_search = ' \'%' . $tax_code_search . '%\'';
			$query          .= ' AND CONCAT( tax_rate_name, "-", tax_rate_priority ) LIKE ' . $tax_code_search;
		}

		return $query;
	}
}
