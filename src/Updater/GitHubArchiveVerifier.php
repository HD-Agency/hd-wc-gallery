<?php
/**
 * GitHub Release Archive Downloader, Strict Redirect Guard & Package Integrity Verifier.
 *
 * @package HDWCGallery\Updater
 */

declare(strict_types=1);

namespace HDWCGallery\Updater;

use WP_Error;
use WpOrg\Requests\Exception as RequestsException;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

final class GitHubArchiveVerifier {

	private const REPO_PATH             = 'hd-agency/hd-wc-gallery';
	private const MAX_DOWNLOAD_ATTEMPTS = 3;
	private const MIN_ARCHIVE_BYTES     = 10240;

	/** @var list<string> Required relative files inside an extracted `hd-wc-gallery` package. */
	private const REQUIRED_PACKAGE_FILES = [
		'hd-wc-gallery.php',
		'src/Plugin.php',
		'vendor/autoload.php',
	];

	public static function isAwsS3Host( string $host ): bool {
		$clean = strtolower( trim( $host, '.' ) );

		return 's3.amazonaws.com' === $clean
			|| 1 === preg_match( '/\.s3(?:\.[a-z0-9-]+)?\.amazonaws\.com$/', $clean );
	}

	public static function isTrustedExternalHost( string $host ): bool {
		$clean = strtolower( trim( $host, '.' ) );
		if ( '' === $clean ) {
			return false;
		}

		return in_array( $clean, [ 'api.github.com', 'codeload.github.com', 'github.com' ], true )
			|| str_ends_with( $clean, '.github.com' )
			|| str_ends_with( $clean, '.githubusercontent.com' )
			|| self::isAwsS3Host( $clean );
	}

