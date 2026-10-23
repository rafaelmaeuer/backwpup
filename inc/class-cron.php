<?php

/**
 * Class for BackWPup cron methods.
 */
class BackWPup_Cron
{
    /**
     * Callback of the 'backwpup_cron' WP-Cron hook.
     *
     * Never reads request data: the authority to start a job comes from WordPress
     * having fired a due scheduled event, not from the current request.
     *
     * @param string $arg
     */
    public static function run($arg = 'restart')
    {
        if (!is_main_site(get_current_blog_id())) {
            return;
        }

        if ($arg === 'restart') {
            //reschedule restart
            wp_schedule_single_event(time() + 60, 'backwpup_cron', ['arg' => 'restart']);
            //restart job if not working or a restart imitated
            self::resume_scheduled_restart();

            return;
        }

        $arg = is_numeric($arg) ? abs((int) $arg) : 0;
        if (!$arg) {
            return;
        }

        //check that job exits
        $jobids = BackWPup_Option::get_job_ids('activetype', 'wpcron');
        if (!in_array($arg, $jobids, true)) {
            return;
        }

        // Clear all (possibly duplicated) events of this job before rescheduling.
        wp_clear_scheduled_hook('backwpup_cron', ['arg' => $arg]);

        //delay other job start for 5-7 minutes if already one is running
        $job_object = BackWPup_Job::get_working_data();
        if ($job_object) {
            if (isset($job_object->job['jobid']) && (int) $job_object->job['jobid'] === $arg) {
                // Same job is still running: skip this run, schedule the next regular one.
                wp_schedule_single_event(self::cron_next(BackWPup_Option::get($arg, 'cron')), 'backwpup_cron', ['arg' => $arg]);
            } else {
                wp_schedule_single_event(time() + 300 + random_int(0, 120), 'backwpup_cron', ['arg' => $arg]);
            }

            return;
        }

        //reschedule next job run
        $cron_next = self::cron_next(BackWPup_Option::get($arg, 'cron'));
        wp_schedule_single_event($cron_next, 'backwpup_cron', ['arg' => $arg]);

        //start job
        self::start_scheduled_job($arg);
    }

    /**
     * Check Jobs worked and Cleanup logs and so on.
     */
    public static function check_cleanup()
    {
        $job_object = BackWPup_Job::get_working_data();
        $log_folder = get_site_option('backwpup_cfg_logfolder');
        $log_folder = BackWPup_File::get_absolute_path($log_folder);

        // check aborted jobs for longer than a tow hours, abort them courtly and send mail
        if (is_object($job_object) && !empty($job_object->logfile)) {
            $not_worked_time = microtime(true) - $job_object->timestamp_last_update;
            if ($not_worked_time > 3600) {
                $job_object->log(
                    E_USER_ERROR,
                    __('Aborted, because no progress for one hour!', 'backwpup'),
                    __FILE__,
                    __LINE__
                );
                $running_file = BackWPup::get_plugin_data('running_file');
                if (file_exists($running_file)) {
                    @unlink($running_file);
                }
                $job_object->update_working_data();
            }
        }

		/**
		 * Filter whether BackWPup will compress logs or not.
		 *
		 * @param bool $log_compress Whether the logs will be compressed or not.
		 */
		$log_compress = (bool) apply_filters( 'backwpup_gz_logs', (bool) get_site_option( 'backwpup_cfg_gzlogs' ) );
		// Compress not compressed logs.
		if (
			is_readable( $log_folder ) &&
			function_exists( 'gzopen' ) &&
			$log_compress &&
			! is_object( $job_object )
		) {
			// Compress old not compressed logs.
			try {
                $dir = new BackWPup_Directory($log_folder);

                $jobids = BackWPup_Option::get_job_ids();

                foreach ($dir as $file) {
                    if ($file->isWritable() && '.html' === substr($file->getFilename(), -5)) {
                        $compress = new BackWPup_Create_Archive($file->getPathname() . '.gz');
                        if ($compress->add_file($file->getPathname())) {
                            unlink($file->getPathname());
                            //change last logfile in jobs
                            foreach ($jobids as $jobid) {
                                $job_logfile = BackWPup_Option::get($jobid, 'logfile');
                                if (!empty($job_logfile) && $job_logfile === $file->getPathname()) {
                                    BackWPup_Option::update($jobid, 'logfile', $file->getPathname() . '.gz');
                                }
                            }
                        }
                        $compress->close();
                        unset($compress);
                    }
                }
            } catch (UnexpectedValueException $e) {
                // No job object exists in this branch (only runs when no job is working), so it can't be logged to a job log.
                trigger_error(
                    esc_html(sprintf(__('Could not open path: %s', 'backwpup'), $e->getMessage())),
                    E_USER_WARNING
                );
            }
        }

        //Jobs cleanings
        if (!$job_object) {
            //remove restart cron
            wp_clear_scheduled_hook('backwpup_cron', ['arg' => 'restart']);
            //temp cleanup
            BackWPup_Job::clean_temp_folder();
        }

        // Sweep and clean up any abandoned restore working directory (Restore module owns the logic).
        $restore_cleanup = 'Inpsyde\BackWPup\Infrastructure\Restore\maybe_cleanup_abandoned_restore';
        if (!function_exists($restore_cleanup)) {
            $restore_commons = untrailingslashit(BackWPup::get_plugin_data('plugindir')) . '/src/Infrastructure/Restore/commons.php';
            if (is_readable($restore_commons)) {
                require_once $restore_commons;
            }
        }
        if (function_exists($restore_cleanup)) {
            call_user_func($restore_cleanup);
        }

        //check scheduling jobs that not found will removed because there are single scheduled
        $activejobs = BackWPup_Option::get_job_ids('activetype', 'wpcron');

        foreach ($activejobs as $jobid) {
            $cron_next = wp_next_scheduled('backwpup_cron', ['arg' => $jobid]);
            if (!$cron_next || $cron_next < time()) {
                wp_clear_scheduled_hook('backwpup_cron', ['arg' => $jobid]);
                $cron_next = BackWPup_Cron::cron_next(BackWPup_Option::get($jobid, 'cron'));
                wp_schedule_single_event($cron_next, 'backwpup_cron', ['arg' => $jobid]);
            }
        }
    }

