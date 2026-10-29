<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! $bind->errors ) { ?>
	<div class="metabox-holder postbox backwpup-cleared-postbox backwpup-full-width">
		<h3 class="hndle">
			<span><?php esc_html_e( 'Success', 'backwpup' ); ?>
			</span>
		</h3>
		<div class="inside">
			<p>
				<?php
				esc_html_e(
					'Your restore was successful and everything should be back to normal.',
					'backwpup'
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'You will be taken to the dashboard in a few seconds. If you are asked to log in, use the login of the restored site.', 'backwpup' ); ?>
			</p>
			<p>
				<a id="backwpup-restore-dashboard" class="button button-primary" href="<?php echo esc_url( admin_url() ); ?>">
					<?php esc_html_e( 'Go to Dashboard', 'backwpup' ); ?>
				</a>
			</p>
		</div>
	</div>
	<script>
		// Reload into the restored site: the restore page was loaded before the database was replaced.
		setTimeout( function () {
			window.location.assign( document.getElementById( 'backwpup-restore-dashboard' ).href );
		}, 5000 );
	</script>
<?php } ?>

<?php if ( $bind->errors ) { ?>
	<div class="metabox-holder postbox backwpup-cleared-postbox backwpup-full-width">
		<h3 class="hndle">
			<span>
				<?php esc_html_e( 'Restore Report', 'backwpup' ); ?>
			</span>
		</h3>
		<div class="inside">
			<?php
			esc_html_e(
				'Seems there was some error during the restore that need manual action.',
				'backwpup'
			);
			?>
		</div>
	</div>
	<?php
}
