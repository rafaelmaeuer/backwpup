<?php
/**
 * Stale restore files notice.
 *
 * Minimal v4 port of the BackWPup 5.7.4 "stale restore files" security notice
 * (including the 5.7.8 fix that ignores BackWPup's own protection files so the
 * notice does not reappear right after the files were deleted).
 */

declare(strict_types=1);

namespace Inpsyde\BackWPup\Infrastructure\Restore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Warns administrators about leftover restore working-directory files (which may
 * contain database credentials in plain text) and lets them delete the files
 * with one click.
 */
final class StaleRestoreFilesNotice
{
    public const AJAX_DELETE_ACTION = 'backwpup_delete_restore_files';
    public const AJAX_DISMISS_ACTION = 'backwpup_dismiss_stale_restore_files';
    public const USER_META_DISMISSED = 'backwpup_stale_restore_files_dismissed';
    public const CAPABILITY = 'manage_options';

    /**
     * Names of the protection files BackWPup writes into protected folders.
     * They are scaffolding re-created on every restore boot, never leftovers.
     *
     * @var string[]
     */
    private const PROTECTION_FILE_NAMES = ['.htaccess', 'Web.config', 'index.php', '.donotbackup'];

    /**
     * Register hooks.
     */
    public function set_hooks(): void
    {
        add_action('admin_notices', [$this, 'maybe_render']);
        add_action('network_admin_notices', [$this, 'maybe_render']);
        add_action('wp_ajax_' . self::AJAX_DELETE_ACTION, [$this, 'delete_restore_files']);
        add_action('wp_ajax_' . self::AJAX_DISMISS_ACTION, [$this, 'dismiss']);
    }

    /**
     * Render the notice when stale restore files exist.
     */
    public function maybe_render(): void
    {
        if (!current_user_can(self::CAPABILITY) || !function_exists(__NAMESPACE__ . '\\restore_dir_candidates')) {
            return;
        }

        // Do not show the notice on the restore page itself, a restore may be running there.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && isset($screen->id) && strpos((string) $screen->id, 'backwpuprestore') !== false) {
            return;
        }

        $fingerprint = $this->fingerprint();
        if ($fingerprint === '') {
            return;
        }

        $dismissed = get_user_meta(get_current_user_id(), self::USER_META_DISMISSED, true);
        if (is_string($dismissed) && hash_equals($dismissed, $fingerprint)) {
            return;
        }

