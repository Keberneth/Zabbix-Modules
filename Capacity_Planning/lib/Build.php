<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Lib;

/**
 * Release identity shared by PHP endpoints and the browser bundle.
 *
 * Keep VERSION aligned with manifest.json and BUILD_ID aligned with
 * CLIENT_BUILD_ID in the build-versioned assets/js/capacity-planning-*.js.
 * The versioned URL defeats stale browser/proxy caches; the runtime handshake
 * then fails closed when a partial deployment or OPcache mixes builds.
 */
final class Build {
	public const VERSION = '1.4.0';
	public const ID = '1.4.0-20260801.1';

	private function __construct() {}
}
