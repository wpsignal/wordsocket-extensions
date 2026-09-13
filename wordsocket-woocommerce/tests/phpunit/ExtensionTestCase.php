<?php
/**
 * Shared fixture: fake credentials, intercepted HTTP, captured publishes.
 */

use PHPUnit\Framework\TestCase;

abstract class ExtensionTestCase extends TestCase {

	private const OPTIONS = array(
		'wpsignal_site_key',
		'wpsignal_site_secret',
		'wpsignal_jwt_secret',
		'wpsignal_limits',
		'wpsignal_last_publish_error',
	);

	private const MISSING = '__missing__';

	/** @var array<string, mixed> */
	private array $snapshot = array();

	/** @var array<int, array{channel: string, event: string, data: array}> */
	protected array $published = array();

	/** @var callable|null */
	private $http_filter = null;

	/** @var int[] Post-backed objects (products) to delete in tearDown. */
	protected array $products = array();

	/** @var int[] Orders to delete in tearDown. */
	protected array $orders = array();

	protected function setUp(): void {
		parent::setUp();
		foreach ( self::OPTIONS as $name ) {
			$this->snapshot[ $name ] = get_option( $name, self::MISSING );
		}
		update_option( 'wpsignal_site_key', 'phpunitkey' );
		update_option( 'wpsignal_site_secret', 'phpunitsecret' );
		update_option( 'wpsignal_jwt_secret', 'phpunitjwt' );
		unset( $_SERVER['HTTPS'] ); // plain bodies, so events are readable
		$this->published   = array();
		$this->http_filter = function ( $pre, $args, $url ) {
			if ( ! str_ends_with( (string) $url, '/publish' ) ) {
				return $pre;
			}
			$body              = json_decode( (string) $args['body'], true );
			$this->published[] = array(
				'channel' => (string) $body['channel'],
				'event'   => (string) $body['event'],
				'data'    => (array) $body['data'],
			);
			return array(
				'response' => array( 'code' => 200, 'message' => '' ),
				'headers'  => array(),
				'body'     => '{"ok":true}',
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		foreach ( $this->orders as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->products as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
			}
		}
		foreach ( $this->snapshot as $name => $value ) {
			if ( self::MISSING === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		parent::tearDown();
	}

	/** Events published to a channel, oldest first. */
	protected function events_on( string $channel, ?string $event = null ): array {
		return array_values(
			array_filter(
				$this->published,
				static fn( $p ) => $p['channel'] === $channel && ( null === $event || $p['event'] === $event )
			)
		);
	}

	protected function make_product( int $stock = 5, int $low = 2 ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'PHPUnit Widget' );
		$product->set_regular_price( '9.99' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_low_stock_amount( $low );
		$product->save();
		$this->products[] = $product->get_id();
		$this->published  = array(); // creation noise is not under test
		return $product;
	}

	protected function make_order( WC_Product $product, int $qty = 1 ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => 0 ) );
		$order->add_product( $product, $qty );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->calculate_totals();
		$order->save();
		$this->orders[] = $order->get_id();
		return $order;
	}
}
