<?php
/**
 * GitHub Token Resolver for HD WC Gallery Auto-Updater.
 *
 * Resolution precedence:
 * 1. Encrypted wp_options value (`_hd_wc_gallery_github_token`) via `Crypto::decrypt()`.
 * 2. Environment/constant fallback (`HDWCG_GITHUB_TOKEN`, `GITHUB_TOKEN`).
 *
 * @package HDWCGallery\Updater
 */

declare(strict_types=1);

namespace HDWCGallery\Updater;

use HDWCGallery\Support\Crypto;

defined( 'ABSPATH' ) || exit;

final class GitHubTokenResolver {

	public const TOKEN_OPTION = '_hd_wc_gallery_github_token';

	private static ?string $lastCiphertext = null;
	private static ?string $lastDecrypted  = null;
	private static ?string $cachedToken    = null;
	private static ?string $cachedSource   = null;
	private static bool $tokenLoaded       = false;

	public static function resolve(): ?string {
		if ( self::$tokenLoaded ) {
			return self::$cachedToken;
		}

		self::$tokenLoaded = true;
		$stored            = self::getStoredToken();
		if ( null !== $stored ) {
			self::$cachedToken  = $stored;
			self::$cachedSource = 'db';
			return self::$cachedToken;
		}

		$env = self::getEnvironmentToken();
		if ( null !== $env ) {
			self::$cachedToken  = $env;
			self::$cachedSource = 'constant';
			return self::$cachedToken;
		}

		self::$cachedToken  = null;
		self::$cachedSource = 'none';

		return null;
	}

	public static function resetCache(): void {
		self::$tokenLoaded    = false;
		self::$cachedToken    = null;
		self::$cachedSource   = null;
		self::$lastCiphertext = null;
		self::$lastDecrypted  = null;
	}

	public static function hasToken(): bool {
		return 'none' !== self::tokenSource();
	}

	/** @return 'db'|'constant'|'none' */
	public static function tokenSource(): string {
		if ( ! self::$tokenLoaded ) {
			self::resolve();
		}

		return self::$cachedSource ?? 'none';
	}

	public static function getEnvironmentToken(): ?string {
		$keys = [ 'HDWCG_GITHUB_TOKEN', 'GITHUB_TOKEN' ];

		foreach ( $keys as $key ) {
			if ( defined( $key ) ) {
				$constVal = constant( $key );
				if ( is_string( $constVal ) && '' !== trim( $constVal ) ) {
					return trim( $constVal );
				}
			}

			if ( function_exists( 'Env\\env' ) ) {
				$bedrockEnv = \Env\env( $key );
				if ( is_string( $bedrockEnv ) && '' !== trim( $bedrockEnv ) ) {
					return trim( $bedrockEnv );
				}
			} elseif ( function_exists( 'env' ) ) {
				$globalEnv = env( $key );
				if ( is_string( $globalEnv ) && '' !== trim( $globalEnv ) ) {
					return trim( $globalEnv );
				}
			}

			if ( isset( $_ENV[ $key ] ) && is_string( $_ENV[ $key ] ) && '' !== trim( $_ENV[ $key ] ) ) {
				return trim( $_ENV[ $key ] );
			}

			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== trim( $_SERVER[ $key ] ) ) {
				return trim( $_SERVER[ $key ] );
			}

			$envVal = getenv( $key );
			if ( is_string( $envVal ) && '' !== trim( $envVal ) ) {
				return trim( $envVal );
			}
		}

		return null;
	}

	private static function getStoredToken(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			self::$lastCiphertext = '';
			self::$lastDecrypted  = null;
			return null;
		}

		if ( $stored === self::$lastCiphertext ) {
			return self::$lastDecrypted;
		}

		$decrypted            = Crypto::decrypt( $stored );
		self::$lastCiphertext = $stored;
		self::$lastDecrypted  = '' !== trim( $decrypted ) ? trim( $decrypted ) : null;

		return self::$lastDecrypted;
	}
}
