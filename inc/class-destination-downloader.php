<?php

use function Inpsyde\BackWPup\Infrastructure\Restore\restore_container;
/**
 * BackWPup_Destination_Downloader.
 *
 * @since   3.6.0
 */

use Inpsyde\Restore\Api\Controller\DecryptController;
use Inpsyde\Restore\Api\Module\Decryption\Exception\DecryptException;

/**
 * Class BackWPup_Destination_Downloader.
 *
 * @since   3.6.0
 */
class BackWPup_Destination_Downloader
{
    public const ARCHIVE_ENCRYPT_OPTION = 'archiveencryption';
    public const CAPABILITY = 'backwpup_backups_download';

    public const STATE_DOWNLOADING = 'downloading';
    public const STATE_ERROR = 'error';
    public const STATE_DONE = 'done';

    /**
     * @var \BackWpUp_Destination_Downloader_Data
     */
    private $data;

    /**
     * @var \BackWPup_Destination_Downloader_Interface
     */
    private $destination;

    /**
     * BackWPup_Downloader constructor.
     *
     * @param \BackWpUp_Destination_Downloader_Data      $data
     * @param \BackWPup_Destination_Downloader_Interface $destination
     */
    public function __construct(
        BackWpUp_Destination_Downloader_Data $data,
        BackWPup_Destination_Downloader_Interface $destination
    ) {
        $this->data = $data;
        $this->destination = $destination;
    }

    /**
     * Download file via ajax.
     */
    public static function download_by_ajax()
    {
        $dest = (string) filter_input(INPUT_GET, 'destination');
        if (!$dest) {
            return;
        }

        $job_id = (int) filter_input(INPUT_GET, 'jobid', FILTER_SANITIZE_NUMBER_INT);
        if (!$job_id) {
            return;
        }

        $file = (string) filter_input(INPUT_GET, 'file');
        $file_local = (string) filter_input(INPUT_GET, 'local_file');
        if (!$file || !$file_local) {
            return;
        }

        // Authorization must be checked before any destination code runs. Some destinations
        // (e.g. Rackspace, SugarSync) implement their own file_download() which does not go
        // through BackWpup_Download_Handler and therefore never checked permissions.
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Sorry, you don\'t have permissions to do that.', 'backwpup'), 403);
        }
        check_admin_referer('backwpup_action_nonce', 'backwpup_action_nonce');

        set_time_limit(0);
        // Set up eventsource headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        // 2KB padding for IE
        echo ':' . str_repeat(' ', 2048) . "\n\n"; // phpcs:ignore
        // Never let the browser reconnect automatically: a reconnect restarts the whole copy.
        echo "retry: 86400000\n\n";

        // Ensure we're not buffered.
        wp_ob_end_flush_all();
        flush();

        /** @var \BackWPup_Destinations $dest_class */
        $dest_class = BackWPup::get_destination($dest);
        $dest_class->file_download(
            $job_id,
            trim(sanitize_text_field($file)),
            trim(sanitize_text_field($file_local))
        );
    }

    /**
     * @param string $final_file_path when given, the chunks are written to the data's local file path
     *                                (a temporary file) which is renamed to this path once complete,
     *                                so a partially copied file is never served
     *
     * @return bool
     */
    public function download_by_chunks(string $final_file_path = '')
    {
        $this->ensure_user_can_download();

        $source_file_path = $this->data->source_file_path();
        $local_file_path = $this->data->local_file_path();
        $start_byte = 0;
        $chunk_size = 2 * 1024 * 1024;
        $end_byte = $start_byte + $chunk_size - 1;

        try {
            // Inside the try block, so connection or lookup errors are reported to the user.
            $size = (int) $this->destination->calculate_size();
            if ($size <= 0) {
                throw new \RuntimeException(__('The backup file could not be found or is empty.', 'backwpup'));
            }

            if ($end_byte >= $size) {
                $end_byte = $size - 1;
            }

            while ($end_byte <= $size) {
                $this->destination->download_chunk($start_byte, $end_byte);
                self::send_message(
                    [
                        'state' => self::STATE_DOWNLOADING,
                        'start_byte' => $start_byte,
                        'end_byte' => $end_byte,
                        'size' => $size,
                        'download_percent' => round(($end_byte + 1) / $size * 100),
                        'filename' => basename($source_file_path),
                    ]
                );

                if ($end_byte === $size - 1) {
                    break;
                }

                $start_byte = $end_byte + 1;
                $end_byte = $start_byte + $chunk_size - 1;

                if ($start_byte < $size && $end_byte >= $size) {
                    $end_byte = $size - 1;
                }
            }

            if ($final_file_path !== '' && $final_file_path !== $local_file_path) {
                // Release the destination so its file handles are closed before renaming.
                $this->destination = null;
                clearstatcache(true, $local_file_path);
                if ((int) filesize($local_file_path) !== $size) {
                    throw new \RuntimeException(__('The downloaded file is incomplete. Please try again.', 'backwpup'));
                }
                if (file_exists($final_file_path)) {
                    wp_delete_file($final_file_path);
                }
                if (!rename($local_file_path, $final_file_path)) {
                    throw new \RuntimeException(__('Could not move the downloaded file into place.', 'backwpup'));
                }
                $local_file_path = $final_file_path;
            }

            if (BackWPup::is_pro()) {
                /** @var \Inpsyde\Restore\Api\Module\Decryption\Decrypter $decrypter */
                $decrypter = restore_container('decrypter');
                if ($decrypter->isEncrypted($local_file_path)) {
                    throw new DecryptException(DecryptController::STATE_NEED_DECRYPTION_KEY);
                }
            }
        } catch (Exception $e) {
            self::send_message(
                [
                    'state' => self::STATE_ERROR,
                    // Escape the message: it may contain data coming from remote services or file
                    // paths and is rendered as HTML by the backup downloader script.
                    'message' => esc_html($e->getMessage()),
                ],
                'log'
            );

            return false;
        }

        self::send_message([
            'state' => self::STATE_DONE,
            'message' => esc_html__('Your download is being generated &hellip;', 'backwpup'),
        ]);

        return true;
    }

    /**
     * Ensure user capability.
     */
    private function ensure_user_can_download()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die();
        }
    }

    /**
     * @param        $data
     * @param string $event
     */
    private static function send_message($data, $event = 'message')
    {
        echo 'event: ' . esc_html($event) . "\n";
        echo 'data: ' . wp_json_encode($data) . "\n\n";
        flush();
    }
}
