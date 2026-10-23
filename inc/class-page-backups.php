<?php

use function Inpsyde\BackWPup\Infrastructure\Restore\restore_container;
use Inpsyde\Restore\ViewLoader;

final class BackWPup_Page_Backups extends WP_List_Table
{
    private static $listtable;

    private $destinations = [];

    private $jobid = 1;

    private $dest = 'FOLDER';

    public function __construct()
    {
        parent::__construct([
            'plural' => 'backups',
            'singular' => 'backup',
            'ajax' => true,
        ]);

        $this->destinations = BackWPup::get_registered_destinations();
    }

    public function ajax_user_can()
    {
        return current_user_can('backwpup_backups');
    }

    public function prepare_items()
    {
        $per_page = $this->get_items_per_page('backwpupbackups_per_page');
        if (empty($per_page) || $per_page < 1) {
            $per_page = 20;
        }

        $jobdest = '';
        if (isset($_GET['jobdets-button-top'], $_GET['jobdest-top'])) {
            $jobdest = sanitize_text_field(wp_unslash((string) $_GET['jobdest-top']));
        }
        if (isset($_GET['jobdets-button-bottom'], $_GET['jobdest-bottom'])) {
            $jobdest = sanitize_text_field(wp_unslash((string) $_GET['jobdest-bottom']));
        }

        if (empty($jobdest)) {
            $jobdests = $this->get_destinations_list();
            if (empty($jobdests)) {
                $jobdests = ['_'];
            }
            $jobdest = $jobdests[0];
            $_GET['jobdest-top'] = $jobdests[0];
            $_GET['jobdets-button-top'] = 'empty';
        }

        $jobdest_parts = explode('_', (string) $jobdest, 2);
        $this->jobid = absint($jobdest_parts[0]);
        $this->dest = isset($jobdest_parts[1]) ? strtoupper(sanitize_key($jobdest_parts[1])) : '';

        if (!empty($this->destinations[$this->dest]['class'])) {
            /** @var BackWPup_Destinations $dest_object */
            $dest_object = BackWPup::get_destination($this->dest);
            $this->items = $dest_object->file_get_list($jobdest);
        }

        if (!$this->items) {
            $this->items = '';

            return;
        }

        // Sorting.
        $order = isset($_GET['order']) ? strtolower(sanitize_key(wp_unslash((string) $_GET['order']))) : 'desc';
        $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
        $orderby = isset($_GET['orderby']) ? strtolower(sanitize_key(wp_unslash((string) $_GET['orderby']))) : 'time';
        $orderby = in_array($orderby, ['time', 'file', 'folder', 'size'], true) ? $orderby : 'time';
        $tmp = [];

        if ($orderby === 'time') {
            if ($order === 'asc') {
                foreach ($this->items as &$ma) {
                    $tmp[] = &$ma['time'];
                }
                array_multisort($tmp, SORT_ASC, $this->items);
            } else {
                foreach ($this->items as &$ma) {
                    $tmp[] = &$ma['time'];
                }
                array_multisort($tmp, SORT_DESC, $this->items);
            }
        } elseif ($orderby === 'file') {
            if ($order === 'asc') {
                foreach ($this->items as &$ma) {
                    $tmp[] = &$ma['filename'];
                }
                array_multisort($tmp, SORT_ASC, $this->items);
            } else {
                foreach ($this->items as &$ma) {
                    $tmp[] = &$ma['filename'];
                }
                array_multisort($tmp, SORT_DESC, $this->items);
            }
        } elseif ($orderby === 'folder') {
            if ($order === 'asc') {
                foreach ($this->items as &$ma) {
                    $tmp[] = &$ma['folder'];
                }
                array_multisort($tmp, SORT_ASC, $this->items);
            } else {
                foreach ($this->items as &$ma) {
                    $tmp[] = &$ma['folder'];
                }
                array_multisort($tmp, SORT_DESC, $this->items);
            }
        } elseif ($orderby === 'size') {
            if ($order === 'asc') {
                foreach ($this->items as &$ma) {
                    $tmp[] = &$ma['filesize'];
                }
                array_multisort($tmp, SORT_ASC, $this->items);
            } else {
                foreach ($this->items as &$ma) {
                    $tmp[] = &$ma['filesize'];
                }
                array_multisort($tmp, SORT_DESC, $this->items);
            }
        }

        $this->set_pagination_args([
            'total_items' => count($this->items),
            'per_page' => $per_page,
            'jobdest' => $jobdest,
            'orderby' => $orderby,
            'order' => $order,
        ]);

        // Only display items on page.
        $start = intval(($this->get_pagenum() - 1) * $per_page);
        $end = $start + $per_page;
        if ($end > count($this->items)) {
            $end = count($this->items);
        }

        $i = -1;
        $paged_items = [];

        foreach ($this->items as $item) {
            ++$i;
            if ($i < $start) {
                continue;
            }
            if ($i >= $end) {
                break;
            }
            $paged_items[] = $item;
        }

        $this->items = $paged_items;
    }

