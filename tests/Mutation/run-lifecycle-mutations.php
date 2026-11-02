#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: mobile session CSRF channel + GDPR purge wiring.
 */

require __DIR__ . '/harness.php';

$mobile = 'lib/Controller/MobileController.php';
$listener = 'lib/Listener/UserDeletedListener.php';

runMutations(dirname(__DIR__, 2), 'MobilePublicPageContractTest|UserLifecyclePurgeContractTest|AccessControlServiceTest', [
	[
		'name' => 'csrf-channel-always-passes',
		'file' => $mobile,
		'search' => "if (\$this->request->passesCSRFCheck()) {\n\t\t\treturn;\n\t\t}\n\t\tthrow new MobileRequestException('auth_required');",
		'replace' => "if (true || \$this->request->passesCSRFCheck()) {\n\t\t\treturn;\n\t\t}\n\t\tthrow new MobileRequestException('auth_required');",
	],
	[
		'name' => 'scan-skips-csrf-channel',
		'file' => $mobile,
		'search' => "public function scan(): JSONResponse\n\t{\n\t\t\$this->assertSafeMutationChannel();\n\t\t[\$uid, \$device] = \$this->resolveCaller(true);",
		'replace' => "public function scan(): JSONResponse\n\t{\n\t\t[\$uid, \$device] = \$this->resolveCaller(true);",
	],
	[
		'name' => 'listener-skips-seat-purge',
		'file' => $listener,
		'search' => "\$this->access->purgeUser(\$uid);\n\t\t\$this->license->removeSeat(\$uid);\n\t\t\$this->favourites->deleteAllForUser(\$uid);\n\t\t\$this->locationAcl->purgeUser(\$uid);",
		'replace' => "\$this->access->purgeUser(\$uid);",
	],
]);
