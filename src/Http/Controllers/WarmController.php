<?php

namespace Kura\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kura\KuraManager;

class WarmController extends Controller
{
    /**
     * Rebuild cache for specified tables (or all registered tables).
     *
     * POST /kura/warm
     * POST /kura/warm?tables=products,categories
     */
    public function __invoke(Request $request, KuraManager $manager): JsonResponse
    {
        /** @var string|null $version */
        $version = $request->query('version');

        if ($version !== null && $version !== '') {
            $manager->setVersionOverride($version);
        }

        /** @var string|null $tablesParam */
        $tablesParam = $request->query('tables');
        $tables = ($tablesParam !== null && $tablesParam !== '')
            ? explode(',', $tablesParam)
            : $manager->registeredTables();

        if ($tables === []) {
            return new JsonResponse(['message' => 'No tables registered.', 'tables' => []], 200);
        }

        $results = [];

        foreach ($tables as $table) {
            try {
                $repo = $manager->repository($table);
                $manager->rebuild($table);
                $results[$table] = ['status' => 'ok', 'version' => $repo->version()];
            } catch (\Throwable $e) {
                $results[$table] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        $hasError = in_array('error', array_column($results, 'status'), true);

        return new JsonResponse([
            'message' => $hasError ? 'Some tables failed.' : 'All tables warmed.',
            'tables' => $results,
        ], $hasError ? 500 : 200);
    }
}
