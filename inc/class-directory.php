<?php

/**
 * Wraps directory functions in PHP.
 *
 * @since 3.4.0
 */
class BackWPup_Directory extends DirectoryIterator {


	/**
	 * The folders list of the plugins to auto exclude
	 *
	 * @var array
	 */
	private static $auto_exclusion_plugins_folders = [];

	/**
	 * The cache folder list of the plugins to auto exclude
	 *
	 * @var array
	 */
	private static $auto_exclusion_plugin_cache_folders = [];

	/**
	 * Flag to check if auto exclusion folders are initialized
	 *
	 * @var bool
	 */
	private static $auto_exclusion_initialized = false;

	/**
	 * Creates the iterator.
	 *
	 * Fixes the path before calling the parent constructor.
	 *
	 * @param string $path
	 */
	public function __construct($path)
	{
		parent::__construct(BackWPup_Path_Fixer::fix_path($path));
	}

	/**
	 * Override next to skip auto excluded (plugin cache) folders.
	 *
	 * @return void
	 */
	public function next(): void {
		parent::next();
		while ( $this->valid() && $this->should_skip() ) {
			parent::next();
		}
	}

	/**
	 * Override rewind to skip auto excluded (plugin cache) folders.
	 *
	 * @return void
	 */
	public function rewind(): void {
		parent::rewind();
		while ( $this->valid() && $this->should_skip() ) {
			parent::next();
		}
	}

	/**
	 * Check if the current item should be skipped.
	 *
	 * @return bool
	 */
	private function should_skip(): bool {
		$item = parent::current();

		if ( $item->isDot() || ! $item->isDir() ) {
			return false;
		}

		self::init_auto_exclusion_folders();

		$pathname = self::sanitize_path( $item->getPathname() );

		return isset( self::$auto_exclusion_plugin_cache_folders[ $pathname ] );
	}

	/**
	 * Get the folders of the excluded plugins
	 *
	 * @return array
	 */
	public static function get_auto_exclusion_plugins_folders(): array {
		self::init_auto_exclusion_folders();

		return array_keys( self::$auto_exclusion_plugins_folders );
	}

	/**
	 * Get the cache folders of the excluded plugins
	 *
	 * @return array
	 */
	public static function get_auto_exclusion_plugin_cache_folders(): array {
		self::init_auto_exclusion_folders();

		return array_keys( self::$auto_exclusion_plugin_cache_folders );
	}

	/**
	 * Normalize a path to forward slashes with a trailing slash.
	 *
	 * @param string $path The path to sanitize.
	 *
	 * @return string
	 */
	private static function sanitize_path( $path ) {
		return trailingslashit( BackWPup_Path_Fixer::slashify( $path ) );
	}

	/**
	 * Init the excluded folders
	 *
	 * Runs only once per request, filters are not re-applied on every iteration.
	 *
	 * @return void
	 */
	private static function init_auto_exclusion_folders() {
		if ( self::$auto_exclusion_initialized ) {
			return;
		}

		/**
		 * Filter whether BackWPup will list the plugins in the excluded plugins list.
		 *
		 * @param array $excluded_folders List of excluded paths.
		 */
		$auto_exclusion_plugins_folders = apply_filters( 'backwpup_exclusion_plugins_folders', [] );
		/**
		 * Filter whether BackWPup will list the cache folders to include in the backup.
		 *
		 * @param array $excluded_folders List of excluded paths.
		 */
		$auto_exclusion_plugins_cache_folders = apply_filters( 'backwpup_exclusion_plugins_cache_folders', [] );
		$auto_exclusion_plugins_folders       = ( ! is_array( $auto_exclusion_plugins_folders ) ? [] : $auto_exclusion_plugins_folders );
		$auto_exclusion_plugins_cache_folders = ( ! is_array( $auto_exclusion_plugins_cache_folders ) ? [] : $auto_exclusion_plugins_cache_folders );

		self::$auto_exclusion_plugins_folders      = [];
		self::$auto_exclusion_plugin_cache_folders = [];

		foreach ( $auto_exclusion_plugins_folders as $folder ) {
			if ( is_string( $folder ) && '' !== $folder ) {
				self::$auto_exclusion_plugins_folders[ self::sanitize_path( $folder ) ] = true;
			}
		}

		foreach ( $auto_exclusion_plugins_cache_folders as $folder ) {
			if ( is_string( $folder ) && '' !== $folder ) {
				self::$auto_exclusion_plugin_cache_folders[ self::sanitize_path( $folder ) ] = true;
			}
		}

		self::$auto_exclusion_initialized = true;
	}
}