    public function no_items()
    {
        esc_html_e('No files could be found. (List will be generated during next backup.)', 'backwpup');
    }

    public function get_bulk_actions()
    {
        if (!$this->has_items()) {
            return [];
        }

        $actions = [];
        $actions['delete'] = __('Delete', 'backwpup');

        return $actions;
    }

    public function extra_tablenav($which)
    {
        $destinations_list = $this->get_destinations_list();

        if (count($destinations_list) < 1) {
            return;
        }

        if (count($destinations_list) === 1) {
            echo '<input type="hidden" name="jobdest-' . esc_attr($which) . '" value="' . esc_attr($destinations_list[0]) . '">';

            return;
        } ?>
		<div class="alignleft actions">
			<label for="jobdest-<?php echo esc_attr($which); ?>">
				<select name="jobdest-<?php echo esc_attr($which); ?>" class="postform"
				        id="jobdest-<?php echo esc_attr($which); ?>">
					<?php
                    foreach ($destinations_list as $jobdest) {
                        [$jobid, $dest] = explode('_', (string) $jobdest);
                        echo "\t<option value=\"" . esc_attr($jobdest) . '" ' . selected(
                            $this->jobid . '_' . $this->dest,
                            $jobdest,
                            false
                        ) . '>' . esc_html($dest) . ': ' . esc_html(BackWPup_Option::get(
                            $jobid,
                            'name'
                        )) . '</option>' . PHP_EOL;
                    } ?>
				</select>
			</label>
			<?php submit_button(
                        __('Change destination', 'backwpup'),
                        'secondary',
                        'jobdets-button-' . $which,
                        false,
                        ['id' => 'query-submit-' . $which]
                    ); ?>
		</div>
		<?php
    }

    public function get_columns()
    {
        $posts_columns = [];
        $posts_columns['cb'] = '<input type="checkbox" />';
        $posts_columns['time'] = __('Time', 'backwpup');
        $posts_columns['file'] = __('File', 'backwpup');
        $posts_columns['folder'] = __('Folder', 'backwpup');
        $posts_columns['size'] = __('Size', 'backwpup');

        return $posts_columns;
    }

    public function get_sortable_columns()
    {
        return [
            'file' => ['file', false],
            'folder' => 'folder',
            'size' => 'size',
            'time' => ['time', false],
        ];
    }

    public function column_cb($item)
    {
        return '<input type="checkbox" name="backupfiles[]" value="' . esc_attr($item['file']) . '" />';
    }

    public function get_destinations_list()
    {
        $jobdest = [];
        $jobids = BackWPup_Option::get_job_ids();

        foreach ($jobids as $jobid) {
            if (BackWPup_Option::get($jobid, 'backuptype') === 'sync') {
                continue;
            }
            $dests = BackWPup_Option::get($jobid, 'destinations');

            foreach ((array) $dests as $dest) {
                if (empty($this->destinations[$dest]['class'])) {
                    continue;
                }
                $dest_class = BackWPup::get_destination($dest);
                $can_do_dest = $dest_class->file_get_list($jobid . '_' . $dest);
                if (!empty($can_do_dest)) {
                    $jobdest[] = $jobid . '_' . $dest;
                }
            }
        }

        return $jobdest;
    }

