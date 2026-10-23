<?php
/**
 * BackWPup_Destination_Ftp_Downloader.
 *
 * @since   3.5.0
 */

/**
 * Class BackWPup_Destination_Ftp_Downloader.
 *
 * Downloads the backup through the FTP extension (ftp_nb_fget) using the same connection
 * settings as the backup job (SSL, passive mode), instead of the ftp:// stream wrapper which
 * ignored those settings and required the credentials to be put into an URL.
 *
 * @since   3.5.0
 */
final class BackWPup_Destination_Ftp_Downloader implements BackWPup_Destination_Downloader_Interface
{
    /**
     * @var \BackWpUp_Destination_Downloader_Data
     */
    private $data;

    /**
     * @var resource
     */
    private $local_file_handler;

    /**
     * @var BackWPup_Destination_Ftp_Connect
     */
    private $ftp_resource;

    /**
     * Return code of the last non blocking FTP operation.
     *
     * @var int
     */
    private $ret = FTP_FAILED;

    /**
     * Whether a transfer has been started.
     *
     * @var bool
     */
    private $transfer_started = false;

    /**
     * Remote file size.
     *
     * @var int
     */
    private $remote_file_size = 0;

    /**
     * BackWPup_Destination_Ftp_Downloader constructor.
     */
    public function __construct(BackWpUp_Destination_Downloader_Data $data)
    {
        $this->data = $data;

        $this->ftp_resource();
    }

    /**
     * Clean up things.
     */
    public function __destruct()
    {
        if (is_resource($this->local_file_handler)) {
            fclose($this->local_file_handler);
        }
        if ($this->ftp_resource) {
            $this->ftp_resource->disconnect();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function download_chunk($start_byte, $end_byte)
    {
        $this->local_file_handler();

        if ($this->ret === FTP_FINISHED) {
            return;
        }

        $resource = $this->connection();

        if (!$this->transfer_started) {
            $this->transfer_started = true;
            $this->ret = @ftp_nb_fget(
                $resource,
                $this->local_file_handler,
                $this->data->source_file_path(),
                FTP_BINARY,
                (int) ftell($this->local_file_handler)
            );
        }

        // Transfer until the requested chunk is complete (or the file is finished).
        while ($this->ret === FTP_MOREDATA) {
            $this->ret = @ftp_nb_continue($resource);
            if (ftell($this->local_file_handler) > $end_byte) {
                break;
            }
        }

        if ($this->ret === FTP_FAILED) {
            throw new \RuntimeException($this->download_error_message());
        }

        if ($this->ret === FTP_FINISHED && (int) ftell($this->local_file_handler) !== $this->calculate_size()) {
            throw new \RuntimeException(__('Downloaded file size does not match the size of the backup on the FTP server.', 'backwpup'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function calculate_size()
    {
        if (!$this->remote_file_size) {
            $size = ftp_size($this->connection(), $this->data->source_file_path());
            if ($size < 0) {
                throw new \RuntimeException($this->download_error_message());
            }
            $this->remote_file_size = (int) $size;
        }

        return $this->remote_file_size;
    }

    /**
     * Get the FTP connection, connect if needed.
     *
     * @return resource|\FTP\Connection
     */
    private function connection()
    {
        try {
            return $this->ftp_resource
                ->connect()
                ->resource()
            ;
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage() . ' ' . $this->download_error_message(), 0, $e);
        }
    }

    /**
     * Set the local file handler.
     */
    private function local_file_handler()
    {
        if (is_resource($this->local_file_handler)) {
            return;
        }

        $this->local_file_handler = fopen($this->data->local_file_path(), 'wb');

        if (!is_resource($this->local_file_handler)) {
            throw new \RuntimeException(__('File could not be opened for writing.', 'backwpup'));
        }
    }

    /**
     * Set the Ftp resource.
     */
    private function ftp_resource()
    {
        $opts = (object) BackWPup_Option::get_job($this->data->job_id());

        $this->ftp_resource = new BackWPup_Destination_Ftp_Connect(
            $opts->ftphost,
            $opts->ftpuser,
            BackWPup_Encryption::decrypt($opts->ftppass),
            $opts->ftphostport,
            $opts->ftptimeout,
            $opts->ftpssl,
            $opts->ftppasv
        );
    }

    /**
     * The message shown when the backup cannot be downloaded from the FTP server.
     */
    private function download_error_message(): string
    {
        return __(
            'BackWPup couldn’t download the backup file from your FTP server. Please check your FTP connection settings and permissions.',
            'backwpup'
        );
    }
}
