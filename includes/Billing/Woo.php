<?php
/**
 * WooCommerce checkout for job packages: the package's product goes into the cart at the package price,
 * and a paid order activates the package (and submits the waiting job). Works with HPOS and the block checkout (order item meta only).
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Billing;

use Dev3\AiJobBoard\Data\Payments;
use Dev3\AiJobBoard\Data\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * Static adapter.
 */
class Woo {

	const ITEM_KEY = 'dev3jb_payment';

	/**
	 * Hooks (only when WooCommerce is active).
	 */
	public static function init() {
		if ( did_action( 'woocommerce_loaded' ) ) {
			self::register();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'register' ) );
		}
	}

	/**
	 * WooCommerce hooks.
	 */
	public static function register() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'order_paid' ) );
	}

	/**
	 * Hidden virtual product used for a package (created on demand).
	 *
	 * @param array $plan Package.
	 * @return int|\WP_Error
	 */
	public static function product_for( array $plan ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new \WP_Error( 'dev3jb_woo_missing', __( 'WooCommerce is not active.', 'dev3-ai-job-board' ), array( 'status' => 400 ) );
		}
		if ( $plan['wc_product_id'] && function_exists( 'wc_get_product' ) && wc_get_product( $plan['wc_product_id'] ) ) {
			return (int) $plan['wc_product_id'];
		}
		$product = new \WC_Product_Simple();
		/* translators: %s: package name */
		$product->set_name( sprintf( __( 'Job package: %s', 'dev3-ai-job-board' ), $plan['name'] ) );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( (string) $plan['price'] );
		$product->set_status( 'publish' );
		$product->update_meta_data( '_dev3jb_package', $plan['id'] );
		$id       = (int) $product->save();
		$packages = Packages::all();
		foreach ( $packages as &$p ) {
			if ( $p['id'] === $plan['id'] ) {
				$p['wc_product_id'] = $id;
			}
		}
		unset( $p );
		Packages::save( $packages );
		return $id;
	}

	/**
	 * Puts the package in the cart and returns the checkout URL.
	 *
	 * @param array $payment Payment.
	 * @param array $plan    Package.
	 * @return string|\WP_Error
	 */
	public static function add_to_cart( array $payment, array $plan ) {
		$product_id = self::product_for( $plan );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart ) {
			return new \WP_Error( 'dev3jb_woo_cart', __( 'The WooCommerce cart is not available.', 'dev3-ai-job-board' ), array( 'status' => 500 ) );
		}
		WC()->cart->add_to_cart(
			$product_id,
			1,
			0,
			array(),
			array(
				self::ITEM_KEY => (int) $payment['id'],
				'dev3jb_price' => (float) $plan['price'],
			)
		);
		Payments::set_ref( $payment['id'], 'wc-cart-' . $payment['id'] );
		return wc_get_checkout_url();
	}

	/**
	 * Package price in the cart.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function set_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item[ self::ITEM_KEY ], $item['dev3jb_price'] ) && isset( $item['data'] ) ) {
				$item['data']->set_price( (float) $item['dev3jb_price'] );
			}
		}
	}

	/**
	 * Shows the job in the cart.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		if ( isset( $item[ self::ITEM_KEY ] ) ) {
			$payment = Payments::get( (int) $item[ self::ITEM_KEY ] );
			if ( $payment && $payment['job_title'] ) {
				$data[] = array(
					'key'   => __( 'Job', 'dev3-ai-job-board' ),
					'value' => esc_html( $payment['job_title'] ),
				);
			}
		}
		return $data;
	}

	/**
	 * Copies the payment id to the order item.
	 *
	 * @param \WC_Order_Item_Product $order_item Item.
	 * @param string                 $key        Cart key.
	 * @param array                  $values     Cart values.
	 */
	public static function line_item( $order_item, $key, $values ) {
		unset( $key );
		if ( isset( $values[ self::ITEM_KEY ] ) ) {
			$order_item->add_meta_data( '_' . self::ITEM_KEY, (int) $values[ self::ITEM_KEY ], true );
		}
	}

	/**
	 * Paid order → complete the package payments it contains.
	 *
	 * @param int $order_id Order.
	 * @return int Payments completed.
	 */
	public static function order_paid( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return 0;
		}
		$done = 0;
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_meta( '_' . self::ITEM_KEY );
			if ( ! $pid ) {
				continue;
			}
			$payment = Payments::get( $pid );
			if ( $payment && 'paid' !== $payment['status'] ) {
				Payments::set_ref( $pid, 'wc-order-' . $order->get_id() );
				Payments::complete( $pid );
				++$done;
			}
		}
		return $done;
	}
}
