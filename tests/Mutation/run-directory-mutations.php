#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: DirectoryOptionsService + DirectoryController — the
 * search+pick backend behind every Settings/ACL/seat directory picker
 * (planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md §1).
 */

require __DIR__ . '/harness.php';

$service = 'lib/Service/DirectoryOptionsService.php';
$controller = 'lib/Controller/DirectoryController.php';

runMutations(dirname(__DIR__, 2), 'DirectoryOptionsServiceTest|DirectoryControllerTest', [
	[
		'name' => 'searchUsers-blank-query-guard-dropped',
		'file' => $service,
		'search' => "if (mb_strlen(\$query) < 2 || \$limit < 1) {\n\t\t\treturn [];\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn [];\n\t\t}",
	],
	[
		'name' => 'searchUsers-limit-cap-removed',
		'file' => $service,
		'search' => "\$limit = min(self::MAX_LIMIT, \$limit);\n\t\t\$byId = \$this->userManager->search(\$query, \$limit, 0) ?? [];",
		'replace' => "\$byId = \$this->userManager->search(\$query, \$limit, 0) ?? [];",
	],
	[
		'name' => 'searchUsers-empty-uid-not-skipped',
		'file' => $service,
		'search' => "if (\$uid === '' || isset(\$merged[\$uid])) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'replace' => "if (isset(\$merged[\$uid])) {\n\t\t\t\tcontinue;\n\t\t\t}",
	],
	[
		'name' => 'searchUsers-displayName-fallback-removed',
		'file' => $service,
		'search' => "'displayName' => \$displayName !== '' ? \$displayName : \$uid,",
		'replace' => "'displayName' => \$displayName,",
	],
	[
		'name' => 'searchUsers-sort-inverted',
		'file' => $service,
		'search' => "usort(\$out, static fn (array \$a, array \$b): int => strcasecmp(\$a['displayName'], \$b['displayName']));\n\t\treturn \$out;\n\t}\n\n\t/**\n\t * @return list<array{id: string, displayName: string}>\n\t */\n\tpublic function searchGroups",
		'replace' => "usort(\$out, static fn (array \$a, array \$b): int => strcasecmp(\$b['displayName'], \$a['displayName']));\n\t\treturn \$out;\n\t}\n\n\t/**\n\t * @return list<array{id: string, displayName: string}>\n\t */\n\tpublic function searchGroups",
	],
	[
		'name' => 'searchGroups-blank-query-guard-dropped',
		'file' => $service,
		'search' => "\$query = trim(\$query);\n\t\tif (\$query === '' || \$limit < 1) {\n\t\t\treturn [];\n\t\t}\n\t\t\$limit = min(self::MAX_LIMIT, \$limit);\n\t\t\$out = [];\n\t\tforeach (\$this->groupManager->search",
		'replace' => "\$query = trim(\$query);\n\t\tif (false) {\n\t\t\treturn [];\n\t\t}\n\t\t\$limit = min(self::MAX_LIMIT, \$limit);\n\t\t\$out = [];\n\t\tforeach (\$this->groupManager->search",
	],
	[
		'name' => 'searchGroups-empty-gid-not-skipped',
		'file' => $service,
		'search' => "\$gid = trim((string)\$group->getGID());\n\t\t\tif (\$gid === '') {\n\t\t\t\tcontinue;\n\t\t\t}",
		'replace' => "\$gid = trim((string)\$group->getGID());\n\t\t\tif (false) {\n\t\t\t\tcontinue;\n\t\t\t}",
	],
	[
		'name' => 'searchGroups-displayName-fallback-removed',
		'file' => $service,
		'search' => "'displayName' => \$displayName !== '' ? \$displayName : \$gid,",
		'replace' => "'displayName' => \$displayName,",
	],
	[
		'name' => 'searchUsers-requireWorkspaceAdmin-dropped',
		'file' => $controller,
		'search' => "public function searchUsers(): JSONResponse\n\t{\n\t\t\$this->access->requireWorkspaceAdmin(\$this->access->currentUserId());",
		'replace' => "public function searchUsers(): JSONResponse\n\t{",
	],
	[
		'name' => 'searchGroups-requireWorkspaceAdmin-dropped',
		'file' => $controller,
		'search' => "public function searchGroups(): JSONResponse\n\t{\n\t\t\$this->access->requireWorkspaceAdmin(\$this->access->currentUserId());",
		'replace' => "public function searchGroups(): JSONResponse\n\t{",
	],
	[
		'name' => 'searchUsers-min-query-length-guard-dropped',
		'file' => $controller,
		'search' => "\$query = trim((string)\$this->request->getParam('q', ''));\n\t\tif (mb_strlen(\$query) < self::MIN_QUERY_LENGTH) {\n\t\t\treturn new JSONResponse(['users' => []]);\n\t\t}",
		'replace' => "\$query = trim((string)\$this->request->getParam('q', ''));\n\t\tif (false) {\n\t\t\treturn new JSONResponse(['users' => []]);\n\t\t}",
	],
	[
		'name' => 'searchGroups-min-query-length-guard-dropped',
		'file' => $controller,
		'search' => "\$query = trim((string)\$this->request->getParam('q', ''));\n\t\tif (mb_strlen(\$query) < self::MIN_QUERY_LENGTH) {\n\t\t\treturn new JSONResponse(['groups' => []]);\n\t\t}",
		'replace' => "\$query = trim((string)\$this->request->getParam('q', ''));\n\t\tif (false) {\n\t\t\treturn new JSONResponse(['groups' => []]);\n\t\t}",
	],
	[
		'name' => 'searchUsers-result-limit-changed',
		'file' => $controller,
		'search' => "return new JSONResponse(['users' => \$this->directory->searchUsers(\$query, self::RESULT_LIMIT)]);",
		'replace' => "return new JSONResponse(['users' => \$this->directory->searchUsers(\$query, self::RESULT_LIMIT + 1)]);",
	],
	[
		'name' => 'searchGroups-result-limit-changed',
		'file' => $controller,
		'search' => "return new JSONResponse(['groups' => \$this->directory->searchGroups(\$query, self::RESULT_LIMIT)]);",
		'replace' => "return new JSONResponse(['groups' => \$this->directory->searchGroups(\$query, self::RESULT_LIMIT + 1)]);",
	],
]);