    /**
     * Send the response headers used for every wp-cron.php triggered job dispatch.
     */
    private static function send_job_run_headers()
    {
        if (function_exists('session_status') && PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'), true);
            header('X-Robots-Tag: noindex, nofollow', true);
        }
        nocache_headers();
    }

    /**
     * Restart the currently working job when it is missing, stalled or not running.
     */
    private static function resume_working_job()
    {
        $job_object = BackWPup_Job::get_working_data();
        // Restart if cannot find job
        if (!$job_object) {
            BackWPup_Job::start_http('restart');

            return;
        }
        //restart job if not working or a restart wished
        $not_worked_time = microtime(true) - $job_object->timestamp_last_update;
        if (!$job_object->pid || $not_worked_time > 300) {
            BackWPup_Job::start_http('restart');
        }
    }

    /**
     * Resume the scheduled restart check. Called in-process by self::run(), never reads request data.
     */
    private static function resume_scheduled_restart()
    {
        if (!wp_doing_cron()) {
            return;
        }

        self::send_job_run_headers();
        self::resume_working_job();
    }

    /**
     * Start a scheduled job. This is the only producer of the 'cronrun' start type.
     *
     * Called in-process by self::run() (the 'backwpup_cron' WP-Cron hook callback) and
     * must never read $_GET/$_POST.
     *
     * @param int $jobid
     */
    private static function start_scheduled_job($jobid)
    {
        if (!wp_doing_cron()) {
            return;
        }

        $jobid = (int) $jobid;
        if ($jobid < 1 || BackWPup_Option::get($jobid, 'activetype') !== 'wpcron') {
            return;
        }

        self::send_job_run_headers();
        BackWPup_Job::start_http('cronrun', $jobid);
    }

    /**
     * Start a job for the HTTP-triggered run types read from the current request
     * (wp-cron.php?backwpup_run=...).
     *
     * 'cronrun' is NOT accepted here: it is dispatched in-process by self::run() only.
     *
     * @param array $args
     */
    public static function cron_active($args = [])
    {
        //only if cron active
        if (!wp_doing_cron()) {
            return;
        }

        if (!is_array($args)) {
            $args = [];
        }

        if (isset($_GET['backwpup_run'])) {
            $args['run'] = sanitize_text_field(wp_unslash((string) $_GET['backwpup_run']));
        }

        if (isset($_GET['_nonce'])) {
            $args['nonce'] = sanitize_text_field(wp_unslash((string) $_GET['_nonce']));
        }

        if (isset($_GET['jobid'])) {
            $args['jobid'] = absint($_GET['jobid']);
        }

        $args = array_merge(
            [
                'run' => '',
                'nonce' => '',
                'jobid' => 0,
            ],
            $args
        );

        // 'cronrun' must never be accepted from a request (unauthenticated job start).
        if (!in_array(
            $args['run'],
            ['test', 'restart', 'runnow', 'runnowalt', 'runext'],
            true
        )) {
            return;
        }

        self::send_job_run_headers();

        //on test die for fast feedback
        if ($args['run'] === 'test') {
            exit('BackWPup test request');
        }

        if ($args['run'] === 'restart') {
            self::resume_working_job();

            return;
        }

        if ($args['run'] === 'runext') {
            // Special nonce on external start: md5( authkey . jobid ), legacy links use the plain auth key.
            $authkey = (string) get_site_option('backwpup_cfg_jobrunauthkey');
            if ($authkey === '' || $args['nonce'] === '') {
                return;
            }
            if (!hash_equals(md5($authkey . (int) $args['jobid']), (string) $args['nonce'])
                && !hash_equals($authkey, (string) $args['nonce'])
            ) {
                return;
            }

            //check runext is allowed for job
            $should_continue = BackWPup_Option::get((int) $args['jobid'], 'activetype', '') === 'link';

            /**
             * Filter whether BackWPup will allow to start a job with links or not.
             *
             * @param bool  $should_continue True if the job's activetype is "link".
             * @param array $args            Job args array.
             */
            if (!apply_filters('backwpup_allow_job_start_with_links', $should_continue, $args)) {
                return;
            }
        } else {
            // generate normal nonce
            $nonce = substr(wp_hash(wp_nonce_tick() . 'backwpup_job_run-' . $args['run'], 'nonce'), -12, 10);
            // check nonce
            if (!hash_equals($nonce, (string) $args['nonce'])) {
                return;
            }
        }

        //run BackWPup job
        BackWPup_Job::start_http($args['run'], (int) $args['jobid']);
    }

