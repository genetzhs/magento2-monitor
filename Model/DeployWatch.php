<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;

/**
 * Notices a deploy and asks MageSentinel for a checkout check after it.
 *
 * A deploy is a change in the static content version (setup:static-content:deploy) or in the module versions
 * Magento has installed (setup:upgrade). Only the fingerprint of the two is kept. The first reading only records
 * it, and nothing is sent unless the connector is on.
 */
class DeployWatch
{
    /** @var Filesystem */
    private $filesystem;
    /** @var ResourceConnection */
    private $resource;
    /** @var State */
    private $state;
    /** @var Service */
    private $service;

    /**
     * Constructor.
     *
     * @param Filesystem $filesystem
     * @param ResourceConnection $resource
     * @param State $state
     * @param Service $service
     */
    public function __construct(Filesystem $filesystem, ResourceConnection $resource, State $state, Service $service)
    {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        $this->state = $state;
        $this->service = $service;
    }

    /**
     * Compare the deploy fingerprint with the last one and ask for a check when it changed.
     *
     * @return void
     */
    public function run(): void
    {
        $now = $this->fingerprint();
        if ($now['hash'] === '') {
            return;
        }
        $was = $this->state->deploy();
        if ($was === '') {
            $this->state->saveDeploy($now['hash']);
            return;
        }
        if ($was === $now['hash'] || $this->service->connection() !== 'on') {
            // Off or quiet: remember the new deploy anyway, so turning the connector on later does not start a check.
            $this->state->saveDeploy($now['hash']);
            return;
        }
        $r = $this->service->startCheck('deploy', $now['detail']);
        // 429 is MageSentinel's start limit: a check just ran, which covers this deploy too.
        if (in_array($r['code'], [202, 409, 429], true)) {
            $this->state->saveDeploy($now['hash']);
        }
    }

    /**
     * The static content version and the installed module versions, hashed.
     *
     * @return array{hash:string, detail:string}
     */
    private function fingerprint(): array
    {
        $static = '';
        try {
            $dir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW);
            if ($dir->isFile('deployed_version.txt')) {
                $static = trim((string)$dir->readFile('deployed_version.txt'));
            }
        } catch (\Throwable $e) {
            $static = '';
        }
        try {
            $db = $this->resource->getConnection();
            $rows = $db->fetchAll(
                $db->select()->from($this->resource->getTableName('setup_module'))->order('module ASC')
            );
        } catch (\Throwable $e) {
            return ['hash' => '', 'detail' => ''];
        }
        $modules = '';
        foreach ($rows as $row) {
            $modules .= ($row['module'] ?? '') . '=' . ($row['schema_version'] ?? '') . '/'
                . ($row['data_version'] ?? '') . ';';
        }
        return [
            'hash' => hash('sha256', $static . '|' . $modules),
            'detail' => $static !== '' ? 'static ' . $static : 'setup:upgrade',
        ];
    }
}