    public function column_file($item)
    {
        $actions = [];

        $r = '<strong>' . esc_html($item['filename']) . '</strong><br />';
        if (!empty($item['info'])) {
            $r .= esc_html($item['info']) . '<br />';
        }

        if (current_user_can('backwpup_backups_delete')) {
            $actions['delete'] = $this->delete_item_action($item);
        }

        if (!empty($item['downloadurl']) && current_user_can('backwpup_backups_download')) {
            try {
                $actions['download'] = $this->download_item_action($item);

                if ($this->dest === 'HIDRIVE') {
                    $downloadUrl = wp_nonce_url($item['downloadurl'], 'backwpup_action_nonce');

                    if ($item['filesize'] > 10485760) { // 10 MB
                        $request = new BackWPup_Pro_Destination_HiDrive_Request();
                        $authorization = new BackWPup_Pro_Destination_HiDrive_Authorization($request);
                        $api = new BackWPup_Pro_Destination_HiDrive_Api($request, $authorization);
                        $response = $api->temporalDownloadUrl($this->jobid, $item['file']);
                        $responsBody = json_decode((string) $response['body']);

                        if (isset($responsBody->url)) {
                            $downloadUrl = $responsBody->url;
                        }
                    }

                    $actions['download'] = '<a href="' . esc_url($downloadUrl) . '" class="backup-download-link">' . esc_html__('Download', 'backwpup') . '</a>';
                }
            } catch (BackWPup_Factory_Exception $e) {
                $actions['download'] = sprintf(
                    '<a href="%1$s">%2$s</a>',
                    esc_url(wp_nonce_url($item['downloadurl'], 'backwpup_action_nonce')),
                    esc_html__('Download', 'backwpup')
                );
            }
        }

        // Add restore url to link list
        if (current_user_can('backwpup_restore') && !empty($item['restoreurl'])) {
            $item['restoreurl'] = add_query_arg(
                [
                    'step' => 1,
                    'trigger_download' => 1,
                ],
                $item['restoreurl']
            );
            $actions['restore'] = sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url(wp_nonce_url($item['restoreurl'], 'restore-backup_' . $this->jobid)),
                esc_html__('Restore', 'backwpup')
            );
        }

        $r .= $this->row_actions($actions);