    /**
     * Pre-authenticate the configured WordPress user for external link (runext) job starts.
     *
     * Plugins restricting wp-cron.php to a specific user would otherwise kill the request on init,
     * before cron_active() runs on wp_loaded. The runext nonce is validated before the user is set.
     */
    public static function preauth_for_external_run(): void
    {
        $run_type = isset($_GET['backwpup_run']) ? sanitize_key($_GET['backwpup_run']) : '';
        if ($run_type !== 'runext') {
            return;
        }

        $authentication = get_site_option('backwpup_cfg_authentication', ['method' => '', 'user_id' => 0]);
        if (empty($authentication['user_id']) || $authentication['method'] !== 'user') {
            return;
        }

        $authkey = (string) get_site_option('backwpup_cfg_jobrunauthkey');
        $job_id = isset($_GET['jobid']) ? absint($_GET['jobid']) : 0;
        $provided_nonce = isset($_GET['_nonce']) ? sanitize_text_field(wp_unslash($_GET['_nonce'])) : '';
        if ($authkey === '' || $provided_nonce === '') {
            return;
        }

        if (!hash_equals(md5($authkey . $job_id), $provided_nonce) && !hash_equals($authkey, $provided_nonce)) {
            return;
        }

        wp_set_current_user((int) $authentication['user_id']);
    }

    /**
     * Inject the configured user's auth cookie into WordPress's own wp-cron loopback request,
     * so sites restricting wp-cron.php to a user still run scheduled BackWPup jobs.
     *
     * @param array $cron_request
     *
     * @return array
     */
    public static function authenticate_cron_request($cron_request)
    {
        if (!is_array($cron_request) || !empty($cron_request['args']['cookies'])) {
            return $cron_request;
        }

        $authentication = get_site_option('backwpup_cfg_authentication', ['method' => '', 'user_id' => 0]);
        if (empty($authentication['user_id']) || $authentication['method'] !== 'user') {
            return $cron_request;
        }

        $user_id = (int) $authentication['user_id'];
        $transient_key = 'backwpup_cookies_' . $user_id;
        $cookies = get_site_transient($transient_key);
        if ($cookies === false || !is_array($cookies)) {
            $cookies = [];
            $user = get_userdata($user_id);
            if (!empty($user) && $user->exists()) {
                $expiration = time() + (2 * DAY_IN_SECONDS);
                $manager = WP_Session_Tokens::get_instance($user_id);
                $token = $manager->create($expiration);
                $cookies[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);
            }
            set_site_transient($transient_key, $cookies, 2 * DAY_IN_SECONDS);
        }

        foreach ($cookies as $name => $value) {
            $cron_request['args']['cookies'][] = new WP_Http_Cookie(['name' => $name, 'value' => $value]);
        }

        return $cron_request;
    }

