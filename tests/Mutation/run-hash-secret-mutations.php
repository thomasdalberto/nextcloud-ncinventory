#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

$file = 'lib/Service/ScannerService.php';

runMutations(dirname(__DIR__, 2), 'HashSecretPepperTest', [
	[
		'name' => 'empty-pepper-fallback-restored',
		'file' => $file,
		'search' => "if (\$pepper === '') {\n\t\t\tthrow new \\RuntimeException('instance_secret_missing');\n\t\t}\n\t\treturn hash_hmac('sha256', \$secret, \$pepper);",
		'replace' => "if (\$pepper === '') {\n\t\t\t\$pepper = 'inventorycheck';\n\t\t}\n\t\treturn hash_hmac('sha256', \$secret, \$pepper);",
	],
	[
		'name' => 'hmac-downgraded-to-sha256',
		'file' => $file,
		'search' => "return hash_hmac('sha256', \$secret, \$pepper);",
		'replace' => "return hash('sha256', \$secret);",
	],
]);
