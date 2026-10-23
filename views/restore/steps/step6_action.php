<?php

use Inpsyde\Restore\Log\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \stdClass $bind */
if ( $bind->errors ) {
	/** @var Log $log_error */
	foreach ( $bind->errors as $log_error ) {
		// count() on a string throws a TypeError on PHP 8, show the full message instead.
		$message = (string) $log_error->message(); ?>
		<div class="notice notice-error below-h2">
			<p>
				<?php echo esc_html( $message ); ?>
			</p>
		</div>
		<?php
	}
}
