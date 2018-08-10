<?php
/**
 * webtrees: online genealogy
 * Copyright (C) 2018 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Fisharebest\Webtrees\Http\Controllers;

use Exception;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\DebugBar;
use Fisharebest\Webtrees\Functions\Functions;
use Fisharebest\Webtrees\Functions\FunctionsDate;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Tree;
use GuzzleHttp\Client;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use ZipArchive;

/**
 * Controller for upgrading to a new version of webtrees.
 */
class AdminUpgradeController extends AbstractBaseController
{
    // Icons for success and failure
    const SUCCESS = '<i class="fas fa-check" style="color:green"></i> ';
    const FAILURE = '<i class="fas fa-times" style="color:red"></i> ';

    // Options for fetching files using GuzzleHTTP
    const GUZZLE_OPTIONS = [
        'connect_timeout' => 25,
        'read_timeout'    => 25,
        'timeout'         => 55,
    ];

    const LOCK_FILE = 'data/offline.txt';

    protected $layout = 'layouts/administration';

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function wizard(Request $request): Response
    {
        $continue = (bool)$request->get('continue');

        $title = I18N::translate('Upgrade wizard');

        if ($continue) {
            return $this->viewResponse('admin/upgrade/steps', [
                'steps' => $this->wizardSteps(),
                'title' => $title,
            ]);
        } else {
            return $this->viewResponse('admin/upgrade/wizard', [
                'current_version' => $this->currentVersion(),
                'latest_version'  => $this->latestVersion(),
                'title'           => $title,
            ]);
        }
    }

    /**
     * Perform one step of the wizard
     *
     * @param Request $request
     *
     * @return Response
     */
    public function step(Request $request): Response
    {
        $step = $request->get('step');

        switch ($step) {
            case 'Check':
                return $this->wizardStepCheck();
            case 'Pending':
                return $this->wizardStepPending();
            case 'Export':
                return $this->wizardStepExport($request);
            case 'Download':
                return $this->wizardStepDownload();
            case 'Unzip':
                return $this->wizardStepUnzip();
            case 'Copy':
                return $this->wizardStepCopy();
            case 'Cleanup':
                return $this->wizardStepCleanup();
            default:
                throw new NotFoundHttpException;
        }
    }

    /**
     * @return string[]
     */
    private function wizardSteps(): array
    {
        $download_url = $this->downloadUrl();

        $export_steps = [];

        foreach (Tree::getAll() as $tree) {
            $route                = route('upgrade', [
                'step' => 'Export',
                'ged'  => $tree->getName(),
            ]);
            $message              = I18N::translate('Export all the family trees to GEDCOM files…') . ' ' . e($tree->getTitle());
            $export_steps[$route] = $message;
        }

        return [
                route('upgrade', ['step' => 'Check'])   => 'config.php',
                route('upgrade', ['step' => 'Pending']) => I18N::translate('Check for pending changes…'),
            ] + $export_steps + [
                route('upgrade', ['step' => 'Download']) => I18N::translate('Download %s…', e($download_url)),
                route('upgrade', ['step' => 'Unzip'])    => I18N::translate('Unzip %s to a temporary folder…', e(basename($download_url))),
                route('upgrade', ['step' => 'Copy'])     => I18N::translate('Copy files…'),
                route('upgrade', ['step' => 'Cleanup'])  => I18N::translate('Delete temporary files…'),
            ];
    }

    /**
     * @return Response
     */
    private function wizardStepCheck(): Response
    {
        $latest_version = $this->latestVersion();

        if ($latest_version === '') {
            return $this->failure(I18N::translate('No upgrade information is available.'));
        }

        if (version_compare($this->currentVersion(), $latest_version) >= 0) {
            return $this->failure(I18N::translate('This is the latest version of webtrees. No upgrade is available.'));
        }

        return $this->success(/* I18N: %s is a version number, such as 1.2.3 */
            I18N::translate('Upgrade to webtrees %s.', e($latest_version)));
    }

    /**
     * @return Response
     */
    private function wizardStepPending(): Response
    {
        $changes = Database::prepare("SELECT 1 FROM `##change` WHERE status='pending' LIMIT 1")->fetchOne();

        if (empty($changes)) {
            return $this->success(I18N::translate('There are no pending changes.'));
        } else {
            $route   = route('show-pending');
            $message = I18N::translate('You should accept or reject all pending changes before upgrading.');
            $message .= ' <a href="' . e($route) . '">' . I18N::translate('Pending changes') . '</a>';

            return $this->failure($message);
        }
    }

    /**
     * @param Tree $tree
     *
     * @return Response
     */
    private function wizardStepExport(Tree $tree): Response
    {
        $filename = WT_DATA_DIR . $tree->getName() . date('-Y-m-d') . '.ged';

        try {
            $stream = fopen($filename, 'w');
            $tree->exportGedcom($stream);
            fclose($stream);

            return $this->success(I18N::translate('The family tree has been exported to %s.', e($filename)));
        } catch (Throwable $ex) {
            DebugBar::addThrowable($ex);

            return $this->failure(I18N::translate('The file %s could not be created.', e($filename)));
        }
    }

