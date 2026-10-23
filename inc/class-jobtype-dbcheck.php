<?php
class BackWPup_JobType_DBCheck extends BackWPup_JobTypes
{
    public function __construct()
    {
        $this->info['ID'] = 'DBCHECK';
        $this->info['name'] = __('DB Check', 'backwpup');
        $this->info['description'] = __('Check database tables', 'backwpup');
        $this->info['URI'] = __('http://backwpup.com', 'backwpup');
        $this->info['author'] = 'WP Media';
        $this->info['authorURI'] = 'https://wp-media.me';
        $this->info['version'] = BackWPup::get_plugin_data('Version');
    }

    /**
     * @return array
     */
    public function option_defaults()
    {
        return ['dbcheckwponly' => true, 'dbcheckrepair' => false];
    }

    /**
     * @param $jobid
     */
    public function edit_tab($jobid)
    {
        ?>
		<h3 class="title"><?php esc_html_e('Settings for database check', 'backwpup'); ?></h3>
		<p></p>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e('WordPress tables only', 'backwpup'); ?></th>
				<td>
					<label for="iddbcheckwponly">
					<input class="checkbox" value="1" id="iddbcheckwponly"
						   type="checkbox" <?php checked(BackWPup_Option::get($jobid, 'dbcheckwponly'), true); ?>
						   name="dbcheckwponly"/> <?php esc_html_e('Check WordPress database tables only', 'backwpup'); ?>
                    </label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e('Repair', 'backwpup'); ?></th>
				<td>
                    <label for="iddbcheckrepair">
					<input class="checkbox" value="1" id="iddbcheckrepair"
						   type="checkbox" <?php checked(BackWPup_Option::get($jobid, 'dbcheckrepair'), true); ?>
						   name="dbcheckrepair" /> <?php esc_html_e('Try to repair defect table', 'backwpup'); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
    }

    /**
     * @param $jobid
     */
    public function edit_form_post_save($jobid)
    {
        BackWPup_Option::update($jobid, 'dbcheckwponly', !empty($_POST['dbcheckwponly']));
        BackWPup_Option::update($jobid, 'dbcheckrepair', !empty($_POST['dbcheckrepair']));
    }

    /**
     * @param $job_object
     *
     * @return bool
     */
    public function job_run(BackWPup_Job $job_object)
    {
        /** @var wpdb $wpdb */
        global $wpdb;

        $job_object->log(sprintf(__('%d. Trying to check database&#160;&hellip;', 'backwpup'), $job_object->steps_data[$job_object->step_working]['STEP_TRY']));
        if (!isset($job_object->steps_data[$job_object->step_working]['DONETABLE']) || !is_array($job_object->steps_data[$job_object->step_working]['DONETABLE'])) {
            $job_object->steps_data[$job_object->step_working]['DONETABLE'] = [];
        }

        //to check
        $tables = [];
        $tablestype = [];
        $restables = (array) $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);

        foreach ($restables as $table) {
            $table_name = (string) $table[0];
            if (!empty($job_object->job['dbcheckwponly']) && strncmp($table_name, $wpdb->prefix, strlen($wpdb->prefix)) !== 0) {
                continue;
            }
            $tables[] = $table_name;
            $tablestype[$table_name] = $table[1];
        }

        //Set num
        $job_object->substeps_todo = count($tables);

        //Get table status
        $status = [];
        $resstatus = (array) $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);

        foreach ($resstatus as $tablestatus) {
            $status[$tablestatus['Name']] = $tablestatus;
        }

        //check tables
        if ($job_object->substeps_todo > 0) {
            foreach ($tables as $table) {
                if (in_array($table, $job_object->steps_data[$job_object->step_working]['DONETABLE'], true)) {
                    continue;
                }

                if (!isset($tablestype[$table])) {
                    continue;
                }

                // Quote the identifier, table names come from SHOW TABLES but may contain backticks.
                $table_identifier = '`' . str_replace('`', '``', $table) . '`';
                $table_log_name = esc_html($table);

                if ($tablestype[$table] === 'VIEW') {
                    $job_object->log(sprintf(__('Table %1$s is a view. Not checked.', 'backwpup'), $table_log_name));

                    continue;
                }

                $engine = $status[$table]['Engine'] ?? '';
                if ($engine !== 'MyISAM' && $engine !== 'InnoDB') {
                    $job_object->log(sprintf(__('Table %1$s is not a MyISAM/InnoDB table. Not checked.', 'backwpup'), $table_log_name));

                    continue;
                }

                //CHECK TABLE funktioniert bei MyISAM- und InnoDB-Tabellen (http://dev.mysql.com/doc/refman/5.1/de/check-table.html)
                $check = $wpdb->get_row('CHECK TABLE ' . $table_identifier . ' MEDIUM', OBJECT); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $check_msg_text = isset($check->Msg_text) ? (string) $check->Msg_text : (string) $wpdb->last_error;
                $check_msg_type = isset($check->Msg_type) ? (string) $check->Msg_type : 'error';
                if (strtolower($check_msg_text) === 'ok') {
                    if ($job_object->is_debug()) {
                        $job_object->log(sprintf(__('Result of table check for %1$s is: %2$s', 'backwpup'), $table_log_name, esc_html($check_msg_text)));
                    }
                } elseif (strtolower($check_msg_type) === 'warning') {
                    $job_object->log(sprintf(__('Result of table check for %1$s is: %2$s', 'backwpup'), $table_log_name, esc_html($check_msg_text)), E_USER_WARNING);
                } else {
                    $job_object->log(sprintf(__('Result of table check for %1$s is: %2$s', 'backwpup'), $table_log_name, esc_html($check_msg_text)), E_USER_ERROR);
                }
                //Try to Repair table
                if (!empty($job_object->job['dbcheckrepair']) && strtolower($check_msg_text) !== 'ok' && $engine === 'MyISAM') {
                    $repair = $wpdb->get_row('REPAIR TABLE ' . $table_identifier . ' EXTENDED', OBJECT); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $repair_msg_text = isset($repair->Msg_text) ? (string) $repair->Msg_text : (string) $wpdb->last_error;
                    $repair_msg_type = isset($repair->Msg_type) ? (string) $repair->Msg_type : 'error';
                    if (strtolower($repair_msg_text) === 'ok') {
                        $job_object->log(sprintf(__('Result of table repair for %1$s is: %2$s', 'backwpup'), $table_log_name, esc_html($repair_msg_text)));
                    } elseif (strtolower($repair_msg_type) === 'warning') {
                        $job_object->log(sprintf(__('Result of table repair for %1$s is: %2$s', 'backwpup'), $table_log_name, esc_html($repair_msg_text)), E_USER_WARNING);
                    } else {
                        $job_object->log(sprintf(__('Result of table repair for %1$s is: %2$s', 'backwpup'), $table_log_name, esc_html($repair_msg_text)), E_USER_ERROR);
                    }
                }
                $job_object->steps_data[$job_object->step_working]['DONETABLE'][] = $table;
                ++$job_object->substeps_done;
            }
            $job_object->log(__('Database check done!', 'backwpup'));
        } else {
            $job_object->log(__('No tables to check.', 'backwpup'));
        }

        unset($job_object->steps_data[$job_object->step_working]['DONETABLE']);

        return true;
    }
}
