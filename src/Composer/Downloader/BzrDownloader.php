<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Downloader;

use Composer\Package\PackageInterface;

/**
 * @author Jérémy Subtil <jeremy.subtil@gmail.com>
 */
class BzrDownloader extends VcsDownloader
{
    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $ref = $package->getSourceReference();

        $command = 'bzr checkout --lightweight';
        if ($ref !== 'default') {
            $command .= ' --revision ' . $ref;
        }

        $this->io->write('    Checking out ' . $ref);
        $this->execute($command, $package->getSourceUrl(), $path);
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $ref = $target->getSourceReference();

        $command = 'bzr switch';
        if ($ref !== 'default') {
            $command .= ' --revision ' . $ref;
        }

        $this->io->write('    Checking out ' . $ref);
        $this->execute($command, $target->getSourceUrl(), $path);
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges($path)
    {
        if (!is_dir($path.'/.bzr')) {
            return;
        }

        $command = 'bzr status --short --versioned';
        if (0 !== $this->process->execute($command, $output, $path)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return trim($output) ?: null;
    }

    /**
     * {@inheritDoc}
     */
    protected function cleanChanges($path, $update)
    {
        if (!$changes = $this->getLocalChanges($path)) {
            return;
        }

        if (!$this->io->isInteractive()) {
            if (true === $this->config->get('discard-changes')) {
                return $this->discardChanges($path);
            }

            return parent::cleanChanges($path, $update);
        }

        $changes = array_map(function ($elem) {
            return '    '.$elem;
        }, preg_split('{\s*\r?\n\s*}', $changes));
        $this->io->write('    <error>The package has modified files:</error>');
        $this->io->write(array_slice($changes, 0, 10));
        if (count($changes) > 10) {
            $this->io->write('    <info>'.count($changes) - 10 . ' more files modified, choose "v" to view the full list</info>');
        }

        while (true) {
            switch ($this->io->ask('    <info>Discard changes [y,n,v,?]?</info> ', '?')) {
                case 'y':
                    $this->discardChanges($path);
                    break 2;

                case 'n':
                    throw new \RuntimeException('Update aborted');

                case 'v':
                    $this->io->write($changes);
                    break;

                case '?':
                default:
                    $this->io->write(array(
                        '    y - discard changes and apply the '.($update ? 'update' : 'uninstall'),
                        '    n - abort the '.($update ? 'update' : 'uninstall').' and let you manually clean things up',
                        '    v - view modified files',
                    ));
                    break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        $command = sprintf('bzr log --revision %s..%s %s', $fromReference, $toReference, escapeshellarg($path));

        if (0 !== $this->process->execute($command, $output)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return $output;
    }

    /**
     * Execute an Bzr command
     *
     * @param string $command Bzr command to run
     * @param string $url     Bzr url
     * @param string $path    Target for a checkout
     * @return string
     */
    protected function execute($command, $url, $path = null)
    {
        try {
            return $this->process->execute("$command $url $path");
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'Package could not be downloaded, '.$e->getMessage()
            );
        }
    }

    protected function discardChanges($path)
    {
        $command = sprintf('bzr revert %s', $path);

        if (0 !== $this->process->execute($command, $output)) {
            throw new \RuntimeException("Could not revert changes\n\n:".$this->process->getErrorOutput());
        }
    }
}
