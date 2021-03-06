<?php
/**
 * Handles running payment method specs
 */

namespace Automattic\WooCommerce\Admin\Features\RemotePaymentMethods;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Admin\RemoteInboxNotifications\SpecRunner;
use Automattic\WooCommerce\Admin\Features\RemotePaymentMethods\PaymentGatewaysController;

/**
 * Remote Payment Methods engine.
 * This goes through the specs and gets eligible payment methods.
 */
class Init {
	const SPECS_TRANSIENT_NAME = 'woocommerce_admin_remote_payment_methods_specs';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'change_locale', array( __CLASS__, 'delete_specs_transient' ) );
		PaymentGatewaysController::init();
	}

	/**
	 * Go through the specs and run them.
	 */
	public static function get_methods() {
		$methods = array();
		$specs   = self::get_specs();

		foreach ( $specs as $spec ) {
			$method    = EvaluateMethod::evaluate( $spec );
			$methods[] = $method;
		}

		return $methods;
	}

	/**
	 * Delete the specs transient.
	 */
	public static function delete_specs_transient() {
		delete_transient( self::SPECS_TRANSIENT_NAME );
	}

	/**
	 * Get specs or fetch remotely if they don't exist.
	 */
	public static function get_specs() {
		$specs = get_transient( self::SPECS_TRANSIENT_NAME );

		// Fetch specs if they don't yet exist.
		if ( false === $specs || ! is_array( $specs ) || 0 === count( $specs ) ) {
			if ( 'no' === get_option( 'woocommerce_show_marketplace_suggestions', 'yes' ) ) {
				return self::get_default_specs();
			}

			$specs = DataSourcePoller::read_specs_from_data_sources();

			// Fall back to default specs if polling failed.
			if ( ! $specs ) {
				return self::get_default_specs();
			}

			$specs = self::localize( $specs );
			set_transient( self::SPECS_TRANSIENT_NAME, $specs, 7 * DAY_IN_SECONDS );
		}

		return $specs;
	}

	/**
	 * Get default specs.
	 *
	 * @return array Default specs.
	 */
	public static function get_default_specs() {
		return array(
			(object) array(
				'key'        => 'payfast',
				'title'      => __( 'PayFast', 'woocommerce-admin' ),
				'content'    => __( 'The PayFast extension for WooCommerce enables you to accept payments by Credit Card and EFT via one of South Africa’s most popular payment gateways. No setup fees or monthly subscription costs.  Selecting this extension will configure your store to use South African rands as the selected currency.', 'woocommerce-admin' ),
				'image'      => __( 'https =>//www.payfast.co.za/assets/images/payfast_logo_colour.svg', 'woocommerce-admin' ),
				'plugins'    => array( 'woocommerce-payfast-gateway' ),
				'is_visible' => (object) array(
					'type'      => 'base_location_country',
					'value'     => 'ZA',
					'operation' => '=',
				),
			),
		);
	}

	/**
	 * Localize the provided method.
	 *
	 * @param array $specs The specs to localize.
	 * @return array Localized specs.
	 */
	public static function localize( $specs ) {
		$localized_specs = array();

		foreach ( $specs as $spec ) {
			if ( ! isset( $spec->locales ) ) {
				continue;
			}

			$locale = SpecRunner::get_locale( $spec->locales );

			// Skip specs where no matching locale is found.
			if ( ! $locale ) {
				continue;
			}

			$data = (object) array_merge( (array) $locale, (array) $spec );
			unset( $data->locales );

			$data->fields = array();

			// Loop over and localize fields.
			foreach ( $spec->fields as $field ) {
				if ( ! isset( $field->locales ) ) {
					continue;
				}

				$locale = SpecRunner::get_locale( $field->locales );

				if ( ! $locale ) {
					continue;
				}

				$field_data = (object) array_merge( (array) $field, (array) $locale );
				unset( $field_data->locale );
				unset( $field_data->locales );
				$data->fields[] = $field_data;
			}

			$localized_specs[] = $data;
		}

		return $localized_specs;
	}
}
