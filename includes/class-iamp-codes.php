<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Codes {

	/**
	 * Gjeneron kode unike, p.sh. IAM-7KQ2-M9XD.
	 *
	 * @return string[]
	 */
	public static function generate( $package_id, $count, $max_uses = 1, $valid_until = '', $prefix = 'IAM', $note = '' ) {
		$count    = max( 1, min( 500, (int) $count ) );
		$max_uses = max( 1, (int) $max_uses );
		$prefix   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) );
		$prefix   = $prefix ? $prefix : 'IAM';
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // pa 0/O/1/I për të shmangur ngatërresat.
		$out      = array();
		$guard    = 0;

		while ( count( $out ) < $count && $guard < $count * 20 ) {
			$guard++;
			$part = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$part .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			$code = $prefix . '-' . substr( $part, 0, 4 ) . '-' . substr( $part, 4, 4 );
			if ( IAMP_DB::get_code( $code ) ) {
				continue;
			}
			$ok = IAMP_DB::insert_code(
				array(
					'code'        => $code,
					'package_id'  => (int) $package_id,
					'max_uses'    => $max_uses,
					'uses'        => 0,
					'valid_until' => $valid_until ? gmdate( 'Y-m-d 23:59:59', strtotime( $valid_until ) ) : null,
					'status'      => 'active',
					'note'        => $note ? substr( $note, 0, 190 ) : null,
				)
			);
			if ( $ok ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	public static function normalize( $code ) {
		return strtoupper( trim( preg_replace( '/\s+/', '', (string) $code ) ) );
	}

	/**
	 * Përdor kodin për një përdorues.
	 *
	 * @return array|WP_Error
	 */
	public static function redeem( $code, $grant_args ) {
		$code = self::normalize( $code );
		$row  = IAMP_DB::get_code( $code );

		if ( ! $row || 'active' !== $row->status ) {
			IAMP_Log::add( 'code_failed', 0, array( 'kodi' => $code ) );
			return new WP_Error( 'iamp_code', 'Kodi nuk është i vlefshëm.' );
		}
		if ( $row->valid_until && strtotime( $row->valid_until ) < current_time( 'timestamp' ) ) {
			return new WP_Error( 'iamp_code', 'Ky kod ka skaduar.' );
		}
		if ( (int) $row->uses >= (int) $row->max_uses ) {
			return new WP_Error( 'iamp_code', 'Ky kod është përdorur tashmë.' );
		}
		if ( ! IAMP_DB::consume_code( $row->id ) ) {
			return new WP_Error( 'iamp_code', 'Ky kod është përdorur tashmë.' );
		}

		$grant_args['package_id']   = (int) $row->package_id;
		$grant_args['source']       = 'code';
		$grant_args['note']         = 'Kodi: ' . $code;
		$grant_args['external_ref'] = '';

		$res = IAMP_Access::grant( $grant_args );
		if ( is_wp_error( $res ) ) {
			IAMP_DB::release_code( $row->id );
			return $res;
		}

		IAMP_Log::add( 'code_used', $res['user_id'], array( 'kodi' => $code, 'anetaresia' => $res['membership_id'] ) );
		return $res;
	}
}
