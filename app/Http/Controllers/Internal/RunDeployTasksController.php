<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class RunDeployTasksController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $migrateCode = Artisan::call('migrate', ['--force' => true]);
        $migrateOutput = Artisan::output();

        $clearCode = Artisan::call('optimize:clear');
        $clearOutput = Artisan::output();

        $composer = Process::path(base_path())->run([
            'composer',
            'dump-autoload',
            '--no-interaction',
        ]);

        return response()->json([
            'migrate' => [
                'exit_code' => $migrateCode,
                'output' => $migrateOutput,
            ],
            'optimize_clear' => [
                'exit_code' => $clearCode,
                'output' => $clearOutput,
            ],
            'composer_dump_autoload' => [
                'exit_code' => $composer->exitCode(),
                'output' => $composer->output(),
                'error_output' => $composer->errorOutput(),
            ],
        ]);
    }
}