    /**
     * @return Response
     */
    private function wizardStepDownload(): Response
    {
        $download_url = $this->downloadUrl();
        $zip_file     = WT_DATA_DIR . basename($download_url);
        $zip_stream   = fopen($zip_file, 'w');
        $start_time   = microtime(true);
        $client       = new Client();

        try {
            $response = $client->get($download_url, self::GUZZLE_OPTIONS);
            $stream   = $response->getBody();

            while (!$stream->eof()) {
                fwrite($zip_stream, $stream->read(65536));
            }

            $stream->close();
            fclose($zip_stream);
            $zip_size = filesize($zip_file);
            $end_time = microtime(true);

            if ($zip_size > 0) {
                $kb      = I18N::number($zip_size / 1024);
                $seconds = I18N::number($end_time - $start_time, 2);

                return $this->success(/* I18N: %1$s is a number of KB, %2$s is a (fractional) number of seconds */
                    I18N::translate('%1$s KB were downloaded in %2$s seconds.', $kb, $seconds));
            } elseif (preg_match('/^https:/', $download_url) && !in_array('ssl', stream_get_transports())) {
                // Guess why we might have failed...
                return $this->failure(I18N::translate('This server does not support secure downloads using HTTPS.'));
            } else {
                return $this->failure('');
            }
        } catch (Exception $ex) {
            return $this->failure($ex->getMessage());
        }
    }

    /**
     * @return Response
     */
    private function wizardStepUnzip(): Response
    {
        $download_url   = $this->downloadUrl();
        $zip_file       = WT_DATA_DIR . basename($download_url);
        $tmp_folder     = WT_DATA_DIR . basename($download_url, '.zip');
        $src_filesystem = new Filesystem(new ZipArchiveAdapter($zip_file, null, 'webtrees'));
        $dst_filesystem = new Filesystem(new Local($tmp_folder));
        $paths          = $src_filesystem->listContents('', true);
        $paths          = array_filter($paths, function (array $file) {
            return $file['type'] === 'file';
        });

        $start_time = microtime(true);

        // The Flysystem/ZipArchiveAdapter is very slow, taking over a second per file.
        // So we do this step using the native PHP library.

        $zip = new ZipArchive;
        if ($zip->open($zip_file)) {
            $zip->extractTo($tmp_folder);
            $zip->close();
            echo 'ok';
        } else {
            echo 'failed';
        }

        $seconds = I18N::number(microtime(true) - $start_time, 2);
        $count   = count($paths);

        return $this->success(/* I18N: …from the .ZIP file, %2$s is a (fractional) number of seconds */
            I18N::plural('%1$s file was extracted in %2$s seconds.', '%1$s files were extracted in %2$s seconds.', $count, $count, $seconds));
    }

    /**
     * @return Response
     */
    private function wizardStepCopy(): Response
    {
        $download_url   = $this->downloadUrl();
        $src_filesystem = new Filesystem(new Local(WT_DATA_DIR . basename($download_url, '.zip') . '/webtrees'));
        $dst_filesystem = new Filesystem(new Local(WT_ROOT));
        $paths          = $src_filesystem->listContents('', true);
        $paths          = array_filter($paths, function (array $file) {
            return $file['type'] === 'file';
        });

        $lock_file_text = I18N::translate('This website is being upgraded. Try again in a few minutes.') . PHP_EOL . FunctionsDate::formatTimestamp(WT_TIMESTAMP) . /* I18N: Timezone - http://en.wikipedia.org/wiki/UTC */
            I18N::translate('UTC');
        $dst_filesystem->put(self::LOCK_FILE, $lock_file_text);

        $start_time = microtime(true);

        foreach ($paths as $path) {
            $dst_filesystem->put($path['path'], $src_filesystem->read($path['path']));
            // Delete files as we go, in case disk space is limited.
            $src_filesystem->delete($path['path']);

            if (microtime(true) - WT_START_TIME > ini_get('max_execution_time') - 5) {
                return $this->failure(I18N::translate('The server’s time limit has been reached.'));
            }
        }

        $dst_filesystem->delete(self::LOCK_FILE);

        $seconds = I18N::number(microtime(true) - $start_time, 2);
        $count   = count($paths);

        return $this->success(/* I18N: …from the .ZIP file, %2$s is a (fractional) number of seconds */
            I18N::plural('%1$s file was extracted in %2$s seconds.', '%1$s files were extracted in %2$s seconds.', $count, $count, $seconds));
    }

    /**
     * @return Response
     */
    private function wizardStepCleanup(): Response
    {
        $download_url = $this->downloadUrl();

        $filesystem = new Filesystem(new Local(WT_DATA_DIR));

        try {
            $files = $filesystem->listContents(basename($download_url, '.zip'), true);

            foreach ($files as $file) {
                if ($file['type'] === 'file') {
                    $filesystem->delete($file['path']);
                } else {
                    $filesystem->deleteDir($file['path']);
                }
            }

            $filesystem->delete(basename($download_url));
            $filesystem->deleteDir(basename(basename($download_url, '.zip')));
        } catch (Exception $ex) {
            // We don't really care if we cannot delete these temporary files.
            // But show an error message, anyway.

            return $this->failure($ex->getMessage());
        }

        return $this->success(I18N::translate('The upgrade is complete.'));
    }

    /**
     * @param string $message
     *
     * @return Response
     */
    private function success(string $message): Response
    {
        return new Response(self::SUCCESS . $message);
    }

    /**
     * @param string $message
     *
     * @return Response
     */
    private function failure(string $message): Response
    {
        return new Response(self::FAILURE . $message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * @return string
     */
    private function currentVersion(): string
    {
        return WT_VERSION;
    }

    /**
     * @return string
     */
    private function latestVersion(): string
    {
        $latest_version_txt = Functions::fetchLatestVersion();
        if (preg_match('/^[0-9.]+\|[0-9.]+\|/', $latest_version_txt)) {
            return explode('|', $latest_version_txt)[0];
        } else {
            return '';
        }
    }

    /**
     * @return string
     */
    private function downloadUrl(): string
    {
        $latest_version_txt = Functions::fetchLatestVersion();
        if (preg_match('/^[0-9.]+\|[0-9.]+\|/', $latest_version_txt)) {
            return explode('|', $latest_version_txt)[2];
        } else {
            return '';
        }
    }
}
