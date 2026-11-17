<?php
/**
 * WooCommerce orders, refunds and cart lines as plain arrays. All times are true UTC
 * (getTimestamp(), never WC_DateTime::date()).
 *
 * @package W99\Trace
 */

namespace W99\Trace;

defined( 'ABSPATH' ) || exit;

final class OrderData {

	/** Order fields used by the event builders. */
	public static function order( \WC_Order $order ): array {
		$created = $order->get_date_created();
		$paid    = $order->get_date_paid();
		$lines   = array();
		$position = 1;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$lines[] = self::order_line( $item, $position++ );
			}
		}
		return array(
			'id'             => (string) $order->get_id(),
			'number'         => (string) $order->get_order_number(),
			'currency'       => (string) $order->get_currency(),
			'total'          => (float) $order->get_total(),
			'subtotal'       => (float) $order->get_subtotal(),
			'discount'       => (float) $order->get_total_discount(),
			'shipping'       => (float) $order->get_shipping_total(),
			'tax'            => (float) $order->get_total_tax(),
			'coupons'        => array_values( $order->get_coupon_codes() ),
			'payment_method' => (string) $order->get_payment_method(),
			'payment_title'  => (string) $order->get_payment_method_title(),
			'created_via'    => (string) $order->get_created_via(),
			'customer_type'  => $order->get_customer_id() ? 'registered' : 'guest',
			'created_at'     => Util::utc( $created ),
			'paid_at'        => $paid ? Util::utc( $paid ) : null,
			'lines'          => $lines,
		);
	}

	/**
	 * One order line in the products[] shape the shops' current plugin uses (product_id = parent,
	 * variant = variation ID, price = unit price), plus the line's paid totals.
	 */
	public static function order_line( \WC_Order_Item_Product $item, int $position ): array {
		$product   = $item->get_product();
		$qty       = max( 1, abs( (int) $item->get_quantity() ) ); // refund lines carry negative quantities
		$row       = self::product_fields( $product instanceof \WC_Product ? $product : null, (int) $item->get_product_id(), (int) $item->get_variation_id() );
		$row['name']     = $item->get_name();
		$row['price']    = Util::money( (float) $item->get_subtotal() / $qty );   // unit price before cart discounts, excl. tax
		$row['quantity'] = (int) $item->get_quantity();
		$row['position'] = $position;
		$row['line_total'] = Util::money( (float) $item->get_total() );         // after discounts, excl. tax
		return $row;
	}

	/** One cart line, same shape. */
	public static function cart_line( array $item, int $position ): array {
		$product = $item['data'] ?? null;
		$qty     = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		$row     = self::product_fields( $product instanceof \WC_Product ? $product : null, (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );
		$row['name']       = $product instanceof \WC_Product ? $product->get_name() : '';
		$row['price']      = isset( $item['line_subtotal'] ) ? Util::money( (float) $item['line_subtotal'] / $qty ) : ( $product instanceof \WC_Product ? (float) $product->get_price() : 0.0 );
		$row['quantity']   = (int) ( $item['quantity'] ?? 1 );
		$row['position']   = $position;
		$row['line_total'] = Util::money( (float) ( $item['line_total'] ?? 0 ) );
		return $row;
	}

	/** Product description fields: product_id (parent), variant (variation ID), sku, category, url, image. */
	public static function product_fields( ?\WC_Product $product, int $parent_id, int $variation_id ): array {
		if ( ! $parent_id && $product ) {
			$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		}
		$row = array(
			'product_id' => (string) $parent_id,
			'sku'        => '',
			'category'   => '',
			'url'        => '',
			'image_url'  => '',
		);
		if ( $variation_id ) {
			$row['variant'] = (string) $variation_id;
		}
		if ( $product ) {
			$row['sku'] = (string) $product->get_sku();
			if ( '' === $row['sku'] && $variation_id ) {
				$parent     = wc_get_product( $parent_id );
				$row['sku'] = $parent ? (string) $parent->get_sku() : '';
			}
			$image_id = $product->get_image_id();
			$row['image_url'] = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			if ( $variation_id && $product instanceof \WC_Product_Variation ) {
				$row['variant_name'] = wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, false ) );
			}
		}
		$terms = get_the_terms( $parent_id, 'product_cat' );
		if ( is_array( $terms ) && $terms ) {
			$row['category'] = $terms[0]->name;
		}
		$row['url'] = (string) get_permalink( $parent_id );
		return $row;
	}

	/** "|SKU1|SKU2|": the products_string the shops' current plugin sends (SKU, else product ID). */
	public static function products_string( array $lines ): string {
		$ids = array();
		foreach ( $lines as $l ) {
			$id = '' !== ( $l['sku'] ?? '' ) ? $l['sku'] : ( $l['product_id'] ?? '' );
			if ( '' !== (string) $id ) {
				$ids[] = (string) $id;
			}
		}
		return $ids ? '|' . implode( '|', $ids ) . '|' : '|';
	}

	/** Refund fields. Amounts positive. */
	public static function refund( \WC_Order $order, \WC_Order_Refund $refund ): array {
		$lines    = array();
		$position = 1;
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$line               = self::order_line( $item, $position++ );
				$line['quantity']   = abs( (int) $line['quantity'] );
				$line['price']      = abs( (float) $line['price'] );
				$line['line_total'] = abs( (float) $line['line_total'] );
				$lines[]            = $line;
			}
		}
		return array(
			'id'          => (string) $refund->get_id(),
			'amount'      => abs( (float) $refund->get_amount() ),
			'currency'    => (string) ( $refund->get_currency() ?: $order->get_currency() ),
			'reason'      => (string) $refund->get_reason(),
			'lines'       => $lines,
			'full_refund' => (float) $order->get_remaining_refund_amount() <= 0.0001,
			'created_at'  => Util::utc( $refund->get_date_created() ),
		);
	}

	/**
	 * WooCommerce's own order attribution (first-party, recorded at checkout from the session's
	 * source). Kept separate from PostHog's attribution: different tracker, different session rules.
	 * Its URLs are stripped like every other URL; for an order counted without consent they are
	 * left out (they can carry click IDs), and only the campaign-level fields remain.
	 */
	public static function wc_attribution( \WC_Order $order, bool $consented = true ): array {
		$map = array(
			'source_type'   => 'wc_attribution_source_type',
			'referrer'      => 'wc_attribution_referrer',
			'utm_source'    => 'wc_attribution_utm_source',
			'utm_medium'    => 'wc_attribution_utm_medium',
			'utm_campaign'  => 'wc_attribution_utm_campaign',
			'utm_content'   => 'wc_attribution_utm_content',
			'utm_term'      => 'wc_attribution_utm_term',
			'session_entry' => 'wc_attribution_session_entry',
			'session_pages' => 'wc_attribution_session_pages',
			'session_count' => 'wc_attribution_session_count',
			'device_type'   => 'wc_attribution_device_type',
		);
		$urls = array( 'referrer', 'session_entry' );
		$out  = array();
		foreach ( $map as $suffix => $prop ) {
			$v = $order->get_meta( '_wc_order_attribution_' . $suffix, true );
			if ( '' === (string) $v || ( ! $consented && in_array( $suffix, $urls, true ) ) ) {
				continue;
			}
			if ( in_array( $suffix, array( 'session_pages', 'session_count' ), true ) ) {
				$out[ $prop ] = (int) $v;
			} elseif ( in_array( $suffix, $urls, true ) ) {
				$out[ $prop ] = (string) Util::strip_url( substr( (string) $v, 0, 1000 ), Settings::strip_params() );
			} else {
				$out[ $prop ] = substr( (string) $v, 0, 300 );
			}
		}
		return $out;
	}
}
