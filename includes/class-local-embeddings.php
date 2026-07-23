<?php
/**
 * Local hashing embeddings (no external API).
 *
 * Used when the chat provider (e.g. LongCat) does not expose /v1/embeddings.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic bag-of-tokens hashing into a fixed-size vector.
 */
class WCAI_Local_Embeddings {

	const DIMS = 384;

	/**
	 * Embed a single string.
	 *
	 * @param string $text Text.
	 * @return float[]
	 */
	public static function embed( string $text ): array {
		$text = wp_strip_all_tags( $text );
		$text = strtolower( trim( preg_replace( '/\s+/', ' ', $text ) ?? '' ) );
		$vec  = array_fill( 0, self::DIMS, 0.0 );

		if ( '' === $text ) {
			return $vec;
		}

		$tokens = preg_split( '/[^a-z0-9\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		foreach ( $tokens as $token ) {
			if ( strlen( $token ) < 2 ) {
				continue;
			}
			$h     = self::hash32( $token );
			$idx   = $h % self::DIMS;
			$sign  = ( $h & 1 ) ? 1.0 : -1.0;
			$vec[ $idx ] += $sign;

			// Character bigrams for partial matches (mbstring optional).
			$len = self::str_len( $token );
			for ( $i = 0; $i < $len - 1; $i++ ) {
				$bg  = self::str_sub( $token, $i, 2 );
				$hb  = self::hash32( $bg );
				$ib  = $hb % self::DIMS;
				$sb  = ( $hb & 1 ) ? 0.5 : -0.5;
				$vec[ $ib ] += $sb;
			}
		}

		return self::normalize( $vec );
	}

	/**
	 * Embed many strings.
	 *
	 * @param string[] $texts Texts.
	 * @return array[]
	 */
	public static function embed_batch( array $texts ): array {
		$out = array();
		foreach ( $texts as $text ) {
			$out[] = self::embed( (string) $text );
		}
		return $out;
	}

	/**
	 * Multibyte-safe string length.
	 *
	 * @param string $s Input.
	 * @return int
	 */
	private static function str_len( string $s ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $s );
		}
		return strlen( $s );
	}

	/**
	 * Multibyte-safe substring.
	 *
	 * @param string $s Input.
	 * @param int    $start Start.
	 * @param int    $length Length.
	 * @return string
	 */
	private static function str_sub( string $s, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $s, $start, $length );
		}
		return (string) substr( $s, $start, $length );
	}

	/**
	 * FNV-1a style 32-bit hash.
	 *
	 * @param string $s Input.
	 * @return int
	 */
	private static function hash32( string $s ): int {
		$h = 2166136261;
		$len = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$h ^= ord( $s[ $i ] );
			$h = ( $h * 16777619 ) & 0xffffffff;
		}
		return $h;
	}

	/**
	 * L2-normalize a vector.
	 *
	 * @param float[] $vec Vector.
	 * @return float[]
	 */
	private static function normalize( array $vec ): array {
		$sum = 0.0;
		foreach ( $vec as $v ) {
			$sum += $v * $v;
		}
		if ( $sum <= 0.0 ) {
			return $vec;
		}
		$norm = sqrt( $sum );
		foreach ( $vec as $i => $v ) {
			$vec[ $i ] = $v / $norm;
		}
		return $vec;
	}
}
