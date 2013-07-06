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
        $url = escapeshellarg($package->getSourceUrl());
        $ref = $package->getSourceReference();

        $command = sprintf('bzr checkout --lightweight %s %s', $url, escapeshellarg($path));
        if ($ref !== 'default') {
            $command .= sprintf(' --revision %s', escapeshellarg($ref));
        }

        $this->io->write('    Checking out ' . $ref);
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $url = escapeshellarg($target->getSourceUrl());
        $ref = $target->getSourceReference();

        $command = sprintf('bzr switch %s', $url);
        if ($ref !== 'default') {
            $command .= sprintf(' --revision %s', escapeshellarg($ref));
        }

        $this->io->write("    Updating to ".$ref);
        if (0 !== $this->process->execute($command, $ignoredOutput, $path)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
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
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        $command = sprintf('bzr log --revision %s..%s', $fromReference, $toReference);

        if (0 !== $this->process->execute($command, $output, $path)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        return $output;
    }
}