    /**
     * Get the timestamp (UTC) of the next cron execution, the cron expression is interpreted in the site timezone.
     *
     * @param string $cronstring cron (* * * * *)
     *
     * @return int Timestamp
     */
    public static function cron_next($cronstring)
    {
        $cronstr = [];
        $cron = [];
        $cronarray = [];
        //Cron string
        $cronparts = explode(' ', trim((string) $cronstring), 5);
        if (count($cronparts) !== 5) {
            return PHP_INT_MAX;
        }
        [$cronstr['minutes'], $cronstr['hours'], $cronstr['mday'], $cronstr['mon'], $cronstr['wday']] = $cronparts;

        //make arrays form string
        foreach ($cronstr as $key => $value) {
            if (strstr($value, ',')) {
                $cronarray[$key] = explode(',', $value);
            } else {
                $cronarray[$key] = [0 => $value];
            }
        }

        //make arrays complete with ranges and steps
        foreach ($cronarray as $cronarraykey => $cronarrayvalue) {
            $cron[$cronarraykey] = [];

            foreach ($cronarrayvalue as $value) {
                //steps
                $step = 1;
                if (strstr($value, '/')) {
                    [$value, $step] = explode('/', $value, 2);
                    $step = max(1, (int) $step);
                }
                //replace weekday 7 with 0 for sundays
                if ($cronarraykey === 'wday') {
                    $value = str_replace('7', '0', $value);
                }
                //ranges
                if (strstr($value, '-')) {
                    [$first, $last] = explode('-', $value, 2);
                    if (!is_numeric($first) || !is_numeric($last) || $last > 60 || $first > 60) { //check
                        return PHP_INT_MAX;
                    }
                    if ($cronarraykey === 'minutes' && $step < 5) { //set step minimum to 5 min.
                        $step = 5;
                    }
                    $range = [];

                    for ($i = (int) $first; $i <= (int) $last; $i = $i + $step) {
                        $range[] = $i;
                    }
                    $cron[$cronarraykey] = array_merge($cron[$cronarraykey], $range);
                } elseif ($value === '*') {
                    $range = [];
                    if ($cronarraykey === 'minutes') {
                        if ($step < 10) { //set step minimum to 5 min.
                            $step = 10;
                        }

                        for ($i = 0; $i <= 59; $i = $i + $step) {
                            $range[] = $i;
                        }
                    }
                    if ($cronarraykey === 'hours') {
                        for ($i = 0; $i <= 23; $i = $i + $step) {
                            $range[] = $i;
                        }
                    }
                    if ($cronarraykey === 'mday') {
                        for ($i = $step; $i <= 31; $i = $i + $step) {
                            $range[] = $i;
                        }
                    }
                    if ($cronarraykey === 'mon') {
                        for ($i = $step; $i <= 12; $i = $i + $step) {
                            $range[] = $i;
                        }
                    }
                    if ($cronarraykey === 'wday') {
                        for ($i = 0; $i <= 6; $i = $i + $step) {
                            $range[] = $i;
                        }
                    }
                    $cron[$cronarraykey] = array_merge($cron[$cronarraykey], $range);
                } else {
                    if (!is_numeric($value) || (int) $value > 60) {
                        return PHP_INT_MAX;
                    }
                    $cron[$cronarraykey] = array_merge($cron[$cronarraykey], [0 => absint($value)]);
                }
            }
        }

        //generate years
        $year = (int) gmdate('Y');

        for ($i = $year; $i < $year + 100; ++$i) {
            $cron['year'][] = $i;
        }

        //calc next timestamp in the site timezone (handles DST and non-integer offsets)
        $timezone = self::get_timezone();
        $current_timestamp = time();

        foreach ($cron['year'] as $year) {
            foreach ($cron['mon'] as $mon) {
                foreach ($cron['mday'] as $mday) {
                    if (!checkdate($mon, $mday, $year)) {
                        continue;
                    }
                    // Weekday of a calendar date does not depend on the timezone.
                    if (!in_array((int) gmdate('w', gmmktime(0, 0, 0, $mon, $mday, $year)), $cron['wday'], true)) {
                        continue;
                    }

                    foreach ($cron['hours'] as $hours) {
                        foreach ($cron['minutes'] as $minutes) {
                            try {
                                $date = new DateTimeImmutable(
                                    sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $mon, $mday, $hours, $minutes),
                                    $timezone
                                );
                            } catch (Exception $e) {
                                continue;
                            }
                            $timestamp = $date->getTimestamp();
                            if ($timestamp && in_array(
                                (int) $date->format('j'),
                                $cron['mday'],
                                true
                            ) && in_array(
                                (int) $date->format('w'),
                                $cron['wday'],
                                true
                            ) && $timestamp > $current_timestamp) {
                                return $timestamp;
                            }
                        }
                    }
                }
            }
        }

        return PHP_INT_MAX;
    }

    /**
     * Site timezone (wp_timezone() is only available since WP 5.3).
     *
     * @return DateTimeZone
     */
    private static function get_timezone()
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezone_string = get_option('timezone_string');
        if ($timezone_string) {
            try {
                return new DateTimeZone($timezone_string);
            } catch (Exception $e) {
                // Fall through to offset.
            }
        }

        $offset = (float) get_option('gmt_offset');
        $hours = (int) $offset;
        $minutes = abs(($offset - $hours) * 60);

        return new DateTimeZone(sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', abs($hours), $minutes));
    }
}
