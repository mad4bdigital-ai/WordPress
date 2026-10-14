<?php
/** Pure, bounded remote image srcset candidate selection. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Remote_Media_Srcset {
	public static function best( $srcset ) {
		$srcset = trim( (string) $srcset );
		if ( '' === $srcset ) return '';
		$best = ''; $best_score = -1.0; $matches = array();
		// Candidate separators commonly contain optional whitespace, while CDN
		// transformation URLs such as Cloudinary legitimately contain commas.
		// Anchor on the required width/density descriptor instead of explode(',').
		if ( preg_match_all( '/(?:^|,\s*)(\S+)\s+(\d+(?:\.\d+)?)([wx])(?=\s*(?:,|$))/', $srcset, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$score = 'x' === $match[3] ? (float) $match[2] * 100000.0 : (float) $match[2];
				if ( $score > $best_score ) { $best = (string) $match[1]; $best_score = $score; }
			}
			if ( '' !== $best ) return $best;
		}
		// A descriptor-less single candidate is valid; multiple ambiguous
		// descriptor-less values are intentionally not guessed.
		return false === strpos( $srcset, ',' ) ? preg_split( '/\s+/', $srcset )[0] : '';
	}

}
