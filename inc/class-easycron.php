<?php
/**
 * Former integration of EasyCron.com (removed in BackWPup 5.6.7).
 *
 * The EasyCron service is discontinued. The class is kept with its public API so existing
 * callers keep working, but it never contacts easycron.com anymore: the old integration sent
 * the job start URL (incl. auth key) and logged-in auth cookies to that third party.
 * Jobs still configured for EasyCron are converted to WP-Cron on plugin update.
 */
class BackWPup_EasyCron {

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Switch a job from EasyCron to WP-Cron and remove local EasyCron data.
	 *
	 * @param int $backwpup_jobid The job ID.
	 */
	public static function update_to_wpcron( $backwpup_jobid ) {
		self::forget( $backwpup_jobid );
		BackWPup_Option::update( $backwpup_jobid, 'activetype', 'wpcron' );
	}

	/**
	 * EasyCron is no longer supported, nothing is created remotely.
	 *
	 * @param int $backwpup_jobid The job ID.
	 *
	 * @return bool Always false.
	 */
	public static function update( $backwpup_jobid ) {
		self::forget( $backwpup_jobid );

		return false;
	}

	/**
	 * Remove local EasyCron data.
	 *
	 * @param int $backwpup_jobid The job ID.
	 *
	 * @return bool
	 */
	public static function delete( $backwpup_jobid ) {
		self::forget( $backwpup_jobid );

		return true;
	}

	/**
	 * EasyCron is no longer supported.
	 *
	 * @param int $backwpup_jobid The job ID.
	 *
	 * @return array Always empty.
	 */
	public static function status( $backwpup_jobid ) {
		return [];
	}

	/**
	 * Delete locally stored EasyCron data of a job (or the global WP-Cron trigger for id 0).
	 *
	 * @param int $backwpup_jobid The job ID.
	 */
	private static function forget( $backwpup_jobid ) {
		if ( empty( $backwpup_jobid ) ) {
			$id = get_site_option( 'backwpup_cfg_easycronjobid' );
			delete_site_option( 'backwpup_cfg_easycronjobid' );
		} else {
			$id = BackWPup_Option::get( $backwpup_jobid, 'easycronjobid' );
			BackWPup_Option::delete( $backwpup_jobid, 'easycronjobid' );
		}

		if ( ! empty( $id ) ) {
			delete_site_transient( 'backwpup_easycron_' . $id );
		}
	}

	/**
	 * Settings form: only shows that the service is discontinued.
	 */
	public function api_key_form() {
		?>
		<h3 class="title"><?php esc_html_e( 'EasyCron', 'backwpup' ); ?></h3>
		<p><?php esc_html_e( 'EasyCron is no longer supported. Use WP-Cron or start jobs with a link instead.', 'backwpup' ); ?></p>
		<?php
	}

	/**
	 * Settings save: remove stored EasyCron credentials.
	 */
	public function api_key_save_form() {
		delete_site_option( 'backwpup_cfg_easycronapikey' );
		self::forget( 0 );
	}
}
