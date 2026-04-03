<?php
/**
 * Class Iinsight_Logger
 *
 * Writes timestamped entries to a dedicated log file inside the plugin's
 * /logs/ directory — completely separate from WordPress debug.log.
 *
 * @package iinsight-notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Iinsight_Logger {

	private static string $log_file = '';
	private static bool   $enabled  = true;

	const DEBUG   = 'DEBUG';
	const INFO    = 'INFO';
	const WARNING = 'WARNING';
	const ERROR   = 'ERROR';

	// ── Init ──────────────────────────────────────────────────────────────────

	public static function init(): void {
		$log_dir        = IINSIGHT_PLUGIN_DIR . 'logs/';
		self::$log_file = $log_dir . 'iinsight-' . gmdate( 'Y-m' ) . '.log';

		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}
	}

	public static function set_enabled( bool $state ): void {
		self::$enabled = $state;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	public static function debug( string $message, array $context = [] ): void {
		self::write( self::DEBUG, $message, $context );
	}

	public static function info( string $message, array $context = [] ): void {
		self::write( self::INFO, $message, $context );
	}

	public static function warning( string $message, array $context = [] ): void {
		self::write( self::WARNING, $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::write( self::ERROR, $message, $context );
	}

	// ── Core ──────────────────────────────────────────────────────────────────

	private static function write( string $level, string $message, array $context = [] ): void {
		if ( ! self::$enabled ) {
			return;
		}

		if ( empty( self::$log_file ) ) {
			self::init();
		}

		$timestamp   = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$context_str = ! empty( $context ) ? ' | ' . wp_json_encode( $context ) : '';
		$line        = "[{$timestamp}] [{$level}] {$message}{$context_str}" . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( self::$log_file, $line, FILE_APPEND | LOCK_EX );
	}

	// ── Utilities ─────────────────────────────────────────────────────────────

	public static function get_log_contents(): string {
		if ( empty( self::$log_file ) ) {
			self::init();
		}
		if ( ! file_exists( self::$log_file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) file_get_contents( self::$log_file );
	}

	public static function get_log_files(): array {
		$log_dir = IINSIGHT_PLUGIN_DIR . 'logs/';
		$files   = glob( $log_dir . 'iinsight-*.log' );
		return is_array( $files ) ? array_reverse( $files ) : [];
	}

	public static function get_file_contents( string $filename ): string {
		if ( ! preg_match( '/^iinsight-\d{4}-\d{2}\.log$/', $filename ) ) {
			return '';
		}
		$path = IINSIGHT_PLUGIN_DIR . 'logs/' . $filename;
		if ( ! file_exists( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) file_get_contents( $path );
	}

	public static function clear_current_log(): bool {
		if ( empty( self::$log_file ) ) {
			self::init();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return (bool) file_put_contents( self::$log_file, '' );
	}
}

// Init immediately so log_file path is set before any class uses it
Iinsight_Logger::init();
