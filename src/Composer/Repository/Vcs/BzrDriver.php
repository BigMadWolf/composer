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

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\IO\IOInterface;

/**
 * @author Jérémy Subtil <jeremy.subtil@gmail.com>
 */
class BzrDriver extends VcsDriver
{
    protected $tags;
    protected $rootIdentifier = 'default';
    protected $repoDir;
    protected $infoCache = array();

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        if (static::isLocalUrl($this->url)) {
            $this->repoDir = str_replace('file://', '', $this->url);
        } else {
            $cacheDir = $this->config->get('cache-vcs-dir');
            $this->repoDir = $cacheDir . '/' . preg_replace('{[^a-z0-9]}i', '-', $this->url) . '/';

            $fs = new Filesystem();
            $fs->ensureDirectoryExists($cacheDir);

            if (!is_writable(dirname($this->repoDir))) {
                throw new \RuntimeException('Can not checkout '.$this->url.' to access package information. The "'.$cacheDir.'" directory is not writable by the current user.');
            }

            // update the repo if it is a valid hg repository
            if (is_dir($this->repoDir) && 0 === $this->process->execute('bzr status', $output, $this->repoDir)) {
                if (0 !== $this->process->execute('bzr update', $output, $this->repoDir)) {
                    $this->io->write('<error>Failed to update '.$this->url.', package information from this repository may be outdated ('.$this->process->getErrorOutput().')</error>');
                }
            } else {
                // clean up directory and do a fresh clone into it
                $fs->removeDirectory($this->repoDir);

                if (0 !== $this->process->execute(sprintf('bzr checkout --lightweight %s %s', escapeshellarg($this->url), escapeshellarg($this->repoDir)), $output, $cacheDir)) {
                    $output = $this->process->getErrorOutput();

                    if (0 !== $this->process->execute('bzr --version', $ignoredOutput)) {
                        throw new \RuntimeException('Failed to checkout '.$this->url.', bzr was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput());
                    }

                    throw new \RuntimeException('Failed to checkout '.$this->url.', could not read packages from it' . "\n\n" .$output);
                }
            }
        }

        $this->getTags();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        return array('type' => 'bzr', 'url' => $this->url, 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$identifier])) {
            $command = 'bzr cat composer.json';
            if ($identifier !== 'default') {
                $command .= sprintf(' --revision %s', escapeshellarg($identifier));
            }

            $this->process->execute($command, $composer, $this->repoDir);

            if (!trim($composer)) {
                return;
            }

            $composer = JsonFile::parseJson($composer, $identifier);

            if (!isset($composer['time'])) {
                $command = sprintf('bzr log --revision=-1');
                if ($identifier !== 'default') {
                    $command .= sprintf(' --revision %s', escapeshellarg($identifier));
                }

                $this->process->execute($command, $output, $this->repoDir);
                foreach ($this->process->splitLines($output) as $line) {
                    if ($line && preg_match('{^timestamp: (.+)$}', $line, $match)) {
                        $date = new \DateTime($match[1], new \DateTimeZone('UTC'));
                        $composer['time'] = $date->format('Y-m-d H:i:s');
                        break;
                    }
                }
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if (null === $this->tags) {
            $this->tags = array();

            $this->process->execute('bzr tags', $output, $this->repoDir);
            foreach ($this->process->splitLines($output) as $line) {
                if (preg_match('{^([^\s]+)}', $line, $matches)) {
                    $tag = $matches[1];
                    $this->tags[$tag] = $tag;
                }
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        // no branches support for Bazaar
        return array($this->rootIdentifier => $this->rootIdentifier);
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        if (preg_match('#^bzr(?:\+ssh)?://#i', $url)) {
            return true;
        }

        // local filesystem
        if (static::isLocalUrl($url)) {
            if (!is_dir($url)) {
                throw new \RuntimeException('Directory does not exist: '.$url);
            }

            $process = new ProcessExecutor();
            $url = str_replace('file://', '', $url);
            // check whether there is a bzr repo in that path
            if ($process->execute('bzr status', $output, $url) === 0) {
                return true;
            }
        }

        if (!$deep) {
            return false;
        }

        $processExecutor = new ProcessExecutor();
        $exit = $processExecutor->execute(sprintf('bzr info %s', escapeshellarg($url)), $ignored);

        return $exit === 0;
    }
}
