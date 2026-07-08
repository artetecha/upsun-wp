<?php

namespace Upsun;

interface Module {

	/**
	 * Whether the module should register its hooks. Called at
	 * muplugins_loaded priority 0; must not call pluggable functions.
	 */
	public function should_load(): bool;

	/**
	 * Register hooks only. Behavior belongs in the hook callbacks so it
	 * runs after all plugins have loaded.
	 */
	public function register(): void;
}