	public static function isTrustedPackageUrl( string $url, bool $allowCdn = true ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parsed['host'] ?? '' ), '.' ) );
		$path   = strtolower( (string) ( $parsed['path'] ?? '' ) );

		if ( 'https' !== $scheme || ! self::isTrustedExternalHost( $host ) ) {
			return false;
		}

		if ( 'api.github.com' === $host ) {
			return str_starts_with( $path, '/repos/' . self::REPO_PATH . '/' ) || '/repos/' . self::REPO_PATH === $path;
		}

		if ( 'codeload.github.com' === $host || 'github.com' === $host || str_ends_with( $host, '.github.com' ) ) {
			return str_starts_with( $path, '/' . self::REPO_PATH . '/' ) || '/' . self::REPO_PATH === $path;
		}

		return $allowCdn && ( str_ends_with( $host, '.githubusercontent.com' ) || self::isAwsS3Host( $host ) );
	}

	public static function filterExternalHosts( mixed $isExternal, mixed $host ): bool {
		return ( is_string( $host ) && '' !== $host && self::isTrustedExternalHost( $host ) ) ? true : (bool) $isExternal;
	}

	public static function filterTrustedHttpRequestArgs( mixed $args, mixed $url ): mixed {
		if ( is_array( $args ) && is_string( $url ) && '' !== $url && self::isTrustedPackageUrl( $url, false ) ) {
			$args['reject_unsafe_urls'] = false;
		}

		return $args;
	}

	/** @throws RequestsException When redirect URL leaves trusted GitHub/CDN domains. */
	public static function filterRequestsRedirect( mixed &$location, mixed &$headers = [] ): void {
		if ( ! is_string( $location ) || ! self::isTrustedPackageUrl( $location, true ) ) {
			if ( class_exists( RequestsException::class ) ) {
				throw new RequestsException( 'HD WC Gallery update aborted: Untrusted redirect package URL.', 'hdwcg_untrusted_redirect' );
			}
			$location = '';
			return;
		}
		if ( ! is_array( $headers ) ) {
			return;
		}

		$parsedUrl     = wp_parse_url( $location );
		$host          = strtolower( (string) ( $parsedUrl['host'] ?? '' ) );
		$query         = (string) ( $parsedUrl['query'] ?? '' );
		$isCdnOrSigned = self::isAwsS3Host( $host )
			|| str_ends_with( $host, '.githubusercontent.com' )
			|| str_contains( $query, 'AWSAccessKeyId=' )
			|| str_contains( $query, 'X-Amz-Signature=' )
			|| str_starts_with( $query, 'token=' )
			|| str_contains( $query, '&token=' );

		if ( ! $isCdnOrSigned ) {
			return;
		}

		foreach ( array_keys( $headers ) as $headerKey ) {
			if ( 'authorization' === strtolower( (string) $headerKey ) ) {
				$authVal = is_string( $headers[ $headerKey ] ) ? $headers[ $headerKey ] : '';
				if ( ! str_starts_with( $authVal, 'AWS4-HMAC-SHA256' ) ) {
					unset( $headers[ $headerKey ] );
				}
			}
		}
	}

	public static function isPluginUpgrade( string $source, mixed $upgrader, mixed $hookExtra, ?object $checker = null ): bool {
		$pluginBasename = defined( 'HD_WC_GALLERY_PLUGIN_BASENAME' ) ? HD_WC_GALLERY_PLUGIN_BASENAME : GitHubUpdater::PLUGIN_BASENAME;

		if ( is_array( $hookExtra ) ) {
			if ( isset( $hookExtra['theme'] ) ) {
				return false;
			}
			if ( isset( $hookExtra['plugin'] ) ) {
				return $pluginBasename === $hookExtra['plugin'];
			}
		}

		if ( isset( $upgrader->skin->plugin ) && is_string( $upgrader->skin->plugin ) && '' !== $upgrader->skin->plugin ) {
			return $pluginBasename === $upgrader->skin->plugin;
		}

		if ( null !== $checker && is_object( $upgrader ) && method_exists( $checker, 'isBeingUpgraded' ) && $checker->isBeingUpgraded( $upgrader ) ) {
			return true;
		}

		$baseName = strtolower( basename( rtrim( $source, '/\\' ) ) );
		if ( 'hd-wc-gallery' === $baseName || str_starts_with( $baseName, 'hd-agency-hd-wc-gallery-' ) ) {
			return true;
		}

		return str_contains( strtolower( wp_normalize_path( $source ) ), self::REPO_PATH . '/' );
	}

	public static function normalizeSourceDirectory( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null, ?object $checker = null ): string|WP_Error {
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error( 'hdwcg_invalid_source', __( 'Invalid update source path.', 'hd-wc-gallery' ) );
		}
		if ( ! is_string( $remoteSource ) || '' === $remoteSource || ! self::isPluginUpgrade( $source, $upgrader, $hookExtra, $checker ) ) {
			return $source;
		}

		$correctedSource = trailingslashit( $remoteSource ) . 'hd-wc-gallery/';
		if ( trailingslashit( $source ) === $correctedSource ) {
			return $source;
		}

		if ( ! function_exists( 'move_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$result = move_dir( $source, $correctedSource, true );
		return is_wp_error( $result ) ? $result : $correctedSource;
	}

	public static function validatePackageIntegrity( mixed $source, mixed $upgrader, mixed $hookExtra = null, ?object $checker = null ): string|WP_Error {
		global $wp_filesystem;

		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error( 'hdwcg_invalid_source', __( 'Invalid update source path.', 'hd-wc-gallery' ) );
		}
		if ( ! isset( $upgrader, $wp_filesystem ) || ! is_object( $wp_filesystem ) || ! self::isPluginUpgrade( $source, $upgrader, $hookExtra, $checker ) ) {
			return $source;
		}

		$sourceDir = trailingslashit( $source );
		$missing   = [];
		foreach ( self::REQUIRED_PACKAGE_FILES as $relPath ) {
			$exists = method_exists( $wp_filesystem, 'exists' )
				? (bool) $wp_filesystem->exists( $sourceDir . $relPath )
				: is_file( $sourceDir . $relPath );
			if ( ! $exists ) {
				$missing[] = $relPath;
			}
		}

		if ( [] !== $missing ) {
			/* translators: %s: Comma-separated list of missing files */
			return new WP_Error( 'hdwcg_corrupted_package', sprintf( __( 'HD WC Gallery update aborted: The downloaded package is incomplete (missing: %s). The existing plugin installation was preserved.', 'hd-wc-gallery' ), implode( ', ', $missing ) ) );
		}

		return $source;
	}

	public static function validateDownloadArchive( mixed $reply, mixed $package = '', mixed $upgrader = null, mixed $hookExtra = [], ?object $checker = null ): mixed {
		$hookExtra = (array) $hookExtra;
		if ( is_wp_error( $reply ) || ! is_string( $package ) || '' === $package || ! self::isPluginUpgrade( $package, $upgrader, $hookExtra, $checker ) ) {
			return $reply;
		}

		self::cleanStaleTempArchives();

		if ( is_string( $reply ) && '' !== $reply && file_exists( $reply ) ) {
			$check = self::verifyArchiveIntegrity( $reply );
			if ( is_wp_error( $check ) ) {
				self::deleteIfTempFile( $reply );
				return $check;
			}
			return $reply;
		}

		if ( ! preg_match( '!^(http|https|ftp)://!i', $package ) && file_exists( $package ) ) {
			$check = self::verifyArchiveIntegrity( $package );
			if ( is_wp_error( $check ) ) {
				self::deleteIfTempFile( $package );
				return $check;
			}
			return $package;
		}

		if ( ! self::isTrustedPackageUrl( $package, false ) ) {
			return new WP_Error( 'hdwcg_untrusted_package_url', __( 'HD WC Gallery update aborted: Untrusted or non-HTTPS package URL.', 'hd-wc-gallery' ) );
		}

		$token = GitHubTokenResolver::resolve();
		if ( null === $token || '' === $token ) {
			return new WP_Error( 'hdwcg_missing_token', __( 'HD WC Gallery update aborted: GitHub authentication token is missing. Auto-update is disabled.', 'hd-wc-gallery' ) );
		}

		return self::downloadArchiveToTempFile( $package, $token );
	}

	public static function cleanStaleTempArchives( int $maxAgeSeconds = 300 ): void {
		$tempDir = trailingslashit( get_temp_dir() );
		if ( ! is_dir( $tempDir ) || ! is_readable( $tempDir ) ) {
			return;
		}

		$now = time();
		foreach ( [ 'hd-agency-hd-wc-gallery-*.zip', 'hd-wc-gallery-package-*.tmp', 'hd-wc-gallery-package-*.zip' ] as $pattern ) {
			$files = glob( $tempDir . $pattern );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( is_file( $file ) && wp_is_writable( $file ) ) {
					$mtime = filemtime( $file );
					if ( false !== $mtime && ( $now - $mtime ) >= $maxAgeSeconds ) {
						wp_delete_file( $file );
					}
				}
			}
		}
	}

	private static function downloadArchiveToTempFile( string $package, #[\SensitiveParameter] string $token ): string|WP_Error {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$userAgent = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();
		$lastError = null;

		add_filter( 'http_request_host_is_external', [ self::class, 'filterExternalHosts' ], 10, 2 );
		add_action( 'requests-requests.before_redirect', [ self::class, 'filterRequestsRedirect' ], 10, 2 );

		try {
			for ( $attempt = 1; $attempt <= self::MAX_DOWNLOAD_ATTEMPTS; $attempt++ ) {
				$downloadUrl = $package;
				$parsedPkg   = wp_parse_url( $package );
				$pkgHost     = strtolower( (string) ( $parsedPkg['host'] ?? '' ) );
				$pkgPath     = strtolower( (string) ( $parsedPkg['path'] ?? '' ) );

				if ( 'api.github.com' === $pkgHost ) {
					$probe = wp_remote_get(
						$package,
						[
							'timeout'            => 15,
							'redirection'        => 0,
							'stream'             => false,
							'reject_unsafe_urls' => false,
							'headers'            => [
								'Accept'          => str_contains( $pkgPath, '/releases/assets/' ) ? 'application/octet-stream' : 'application/vnd.github+json',
								'Accept-Encoding' => 'identity',
								'User-Agent'      => $userAgent,
								'Authorization'   => 'Bearer ' . $token,
							],
						]
					);

					if ( ! is_wp_error( $probe ) ) {
						$probeCode = (int) wp_remote_retrieve_response_code( $probe );
						if ( in_array( $probeCode, [ 401, 403, 404 ], true ) ) {
							/* translators: %d: HTTP status code */
							return new WP_Error( 'hdwcg_download_http_error', sprintf( __( 'HD WC Gallery update aborted: GitHub returned HTTP %d when downloading the package.', 'hd-wc-gallery' ), $probeCode ) );
						}

						$location = (string) wp_remote_retrieve_header( $probe, 'location' );
						if ( in_array( $probeCode, [ 301, 302, 303, 307, 308 ], true ) && '' !== $location ) {
							if ( ! self::isTrustedPackageUrl( $location, true ) ) {
								return new WP_Error( 'hdwcg_untrusted_package_url', __( 'HD WC Gallery update aborted: Untrusted redirect package URL.', 'hd-wc-gallery' ) );
							}
							$downloadUrl = $location;
						}
					}
				}

				$parsedDl            = wp_parse_url( $downloadUrl );
				$dlPath              = strtolower( (string) ( $parsedDl['path'] ?? '' ) );
				$dlQuery             = (string) ( $parsedDl['query'] ?? '' );
				$hasSignedQueryToken = str_starts_with( $dlQuery, 'token=' )
					|| str_contains( $dlQuery, '&token=' )
					|| str_contains( $dlQuery, 'AWSAccessKeyId=' )
					|| str_contains( $dlQuery, 'X-Amz-Signature=' );

				$dlHeaders = [
					'Accept'          => str_contains( $dlPath, '/releases/assets/' ) ? 'application/octet-stream' : 'application/zip, application/octet-stream, */*',
					'Accept-Encoding' => 'identity',
					'User-Agent'      => $userAgent,
				];
				if ( ! $hasSignedQueryToken && self::isTrustedPackageUrl( $downloadUrl, false ) ) {
					$dlHeaders['Authorization'] = 'Bearer ' . $token;
				}

				$tmpFile = wp_tempnam( 'hd-wc-gallery-package.zip' );
				if ( ! $tmpFile ) {
					return new WP_Error( 'hdwcg_temp_failed', __( 'HD WC Gallery update aborted: Could not create temporary file for package download.', 'hd-wc-gallery' ) );
				}

				$response = wp_remote_get(
					$downloadUrl,
					[
						'timeout'            => 300,
						'redirection'        => 5,
						'stream'             => true,
						'filename'           => $tmpFile,
						'reject_unsafe_urls' => ! self::isTrustedPackageUrl( $downloadUrl, false ),
						'headers'            => $dlHeaders,
					]
				);

				if ( is_wp_error( $response ) ) {
					is_file( $tmpFile ) && wp_delete_file( $tmpFile );
					$lastError = $response;
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( 200 !== $code ) {
					is_file( $tmpFile ) && wp_delete_file( $tmpFile );
					/* translators: 1: HTTP status code, 2: HTTP status message */
					$lastError = new WP_Error( 'hdwcg_download_http_error', sprintf( __( 'HD WC Gallery update aborted: GitHub returned HTTP %1$d (%2$s).', 'hd-wc-gallery' ), $code, wp_remote_retrieve_response_message( $response ) ) );
					if ( in_array( $code, [ 401, 403, 404 ], true ) ) {
						return $lastError;
					}
					continue;
				}

				$contentType = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
				if ( str_starts_with( $contentType, 'text/html' ) || str_starts_with( $contentType, 'application/json' ) ) {
					is_file( $tmpFile ) && wp_delete_file( $tmpFile );
					/* translators: %s: HTTP Content-Type header */
					$lastError = new WP_Error( 'hdwcg_invalid_archive_signature', sprintf( __( 'HD WC Gallery update aborted: The downloaded package is not a valid ZIP archive (missing PK magic header) [Content-Type: %s].', 'hd-wc-gallery' ), $contentType ) );
					continue;
				}

				$check = self::verifyArchiveIntegrity( $tmpFile );
				if ( is_wp_error( $check ) ) {
					is_file( $tmpFile ) && wp_delete_file( $tmpFile );
					$lastError = $check;
					continue;
				}

				return $tmpFile;
			}
		} finally {
			remove_action( 'requests-requests.before_redirect', [ self::class, 'filterRequestsRedirect' ], 10 );
			remove_filter( 'http_request_host_is_external', [ self::class, 'filterExternalHosts' ], 10 );
		}

		return $lastError instanceof WP_Error
			? $lastError
			: new WP_Error( 'hdwcg_download_failed', __( 'HD WC Gallery update aborted: Package download failed.', 'hd-wc-gallery' ) );
	}

	public static function verifyArchiveIntegrity( string $filePath ): true|WP_Error {
		clearstatcache( true, $filePath );
		if ( ! is_file( $filePath ) ) {
			return new WP_Error( 'hdwcg_archive_missing', __( 'The update package file does not exist.', 'hd-wc-gallery' ) );
		}
		$size = filesize( $filePath );
		if ( false === $size || $size < self::MIN_ARCHIVE_BYTES ) {
			/* translators: %d: File size in bytes */
			return new WP_Error( 'hdwcg_archive_truncated', sprintf( __( 'HD WC Gallery update aborted: The downloaded package is truncated or invalid (%d bytes).', 'hd-wc-gallery' ), (int) $size ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = is_readable( $filePath ) ? fopen( $filePath, 'rb' ) : false;
		if ( ! is_resource( $fp ) ) {
			return new WP_Error( 'hdwcg_archive_unreadable', __( 'HD WC Gallery update aborted: Package file is not readable for verification.', 'hd-wc-gallery' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$head       = (string) fread( $fp, 64 );
		$tailLength = min( (int) $size, 65557 );
		$hasEocd    = false;
		if ( 0 === fseek( $fp, (int) $size - $tailLength, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$tail    = fread( $fp, $tailLength );
			$eocdPos = is_string( $tail ) ? strrpos( $tail, "\x50\x4b\x05\x06" ) : false;
			$hasEocd = ( false !== $eocdPos && ( strlen( $tail ) - $eocdPos ) >= 22 );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		if ( ! str_starts_with( $head, "\x50\x4b\x03\x04" ) ) {
			$trimmed = ltrim( $head );
			$hint    = str_starts_with( strtolower( $trimmed ), '<!doctype' ) || str_starts_with( strtolower( $trimmed ), '<html' )
				? ' [detected: HTML document]'
				: ( str_starts_with( $trimmed, '{' ) ? ' [detected: JSON response]' : '' );
			return new WP_Error( 'hdwcg_invalid_archive_signature', __( 'HD WC Gallery update aborted: The downloaded package is not a valid ZIP archive (missing PK magic header).', 'hd-wc-gallery' ) . $hint );
		}
		if ( ! $hasEocd ) {
			return new WP_Error( 'hdwcg_archive_truncated', __( 'HD WC Gallery update aborted: The downloaded ZIP archive is incomplete (missing End of Central Directory record).', 'hd-wc-gallery' ) );
		}
		if ( class_exists( ZipArchive::class ) ) {
			$zip        = new ZipArchive();
			$openResult = $zip->open( $filePath, ZipArchive::CHECKCONS );
			if ( true !== $openResult || $zip->numFiles <= 0 ) {
				if ( true === $openResult ) {
					$zip->close();
				}
				/* translators: %s: ZipArchive error code */
				return new WP_Error( 'hdwcg_invalid_archive_structure', sprintf( __( 'HD WC Gallery update aborted: The downloaded ZIP archive failed consistency verification (code: %s).', 'hd-wc-gallery' ), (string) $openResult ) );
			}
			$zip->close();
		}
		return true;
	}

	private static function deleteIfTempFile( string $filePath ): void {
		$normalizedFile = wp_normalize_path( $filePath );
		$normalizedTemp = trailingslashit( wp_normalize_path( get_temp_dir() ) );
		if ( str_starts_with( $normalizedFile, $normalizedTemp ) && is_file( $filePath ) ) {
			wp_delete_file( $filePath );
		}
	}
}
