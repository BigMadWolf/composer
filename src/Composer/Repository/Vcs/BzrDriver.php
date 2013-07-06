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

use Composer\Cache;
use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;

/**
 * @author Jérémy Subtil <jeremy.subtil@gmail.com>
 */
class BzrDriver extends VcsDriver
{
    protected $tags;
    protected $rootIdentifier = 'default';
    protected $cache;
    protected $infoCache = array();

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->url = rtrim(self::normalizeUrl($this->url), '/');
        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->url));

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
        if ($res = $this->cache->read($identifier.'.json')) {
            $this->infoCache[$identifier] = JsonFile::parseJson($res);
        }

        $file = $this->url . '/composer.json';

        if (!isset($this->infoCache[$identifier])) {
            try {
                $output = $this->execute('bzr cat', $file);
                if (!trim($output)) {
                    return;
                }
            } catch (\RuntimeException $e) {
                throw new TransportException($e->getMessage());
            }

            $composer = JsonFile::parseJson($output, $file);

            if (!isset($composer['time'])) {
                $output = $this->execute('bzr log --revision=-1 ', $this->url);
                foreach ($this->process->splitLines($output) as $line) {
                    if ($line && preg_match('{^timestamp: (.+)$}', $line, $match)) {
                        $date = new \DateTime($match[1], new \DateTimeZone('UTC'));
                        $composer['time'] = $date->format('Y-m-d H:i:s');
                        break;
                    }
                }
            }

            $this->cache->write($identifier.'.json', json_encode($composer));
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

            $output = $this->execute('bzr tags --directory=', $this->url);

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
        $url = self::normalizeUrl($url);
        if (preg_match('#(^https?://|^bzr(?:\+ssh)?://)#i', $url)) {
            return true;
        }

        // proceed with deep check for local urls since they are fast to process
        if (!$deep && !static::isLocalUrl($url)) {
            return false;
        }

        $processExecutor = new ProcessExecutor();

        $exit = $processExecutor->execute(
            "bzr info {$url}",
            $ignoredOutput
        );

        if ($exit === 0) {
            // This is definitely a Bazaar repository.
            return true;
        }

        return false;
    }

    /**
     * An absolute path (leading '/') is converted to a file:// url.
     *
     * @param string $url
     *
     * @return string
     */
    protected static function normalizeUrl($url)
    {
        $fs = new Filesystem();
        if ($fs->isAbsolutePath($url)) {
            return 'file://' . strtr($url, '\\', '/');
        }

        return $url;
    }

    /**
     * Execute an BZR command and try to fix up the process with credentials
     * if necessary.
     *
     * @param string $command The bzr command to run.
     * @param string $url     The BZR URL.
     *
     * @return string
     */
    protected function execute($command, $url)
    {
        try {
            $this->process->execute("$command $url", $output);
            return $output;
        } catch (\RuntimeException $e) {
            if (0 !== $this->process->execute('bzr --version', $ignoredOutput)) {
                throw new \RuntimeException('Failed to load '.$this->url.', bzr was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput());
            }

            throw new \RuntimeException(
                'Repository '.$this->url.' could not be processed, '.$e->getMessage()
            );
        }
    }
}
