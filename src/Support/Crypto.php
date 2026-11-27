<?php
/**
 * Cryptographic Service for HD WC Gallery.
 *
 * Uses libsodium (XChaCha20-Poly1305 / crypto_secretbox) with BLAKE2b key derivation
 * from WordPress authentication salts.
 *
 * @package HDWCGallery\Support
 */

declare(strict_types=1);

namespace HDWCGallery\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private const CONTEXT = 'hdwcg_plugin_encryption_v1';

	/**
	 * Encrypt plaintext using libsodium.
	 *
	 * Output format: base64( nonce [24 bytes] + ciphertext [variable] + auth_tag [16 bytes] )
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string Base64-encoded ciphertext, or empty string on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}

		try {
			$key   = self::deriveKey();
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );

			if ( extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $plaintext );
				sodium_memzero( $key );
			}

			return base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \Throwable ) {
			return '';
		}
	}

	/**
	 * Decrypt ciphertext using libsodium.
	 *
	 * @param string $encoded Base64-encoded payload from encrypt().
	 * @return string Decrypted plaintext, or empty string on failure / tampering.
	 */
	public static function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}

		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		try {
			$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key        = self::deriveKey();

			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

			if ( extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}

			if ( false === $plaintext ) {
				return '';
			}

			return $plaintext;
		} catch ( \Throwable ) {
			return '';
		}
	}

	/**
	 * Derive a 256-bit encryption key from WordPress salts using BLAKE2b.
	 */
	private static function deriveKey(): string {
		$salt = '';
		if ( defined( 'LOGGED_IN_KEY' ) && defined( 'LOGGED_IN_SALT' ) ) {
			$salt = LOGGED_IN_KEY . LOGGED_IN_SALT;
		} elseif ( defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) ) {
			$salt = AUTH_KEY . AUTH_SALT;
		}

		if ( '' === $salt ) {
			$salt = 'hdwcg_fallback_salt_' . ABSPATH;
		}

		return sodium_crypto_generichash( $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
