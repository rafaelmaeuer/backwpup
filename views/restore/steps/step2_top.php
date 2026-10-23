<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="metabox-holder postbox backwpup-cleared-postbox backwpup-full-width">
	<h3 class="hndle"><span><?php esc_html_e( 'Select Strategy', 'backwpup' ); ?></span></h3>
	<div class="inside">
		<p><?php esc_html_e( 'Select how you want to restore this backup. Available options depend on what is included in the archive. Your decision affects the behavior of the restore process and decides which files are going to be restored.', 'backwpup' ); ?></p>
		<hr>
		<h3 class="mdl-card__title-text"><?php esc_html_e( 'Full Restore', 'backwpup' ); ?></h3>
		<p>
			<?php
			echo wp_kses(
				__(
					'This option restores everything. Your database as well as all files within the backup are restored. The backup usually holds files from your <code>wp-content/</code> directory.',
					'backwpup'
				),
				[ 'code' => [] ]
			);
			?>
		</p>

		<h3 class="mdl-card__title-text"><?php esc_html_e( 'Database Only', 'backwpup' ); ?></h3>
		<p><?php esc_html_e( 'The second option is a database only restore. Choosing this option will restore only the database dump. No files will be restored.', 'backwpup' ); ?></p>

		<h3 class="mdl-card__title-text"><?php esc_html_e( 'Files Only', 'backwpup' ); ?></h3>
		<p><?php esc_html_e( 'Restores only the files from the backup. The database will not be restored.', 'backwpup' ); ?></p>

		<hr>
		<p>
		<?php
		echo wp_kses(
			__( 'Note: Available strategies depend on the archive contents. <strong>Full Restore</strong> can only be done if everything is included in the archive. If it contains only a database export, only a <strong>Database only</strong> restore can be performed. If it contains only files, only a <strong>Files only</strong> restore can be performed.', 'backwpup' ),
			[ 'strong' => [] ]
		);
		?>
		</p>
	</div>
</div>
