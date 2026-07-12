<?php
/**
 * UpdraftPlus stages backups in wp-content/updraft (its default; the
 * updraft_dir option can move it). Advisory only: the fix on Upsun is a
 * mount, and the writable-paths check prints the exact YAML when missing.
 */

namespace Upsun\Integrations;

use Upsun\Integration;

class UpdraftPlus implements Integration {

	public function label(): string {
		return 'UpdraftPlus';
	}

	public function is_active(): bool {
		return class_exists( 'UpdraftPlus' );
	}

	public function register(): void {
		add_filter( 'upsun_writable_path_requirements', array( $this, 'add_requirements' ), 5 );
	}

	public function add_requirements( array $requirements ): array {
		$requirements['updraftplus'] = array(
			'label'  => 'UpdraftPlus',
			'active' => array( $this, 'is_active' ),
			'paths'  => array( 'updraft' ),
		);

		return $requirements;
	}
}
