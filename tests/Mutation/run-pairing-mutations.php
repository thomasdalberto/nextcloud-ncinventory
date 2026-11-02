#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

$file = 'lib/Service/DevicePairingService.php';

runMutations(dirname(__DIR__, 2), 'DevicePairingServiceTest', [
	[
		'name' => 'claim-false-ignored',
		'file' => $file,
		'search' => "if (!\$claimed) {\n\t\t\t\$this->recordFailureOrThrowRateLimited();\n\t\t\tthrow new ValidationException('invalid_pair_code');\n\t\t}",
		'replace' => "if (false) {\n\t\t\t\$this->recordFailureOrThrowRateLimited();\n\t\t\tthrow new ValidationException('invalid_pair_code');\n\t\t}",
	],
	[
		'name' => 'last-seen-throttle-zero',
		'file' => $file,
		'search' => 'if ($now - $last < self::LAST_SEEN_THROTTLE) {',
		'replace' => 'if ($now - $last < 0) {',
	],
	[
		'name' => 'rate-max-raised',
		'file' => $file,
		'search' => 'private const RATE_MAX = 10;',
		'replace' => 'private const RATE_MAX = 1000;',
	],
	[
		'name' => 'record-skips-recheck',
		'file' => $file,
		'search' => "if (\$count >= self::RATE_MAX) {\n\t\t\t\tthrow new MobileRequestException('rate_limited');\n\t\t\t}\n\t\t\t\$data['count'] = \$count + 1;",
		'replace' => "if (false && \$count >= self::RATE_MAX) {\n\t\t\t\tthrow new MobileRequestException('rate_limited');\n\t\t\t}\n\t\t\t\$data['count'] = \$count + 1;",
	],
	[
		'name' => 'rate-bucket-ignores-ip',
		'file' => $file,
		'search' => "return self::RATE_KEY_PREFIX . substr(hash('sha256', \$ip), 0, 32);",
		'replace' => "return self::RATE_KEY_PREFIX . 'global';",
	],
]);
