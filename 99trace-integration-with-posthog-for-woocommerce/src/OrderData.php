<?php
/**
 * WooCommerce orders, refunds and cart lines as plain arrays. All times are true UTC
 * (getTimestamp(), never WC_DateTime::date()).
 *
 * @package W99Phwc
 */

namespace W99Phwc;

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

	/** One cart line, same shape. $for: self::FOR_SERVER, or self::FOR_PAGE when the line is printed into the page. */
	public static function cart_line( array $item, int $position, string $for = self::FOR_SERVER ): array {
		$product = $item['data'] ?? null;
		$qty     = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		$row     = self::product_fields( $product instanceof \WC_Product ? $product : null, (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ), $for );
		$row['name']       = $product instanceof \WC_Product ? $product->get_name() : '';
		$row['price']      = isset( $item['line_subtotal'] ) ? Util::money( (float) $item['line_subtotal'] / $qty ) : ( $product instanceof \WC_Product ? (float) $product->get_price() : 0.0 );
		$row['quantity']   = (int) ( $item['quantity'] ?? 1 );
		$row['position']   = $position;
		$row['line_total'] = Util::money( (float) ( $item['line_total'] ?? 0 ) );
		return $row;
	}

	/** Product data for an event the shop's server sends to PostHog. */
	public const FOR_SERVER = 'server';
	/** Product data printed into the page for the browser module: every visitor (and any cache) sees it. */
	public const FOR_PAGE = 'page';

	/**
	 * WooCommerce product data never sent: long texts (descriptions, purchase note), file links of downloadable
	 * products, the post password, raw meta (custom fields have their own setting), attribute and term IDs (sent
	 * as names instead) and the fields products[] lines already carry under their own names.
	 */
	private const NOT_SENT = array(
		'id' => 1, 'name' => 1, 'sku' => 1, 'price' => 1, 'parent_id' => 1, 'description' => 1, 'short_description' => 1,
		'purchase_note' => 1, 'downloads' => 1, 'post_password' => 1, 'meta_data' => 1, 'attributes' => 1,
		'default_attributes' => 1, 'image_id' => 1, 'gallery_image_ids' => 1, 'category_ids' => 1, 'tag_ids' => 1,
		'brand_ids' => 1, 'shipping_class_id' => 1,
	);

	/** Shop-internal numbers kept out of data printed into pages (visible in the page source to every visitor). */
	private const NOT_ON_PAGES = array( 'total_sales' => 1, 'stock_quantity' => 1, 'low_stock_amount' => 1 );

	/** WooCommerce stores these numbers as text; sent as numbers. */
	private const NUMERIC = array( 'regular_price' => 1, 'sale_price' => 1, 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'average_rating' => 1 );

	/** Custom fields: at most this many per product, each value at most this many characters (longer ones are cut). */
	private const CUSTOM_FIELDS_MAX = 100;
	private const CUSTOM_FIELD_CHARS = 500;

	/**
	 * Product description fields: product_id (parent), variant (variation ID), sku, category, url, image (unchanged
	 * since 0.2), then every other public WooCommerce product property (1.1.0, product_details()). Fields already
	 * present are never overwritten.
	 */
	public static function product_fields( ?\WC_Product $product, int $parent_id, int $variation_id, string $for = self::FOR_SERVER ): array {
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
		return $row + self::product_details( $product, $parent_id, $for );
	}

	/**
	 * Every public WooCommerce property of the product: its type, the parent's name and SKU and the variation's own
	 * address (with its attributes in the query, as WooCommerce links a variation), WooCommerce's product data (prices,
	 * stock, dimensions, tax, visibility, dates, ratings …) read as the shop shows it (a variation inherits what it does
	 * not set itself), all its attributes by name (`attributes`: {"pa_bundle": "2 x Product"}), and all category, tag,
	 * brand and shipping class names. Custom fields and WooCommerce's cost of goods (`cogs_*`) only with Events → Product
	 * custom fields (cost of goods: "all"), and never in page data.
	 */
	public static function product_details( ?\WC_Product $product, int $parent_id, string $for = self::FOR_SERVER ): array {
		if ( ! $product instanceof \WC_Product ) {
			return array();
		}
		$on_page = self::FOR_PAGE === $for;
		$parent  = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		$out     = array( 'product_type' => (string) $product->get_type() );
		if ( $parent instanceof \WC_Product ) {
			$out['parent_name'] = (string) $parent->get_name();
			$out['parent_sku']  = (string) $parent->get_sku();
			$out['variant_url'] = (string) $product->get_permalink();
		}
		$internal = ! $on_page && 'all' === Settings::get( 'product_meta' );
		foreach ( array_keys( $product->get_data() ) as $key ) {
			if ( isset( self::NOT_SENT[ $key ] ) || ( $on_page && isset( self::NOT_ON_PAGES[ $key ] ) ) ) {
				continue;
			}
			if ( 0 === strpos( (string) $key, 'cogs_' ) && ! $internal ) {
				continue; // WooCommerce's own cost of goods is a purchase cost: internal, sent only with custom fields "all".
			}
			$getter = 'get_' . $key;
			if ( ! is_callable( array( $product, $getter ) ) ) {
				continue;
			}
			try {
				$value = self::plain( $product->{$getter}() );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( isset( self::NUMERIC[ $key ] ) && is_string( $value ) && is_numeric( $value ) ) {
				$value = (float) $value;
			}
			if ( null !== $value && '' !== $value && array() !== $value ) {
				$out[ $key ] = $value;
			}
		}
		$out['on_sale'] = (bool) $product->is_on_sale();
		$attributes     = self::attributes( $product, $parent );
		if ( $attributes ) {
			$out['attributes'] = $attributes;
		}
		$term_owner = $parent_id ? $parent_id : $product->get_id();
		foreach ( array( 'categories' => 'product_cat', 'tags' => 'product_tag', 'brands' => 'product_brand' ) as $field => $taxonomy ) {
			$names = taxonomy_exists( $taxonomy ) ? self::term_names( $term_owner, $taxonomy ) : array();
			if ( $names ) {
				$out[ $field ] = $names;
			}
		}
		$class = (string) $product->get_shipping_class();
		if ( '' !== $class ) {
			$term                  = get_term_by( 'slug', $class, 'product_shipping_class' );
			$out['shipping_class'] = $term instanceof \WP_Term ? $term->name : $class;
		}
		if ( ! $on_page ) {
			$fields = self::custom_fields( $product, $parent );
			if ( $fields ) {
				$out['custom_fields'] = $fields;
			}
		}
		/**
		 * The public product properties 99Trace adds to product events and products[] lines.
		 *
		 * @param array       $out     Properties by name.
		 * @param \WC_Product $product The product or variation.
		 * @param string      $for     'server' (sent by the shop's server) or 'page' (printed into the page).
		 */
		$filtered = apply_filters( 'w99_phwc_product_properties', $out, $product, $for );
		return is_array( $filtered ) ? $filtered : $out;
	}

	/**
	 * Attribute name => value, as shoppers see them. A variation's own choices first ("pa_bundle" => "2 x Product",
	 * term names for global attributes, the text for custom ones), then the parent's or the product's other visible
	 * attributes (all their options, comma-separated). Keys are the names WooCommerce uses in a variation's
	 * address without "attribute_": "pa_<slug>" for global attributes, the sanitized name for custom ones.
	 */
	public static function attributes( \WC_Product $product, ?\WC_Product $parent = null ): array {
		$out = array();
		if ( $product instanceof \WC_Product_Variation ) {
			foreach ( $product->get_variation_attributes( false ) as $name => $value ) {
				$name  = (string) $name;
				$value = (string) $value;
				if ( '' === $value ) {
					continue; // "Any …": the shopper's choice is in the cart line's own data, not on the variation.
				}
				if ( taxonomy_exists( $name ) ) {
					$term  = get_term_by( 'slug', $value, $name );
					$value = $term instanceof \WP_Term ? $term->name : $value;
				}
				$out[ $name ] = $value;
			}
		}
		$source = $parent instanceof \WC_Product ? $parent : $product;
		foreach ( $source->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ( ! $attribute->get_visible() && ! $attribute->get_variation() ) ) {
				continue; // A hidden attribute that varies nothing is not shown to shoppers.
			}
			$name = $attribute->is_taxonomy() ? (string) $attribute->get_name() : sanitize_title( $attribute->get_name() );
			if ( isset( $out[ $name ] ) ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $source->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$values = array_values( array_filter( array_map( 'strval', (array) $values ), 'strlen' ) );
			if ( $values ) {
				$out[ $name ] = implode( ', ', $values );
			}
		}
		return $out;
	}

	/** Names of the product's terms in one taxonomy, in WooCommerce's order. */
	private static function term_names( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( static fn( $t ) => (string) $t->name, $terms ) );
	}

	/**
	 * Custom fields (product meta) by key, following Events → Product custom fields: none (default), the visible ones
	 * (keys not starting with "_"), or all of them. A variation's own fields win over its parent's. Values are kept as
	 * text, cut at 500 characters; lists and objects are sent as JSON when they fit.
	 */
	public static function custom_fields( \WC_Product $product, ?\WC_Product $parent = null ): array {
		$mode = Settings::get( 'product_meta' );
		if ( 'visible' !== $mode && 'all' !== $mode ) {
			return array();
		}
		$out = array();
		foreach ( array( $product, $parent ) as $source ) {
			if ( ! $source instanceof \WC_Product ) {
				continue;
			}
			foreach ( $source->get_meta_data() as $meta ) {
				$data  = $meta->get_data();
				$key   = (string) ( $data['key'] ?? '' );
				$value = $data['value'] ?? null;
				if ( '' === $key || isset( $out[ $key ] ) || ( 'visible' === $mode && is_protected_meta( $key, 'post' ) ) ) {
					continue;
				}
				if ( count( $out ) >= self::CUSTOM_FIELDS_MAX ) {
					break 2;
				}
				if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
					$out[ $key ] = $value;
					continue;
				}
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = wp_json_encode( $value );
					if ( ! is_string( $value ) || strlen( $value ) > self::CUSTOM_FIELD_CHARS ) {
						continue;
					}
				}
				if ( is_string( $value ) && '' !== $value ) {
					$out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::CUSTOM_FIELD_CHARS ) : substr( $value, 0, self::CUSTOM_FIELD_CHARS );
				}
			}
		}
		return $out;
	}

	/** A product property as JSON-friendly data: dates as UTC text, lists kept, objects dropped. */
	private static function plain( $value ) {
		if ( $value instanceof \DateTimeInterface ) {
			return Util::utc( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$v = self::plain( $v );
				if ( null !== $v ) {
					$out[ $k ] = $v;
				}
			}
			return $out;
		}
		return is_scalar( $value ) ? $value : null;
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