        $delete_nonce = wp_create_nonce(self::AJAX_DELETE_ACTION);
        $dismiss_nonce = wp_create_nonce(self::AJAX_DISMISS_ACTION);
        ?>
        <div id="backwpup-stale-restore-files-notice" class="notice notice-warning">
            <p><strong><?php esc_html_e('BackWPup: Sensitive restore files detected', 'backwpup'); ?></strong></p>
            <p>
                <?php
                esc_html_e(
                    'BackWPup detected leftover files from a previous restore operation in the restore working directory. These files may contain your database credentials in plain text. It is strongly recommended to delete them immediately.',
                    'backwpup'
                );
                ?>
            </p>
            <p>
                <?php
                esc_html_e(
                    'BackWPup also periodically checks for and automatically removes abandoned restore files, but deleting them now is safer while they remain on disk.',
                    'backwpup'
                );
                ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="backwpup-delete-restore-files"
                    data-nonce="<?php echo esc_attr($delete_nonce); ?>">
                    <?php esc_html_e('Delete restore files', 'backwpup'); ?>
                </button>
                <button type="button" class="button-link" id="backwpup-dismiss-restore-files"
                    data-nonce="<?php echo esc_attr($dismiss_nonce); ?>">
                    <?php esc_html_e('Dismiss', 'backwpup'); ?>
                </button>
                <span id="backwpup-delete-restore-files-error" role="alert" aria-live="assertive"
                    style="display:none; margin-left:1em; color:#d63638;"></span>
            </p>
        </div>
        <script>
            ( function () {
                var notice = document.getElementById( 'backwpup-stale-restore-files-notice' );
                if ( ! notice || ! window.fetch || typeof window.ajaxurl === 'undefined' ) {
                    return;
                }
                var deleteBtn = document.getElementById( 'backwpup-delete-restore-files' );
                var dismissBtn = document.getElementById( 'backwpup-dismiss-restore-files' );
                var errorSpan = document.getElementById( 'backwpup-delete-restore-files-error' );

                function post( action, nonce ) {
                    var body = new URLSearchParams();
                    body.append( 'action', action );
                    body.append( '_ajax_nonce', nonce );
                    return window.fetch( window.ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: body
                    } ).then( function ( response ) {
                        return response.json();
                    } );
                }

                deleteBtn.addEventListener( 'click', function () {
                    deleteBtn.disabled = true;
                    errorSpan.style.display = 'none';
                    post( <?php echo wp_json_encode(self::AJAX_DELETE_ACTION); ?>, deleteBtn.getAttribute( 'data-nonce' ) )
                        .then( function ( result ) {
                            if ( result && result.success ) {
                                notice.parentNode.removeChild( notice );
                                return;
                            }
                            throw new Error( result && result.data && result.data.message ? result.data.message : '' );
                        } )
                        .catch( function ( error ) {
                            deleteBtn.disabled = false;
                            errorSpan.textContent = error && error.message
                                ? error.message
                                : <?php echo wp_json_encode(__('Restore files could not be deleted. Please delete them manually.', 'backwpup')); ?>;
                            errorSpan.style.display = 'inline';
                        } );
                } );

                dismissBtn.addEventListener( 'click', function () {
                    post( <?php echo wp_json_encode(self::AJAX_DISMISS_ACTION); ?>, dismissBtn.getAttribute( 'data-nonce' ) )
                        .then( function () {
                            notice.parentNode.removeChild( notice );
                        } );
                } );
            }() );
        </script>
        <?php
    }

    /**
     * AJAX: delete every restore working directory's files.
     */
    public function delete_restore_files(): void
    {
        if (!check_ajax_referer(self::AJAX_DELETE_ACTION, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'backwpup')], 403);
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(
                ['message' => esc_html__('You do not have permission to perform this action.', 'backwpup')],
                403
            );
        }

        foreach (restore_dir_candidates() as $project_temp) {
            cleanup_restore_dir($project_temp, false);
        }

        if ($this->fingerprint() !== '') {
            wp_send_json_error(
                ['message' => esc_html__('Some restore files could not be deleted. Please delete them manually.', 'backwpup')],
                500
            );
        }

        delete_user_meta(get_current_user_id(), self::USER_META_DISMISSED);
        wp_send_json_success();
    }

    /**
     * AJAX: dismiss the notice for the current user until the leftover files change.
     */
    public function dismiss(): void
    {
        if (!check_ajax_referer(self::AJAX_DISMISS_ACTION, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'backwpup')], 403);
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(
                ['message' => esc_html__('You do not have permission to perform this action.', 'backwpup')],
                403
            );
        }

        update_user_meta(get_current_user_id(), self::USER_META_DISMISSED, $this->fingerprint());
        wp_send_json_success();
    }

    /**
     * Fingerprint of the currently detected stale files, empty string if there are none.
     *
     * A dismissal is bound to this fingerprint, so the notice comes back when new
     * leftovers appear (e.g. after another interrupted restore).
     */
    private function fingerprint(): string
    {
        $parts = [];
        foreach (restore_dir_candidates() as $base_dir) {
            if ($this->dir_has_files($base_dir)) {
                $parts[] = $base_dir . ':' . (string) newest_restore_activity_mtime($base_dir);
            }
        }

        return $parts ? md5(implode('|', $parts)) : '';
    }

    /**
     * Whether one restore working directory holds stale files.
     *
     * True if restore.dat.bkp exists (plaintext DB credentials), extract/ has any
     * entry, or uploads/ has any entry other than BackWPup's own protection files.
     */
    private function dir_has_files(string $base_dir): bool
    {
        if (!is_dir($base_dir)) {
            return false;
        }

        if (file_exists($base_dir . '/restore.dat.bkp')) {
            return true;
        }

        $ignored_names = [
            'uploads' => self::PROTECTION_FILE_NAMES,
            'extract' => [],
        ];

        foreach ($ignored_names as $subdir => $ignored) {
            $path = $base_dir . '/' . $subdir;
            if (!is_dir($path)) {
                continue;
            }

            try {
                $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
                foreach ($iterator as $entry) {
                    if (!$entry->isLink() && $entry->isFile() && in_array($entry->getFilename(), $ignored, true)) {
                        continue;
                    }

                    return true;
                }
            } catch (\UnexpectedValueException $e) {
                continue;
            }
        }

        return false;
    }
}
