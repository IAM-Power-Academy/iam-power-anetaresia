<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook i vetëm për pagesat: Digistore24 (IPN), Ko-fi, Systeme.io, WooCommerce ose çdo platformë tjetër.
 *
 * URL: /wp-json/iampower/v1/webhook?key=CELESI
 * Opsionale: &package=ID  (detyron një paketë të caktuar — e përshtatshme për Systeme.io)
 */
class IAMP_Webhook {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function url( $package_id = 0 ) {
		$args = array( 'key' => IAMP_Settings::get( 'webhook_key' ) );
		if ( $package_id ) {
			$args['package'] = (int) $package_id;
		}
		return add_query_arg( $args, rest_url( 'iampower/v1/webhook' ) );
	}

	public static function routes() {
		register_rest_route(
			'iampower/v1',
			'/webhook',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Kontrolli bëhet me çelësin sekret më poshtë.
			)
		);
	}

	private static function respond( $text, $code = 200 ) {
		// Digistore24 pret "OK" si tekst të thjeshtë.
		add_filter(
			'rest_pre_serve_request',
			function ( $served, $result ) use ( $text ) {
				if ( ! headers_sent() ) {
					header( 'Content-Type: text/plain; charset=utf-8' );
				}
				echo $text; // phpcs:ignore
				return true;
			},
			10,
			2
		);
		return new WP_REST_Response( $text, $code );
	}

	public static function handle( WP_REST_Request $request ) {
		$key = (string) $request->get_param( 'key' );
		if ( ! $key || ! hash_equals( (string) IAMP_Settings::get( 'webhook_key' ), $key ) ) {
			IAMP_Log::add( 'webhook_error', 0, 'Çelës i pasaktë nga IP ' . IAMP_Log::ip() );
			return self::respond( 'FORBIDDEN', 403 );
		}

		if ( 'GET' === $request->get_method() ) {
			return self::respond( 'OK' ); // Test lidhjeje.
		}

		$payload = self::collect_payload( $request );

		// Ko-fi: verifikimi i token-it.
		$kofi_token = IAMP_Settings::get( 'kofi_token' );
		if ( isset( $payload['verification_token'] ) && $kofi_token && ! hash_equals( $kofi_token, (string) $payload['verification_token'] ) ) {
			IAMP_Log::add( 'webhook_error', 0, 'Token Ko-fi i pasaktë' );
			return self::respond( 'FORBIDDEN', 403 );
		}

		$flat  = array();
		self::flatten( $payload, '', $flat );
		$event = strtolower( (string) self::first( $flat, array( 'event', 'type', 'event_type', 'topic' ) ) );

		// Test lidhjeje nga Digistore24.
		if ( 'connection_test' === $event ) {
			IAMP_Log::add( 'webhook', 0, 'Test lidhjeje Digistore24 — OK' );
			return self::respond( 'OK' );
		}

		$email = self::find_email( $flat );
		if ( ! $email ) {
			IAMP_Log::add( 'webhook_error', 0, array( 'gabimi' => 'Email nuk u gjet', 'fushat' => array_slice( array_keys( $flat ), 0, 40 ) ) );
			return self::respond( 'OK' ); // Kthe OK që platforma të mos e përsërisë pafundësisht.
		}

		$first = (string) self::first( $flat, array( 'first_name', 'address_first_name', 'buyer_first_name', 'firstname', 'from_name', 'contact.fields.first_name', 'customer.first_name', 'billing.first_name' ) );
		$last  = (string) self::first( $flat, array( 'last_name', 'address_last_name', 'buyer_last_name', 'lastname', 'customer.last_name', 'billing.last_name' ) );
		$ref   = (string) self::first( $flat, array( 'order_id', 'kofi_transaction_id', 'transaction_id', 'order.id', 'id' ) );
		$src   = self::detect_source( $flat );
		if ( $ref ) {
			$ref = $src . ':' . $ref;
		}

		// Paketa.
		$packages = array();
		$forced   = absint( $request->get_param( 'package' ) );
		if ( $forced ) {
			$pkg = IAMP_DB::get_package( $forced );
			if ( $pkg ) {
				$packages[] = $pkg;
			}
		} else {
			$packages = self::match_packages( $flat );
		}

		if ( ! $packages ) {
			IAMP_Log::add( 'webhook_error', 0, array( 'gabimi' => 'Asnjë paketë nuk përputhet', 'email' => $email, 'produktet' => self::product_values( $flat ) ) );
			return self::respond( 'OK' );
		}

		// Rimbursim / chargeback / anulim → hiq qasjen.
		if ( self::is_refund( $event, $flat ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				foreach ( IAMP_DB::get_user_memberships( $user->ID, 'active' ) as $m ) {
					foreach ( $packages as $pkg ) {
						if ( (int) $m->package_id === (int) $pkg->id && 'webhook' === $m->source ) {
							IAMP_Access::revoke( $m->id, 'revoked', false );
						}
					}
				}
				IAMP_Log::add( 'webhook_refund', $user->ID, array( 'ngjarja' => $event, 'ref' => $ref ) );
			}
			return self::respond( 'OK' );
		}

		foreach ( $packages as $pkg ) {
			$res = IAMP_Access::grant(
				array(
					'email'        => $email,
					'first_name'   => sanitize_text_field( $first ),
					'last_name'    => sanitize_text_field( $last ),
					'package_id'   => (int) $pkg->id,
					'source'       => 'webhook',
					'external_ref' => $ref,
					'note'         => ucfirst( $src ) . ( $event ? ' — ' . $event : '' ),
					'send_email'   => true,
				)
			);
			if ( is_wp_error( $res ) ) {
				IAMP_Log::add( 'webhook_error', 0, array( 'gabimi' => $res->get_error_message(), 'email' => $email ) );
			} else {
				IAMP_Log::add( 'webhook', $res['user_id'], array( 'burimi' => $src, 'paketa' => $pkg->name, 'ref' => $ref, 'dublikatë' => ! empty( $res['duplicate'] ) ) );
			}
		}

		return self::respond( 'OK' );
	}

	/* ---------- Ndihmës ---------- */

	private static function collect_payload( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ! $payload ) {
			$payload = $request->get_body_params();
		}
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		// Ko-fi dërgon gjithçka si JSON brenda fushës "data".
		if ( isset( $payload['data'] ) && is_string( $payload['data'] ) ) {
			$decoded = json_decode( wp_unslash( $payload['data'] ), true );
			if ( is_array( $decoded ) ) {
				unset( $payload['data'] );
				$payload = array_merge( $payload, $decoded );
			}
		}
		unset( $payload['key'], $payload['package'] );
		return $payload;
	}

	private static function flatten( $data, $prefix, &$out ) {
		foreach ( (array) $data as $k => $v ) {
			$key = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) || is_object( $v ) ) {
				self::flatten( (array) $v, $key, $out );
			} elseif ( is_scalar( $v ) ) {
				$out[ strtolower( $key ) ] = (string) $v;
			}
		}
	}

	private static function first( $flat, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $flat[ $k ] ) && '' !== $flat[ $k ] ) {
				return $flat[ $k ];
			}
		}
		return '';
	}

	private static function find_email( $flat ) {
		$preferred = array( 'email', 'buyer_email', 'customer_email', 'contact.email', 'customer.email', 'billing.email', 'data.email', 'buyer.email', 'payer_email' );
		foreach ( $preferred as $k ) {
			if ( isset( $flat[ $k ] ) && is_email( $flat[ $k ] ) ) {
				return sanitize_email( $flat[ $k ] );
			}
		}
		foreach ( $flat as $k => $v ) {
			if ( false !== strpos( $k, 'email' ) && is_email( $v ) ) {
				return sanitize_email( $v );
			}
		}
		return '';
	}

	/**
	 * Vlerat që mund të identifikojnë produktin.
	 */
	private static function product_values( $flat ) {
		$vals = array();
		foreach ( $flat as $k => $v ) {
			$last = substr( strrchr( '.' . $k, '.' ), 1 );
			if ( preg_match( '/(product|tier|offer|plan|item|direct_link_code|tag|course|funnel|price_plan|sku)/', $last ) ) {
				$v = trim( $v );
				if ( '' !== $v && strlen( $v ) < 200 ) {
					$vals[] = strtolower( $v );
				}
			}
		}
		return array_values( array_unique( $vals ) );
	}

	private static function match_packages( $flat ) {
		$vals = self::product_values( $flat );
		if ( ! $vals ) {
			return array();
		}
		$found = array();
		foreach ( IAMP_DB::get_packages( true ) as $pkg ) {
			$ids = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $pkg->product_ids ) ) ) );
			if ( array_intersect( $ids, $vals ) ) {
				$found[] = $pkg;
			}
		}
		return $found;
	}

	private static function is_refund( $event, $flat ) {
		$refund_events = array( 'on_refund', 'on_chargeback', 'on_payment_missed', 'refund', 'chargeback', 'order.refunded', 'subscription_canceled_immediately', 'sale.refunded' );
		if ( in_array( $event, $refund_events, true ) ) {
			return true;
		}
		$status = strtolower( (string) self::first( $flat, array( 'status', 'order_status', 'payment_status' ) ) );
		return in_array( $status, array( 'refunded', 'chargeback', 'reversed' ), true );
	}

	private static function detect_source( $flat ) {
		if ( isset( $flat['kofi_transaction_id'] ) || isset( $flat['verification_token'] ) ) {
			return 'kofi';
		}
		if ( isset( $flat['sha_sign'] ) || isset( $flat['address_first_name'] ) || 0 === strpos( (string) self::first( $flat, array( 'event' ) ), 'on_' ) ) {
			return 'digistore24';
		}
		foreach ( array_keys( $flat ) as $k ) {
			if ( 0 === strpos( $k, 'contact.' ) || 0 === strpos( $k, 'funnel' ) ) {
				return 'systeme';
			}
		}
		return 'webhook';
	}
}