        return $r;
    }

    public function column_folder($item)
    {
        return esc_html($item['folder']);
    }

    public function column_size($item)
    {
        if (!empty($item['filesize']) && (int) $item['filesize'] !== -1) {
            return esc_html(size_format($item['filesize'], 2));
        }

        return esc_html__('?', 'backwpup');
    }

    public function column_time($item)
    {
        return sprintf(
            esc_html__('%1$s at %2$s', 'backwpup'),
            esc_html(date_i18n(get_option('date_format'), (int) $item['time'], true)),
            esc_html(date_i18n(get_option('time_format'), (int) $item['time'], true))
        );
    }

    public static function load()
    {
        global $current_user;

        //Create Table
        self::$listtable = new BackWPup_Page_Backups();

        switch (self::$listtable->current_action()) {
            case 'delete': //Delete Backup archives
                check_admin_referer('bulk-backups');
                if (!current_user_can('backwpup_backups_delete')) {
                    wp_die(esc_html__('Sorry, you don\'t have permissions to do that.', 'backwpup'));
                }

                $jobdest = '';
                if (isset($_GET['jobdest'])) {
                    $jobdest = sanitize_text_field(wp_unslash((string) $_GET['jobdest']));
                }
                if (isset($_GET['jobdest-top'])) {
                    $jobdest = sanitize_text_field(wp_unslash((string) $_GET['jobdest-top']));
                }

                $_GET['jobdest-top'] = $jobdest;
                $_GET['jobdets-button-top'] = 'submit';

                if ($jobdest === '') {
                    return;
                }

                if (strpos($jobdest, '_') === false) {
                    return;
                }

                [$jobid, $dest] = explode('_', $jobdest, 2);
                $dest = strtoupper(sanitize_key($dest));
                $registered_destinations = BackWPup::get_registered_destinations();
                if (empty($registered_destinations[$dest]['class'])) {
                    return;
                }

                /** @var BackWPup_Destinations $dest_class */
                $dest_class = BackWPup::get_destination($dest);
                $files = $dest_class->file_get_list($jobdest);

                if (empty($_GET['backupfiles']) || !is_array($_GET['backupfiles'])) {
                    break;
                }

                $request_backup_files = array_map('sanitize_text_field', wp_unslash($_GET['backupfiles']));

                foreach ($request_backup_files as $backupfile) {
                    foreach ($files as $file) {
                        if (is_array($file) && (string) $file['file'] === $backupfile) {
                            $dest_class->file_delete($jobdest, $backupfile);
                        }
                    }
                }
                $files = $dest_class->file_get_list($jobdest);
                if (empty($files)) {
                    $_GET['jobdest-top'] = '';
                }
                break;

            default:
                if (isset($_GET['jobid'])) {
                    $jobid = absint($_GET['jobid']);
                    if (!current_user_can('backwpup_backups_download')) {
                        wp_die(esc_html__('Sorry, you don\'t have permissions to do that.', 'backwpup'));
                    }
                    check_admin_referer('backwpup_action_nonce');

                    $source_file = '';
                    if (!empty($_GET['local_file'])) {
                        $source_file = sanitize_text_field(wp_unslash((string) $_GET['local_file']));
                    } elseif (!empty($_GET['file'])) {
                        $source_file = sanitize_text_field(wp_unslash((string) $_GET['file']));
                    }
                    if ($source_file === '') {
                        break;
                    }

                    $filename = untrailingslashit(BackWPup::get_plugin_data('temp')) . '/' . basename($source_file);
                    if (basename($source_file) !== '' && is_file($filename) && !is_link($filename)) {
                        $downloader = new BackWPup_Download_File(
                            $filename,
                            function (BackWPup_Download_File_Interface $obj) use ($filename) {
                                $obj->clean_ob()
                                    ->headers()
                                ;

                                readfile($filename); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

                                // Delete the temporary file.
                                wp_delete_file($filename);

                                exit();
                            },
                            'backwpup_backups_download'
                        );
                        $downloader->download();
                    } else {
                        // If the file doesn't exist, fallback to old way of downloading
                        // This is for destinations without a downloader class
                        $dest = strtoupper(str_replace('download', '', self::$listtable->current_action()));
                        $registered_destinations = BackWPup::get_registered_destinations();
                        if (!empty($dest) && strpos((string) self::$listtable->current_action(), 'download') !== false && !empty($registered_destinations[$dest]['class'])) {
                            /** @var BackWPup_Destinations $dest_class */
                            $dest_class = BackWPup::get_destination($dest);

                            try {
                                if (!empty($_GET['file'])) {
                                    $dest_class->file_download($jobid, trim(sanitize_text_field(wp_unslash((string) $_GET['file']))));
                                }
                            } catch (BackWPup_Destination_Download_Exception $e) {
                                header('HTTP/1.0 404 Not Found');
                                wp_die(
                                    esc_html__('Ops! Unfortunately the file doesn\'t exists. May be was deleted?', 'backwpup'),
                                    esc_html__('404 - File Not Found.', 'backwpup'),
                                    [
                                        'back_link' => esc_html__('&laquo; Go back', 'backwpup'),
                                    ]
                                );
                            }
                        }
                    }
                }
                break;
        }

        //Save per page
        if (isset($_POST['screen-options-apply'], $_POST['wp_screen_options']['option'], $_POST['wp_screen_options']['value']) && $_POST['wp_screen_options']['option'] === 'backwpupbackups_per_page') {
            check_admin_referer('screen-options-nonce', 'screenoptionnonce');

            $per_page_value = absint($_POST['wp_screen_options']['value']);
            if ($per_page_value > 0 && $per_page_value < 1000) {
                update_user_option(
                    $current_user->ID,
                    'backwpupbackups_per_page',
                    $per_page_value
                );
                wp_safe_redirect(remove_query_arg(['pagenum', 'apage', 'paged'], wp_get_referer()));

                exit;
            }
        }

        add_screen_option(
            'per_page',
            [
                'label' => __('Backup Files', 'backwpup'),
                'default' => 20,
                'option' => 'backwpupbackups_per_page',
            ]
        );

        self::$listtable->prepare_items();
    }

    public static function admin_print_styles()
    {
        ?>
		<style type="text/css" media="screen">
			.column-size, .column-time {
				width: 10%;
			}

			@media screen and (max-width: 782px) {
				.column-size, .column-runtime, .column-size, .column-folder {
					display: none;
				}

				.column-time {
					width: 18%;
				}
			}
		</style>
		<?php
    }

    public static function admin_print_scripts()
    {
        $suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
        $plugin_url = BackWPup::get_plugin_data('url');
        $plugin_dir = BackWPup::get_plugin_data('plugindir');
        $plugin_scripts_url = "{$plugin_url}/assets/js";
        $plugin_scripts_dir = "{$plugin_dir}/assets/js";
        $shared_scripts_path = "{$plugin_url}/vendor/inpsyde/backwpup-shared/resources/js";
        $shared_scripts_dir = "{$plugin_dir}/vendor/inpsyde/backwpup-shared/resources/js";

        wp_register_script(
            'backwpup_functions',
            "{$shared_scripts_path}/functions{$suffix}.js",
            ['underscore', 'jquery'],
            filemtime("{$shared_scripts_dir}/functions{$suffix}.js"),
            true
        );
        wp_register_script(
            'backwpup_states',
            "{$shared_scripts_path}/states{$suffix}.js",
            [
                'backwpup_functions',
            ],
            filemtime("{$shared_scripts_dir}/states{$suffix}.js"),
            true
        );

        $dependencies = [
            'jquery',
            'underscore',
            'backwpupgeneral',
            'backwpup_functions',
            'backwpup_states',
        ];
        if (\BackWPup::is_pro()) {
            $dependencies[] = 'decrypter';
        }
        wp_enqueue_script(
            'backwpup-backup-downloader',
            "{$plugin_scripts_url}/backup-downloader{$suffix}.js",
            $dependencies,
            filemtime("{$plugin_scripts_dir}/backup-downloader{$suffix}.js"),
            true
        );

        if (\BackWPup::is_pro()) {
            self::admin_print_pro_scripts($suffix, $plugin_url, $plugin_dir);
        }
    }

    public static function page()
    {
        ?>
		<div class="wrap" id="backwpup-page">
			<h1><?php echo esc_html(sprintf(
            __('%s &rsaquo; Manage Backup Archives', 'backwpup'),
            BackWPup::get_plugin_data('name')
        )); ?></h1>
			<?php BackWPup_Admin::display_messages(); ?>
			<form id="posts-filter" action="" method="get">
				<input type="hidden" name="page" value="backwpupbackups"/>
				<?php self::$listtable->display(); ?>
				<div id="ajax-response"></div>
			</form>
		</div>

		<div id="tb_download_file" style="display: none;">
			<div id="tb_container">
				<p id="download-file-waiting">
					<?php esc_html_e('Please wait &hellip;', 'backwpup'); ?>
				</p>
				<p id="download-file-success" style="display: none;">
					<?php esc_html_e(
            'Your download has been generated. It should begin downloading momentarily.',
            'backwpup'
        ); ?>
				</p>
				<div class="progressbar" style="display: none;">
					<div id="progresssteps" class="bwpu-progress" style="width:0%;">0%</div>
				</div>
				<?php
				if ( \BackWPup::is_pro() ) {
					$view = new ViewLoader();
					$view->decrypt_key_input();
                } ?>
			</div>
		</div>
		<?php
    }

    private static function admin_print_pro_scripts($suffix, $plugin_url, $plugin_dir)
    {
        $restore_scripts_path = "{$plugin_url}/vendor/inpsyde/backwpup-restore-shared/resources/js";
        $restore_scripts_dir = "{$plugin_dir}/vendor/inpsyde/backwpup-restore-shared/resources/js";

        wp_register_script(
            'decrypter',
            "{$restore_scripts_path}/decrypter{$suffix}.js",
            [
                'underscore',
                'jquery',
                'backwpup_states',
                'backwpup_functions',
            ],
            filemtime("{$restore_scripts_dir}/decrypter{$suffix}.js"),
            true
        );
    }

    private function delete_item_action($item)
    {
        $query = sprintf(
            '?page=backwpupbackups&action=delete&jobdest-top=%1$s&paged=%2$s&backupfiles[]=%3$s',
            rawurlencode($this->jobid . '_' . $this->dest),
            $this->get_pagenum(),
            rawurlencode((string) $item['file'])
        );
        $url = wp_nonce_url(network_admin_url('admin.php') . $query, 'bulk-backups');
        $js = sprintf(
            'if ( confirm(\'%s\') ) { return true; } return false;',
            esc_js(
                __(
                    'You are about to delete this backup archive. \'Cancel\' to stop, \'OK\' to delete.',
                    'backwpup'
                )
            )
        );

        return sprintf(
            '<a class="submitdelete" href="%1$s" onclick="%2$s">%3$s</a>',
            esc_url($url),
            $js,
            esc_html__('Delete', 'backwpup')
        );
    }

    private function download_item_action($item)
    {
        $local_file = untrailingslashit(BackWPup::get_plugin_data('TEMP')) . "/{$item['filename']}";

        return sprintf(
            '<a href="#TB_inline?height=300&width=630&inlineId=tb_download_file" 
				class="backup-download-link thickbox" 
				id="backup-download-link"
				data-jobid="%1$s" 
				data-destination="%2$s" 
				data-file="%3$s" 
				data-local-file="%4$s" 
				data-nonce="%5$s" 
				data-url="%6$s">%7$s</a>',
            intval($this->jobid),
            esc_attr($this->dest),
            esc_attr($item['file']),
            esc_attr($local_file),
            esc_attr(wp_create_nonce('backwpup_action_nonce')),
            esc_url(wp_nonce_url($item['downloadurl'], 'backwpup_action_nonce')),
            esc_html__('Download', 'backwpup')
        );
    }
}
