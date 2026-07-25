<?php
/**
 * Harness fixture: a migration whose only job is to leave a verifiable trace
 * in the real database, so the integration run can assert both the side
 * effect and the ledger option that records it.
 */

return static function (): void {
	update_option( 'upsun_harness_probe', 'harness' );
};
