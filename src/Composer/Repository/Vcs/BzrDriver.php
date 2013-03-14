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
 *
 */
class BzrDriver extends VcsDriver
{
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $repoDir;
    protected $infoCache = array();


    protected $trunkPath    = false;
    protected $tagsPath     = false; //'tags';


    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->url = rtrim($this->url, '/');
        $this->rootIdentifier = substr($this->url, strrpos($this->url, '/') + 1);
        $this->branches = array($this->rootIdentifier => $this->rootIdentifier);
        $this->url = $this->baseUrl = substr($this->url, 0, strrpos($this->url, '/'));
        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->baseUrl));
        $this->getTags();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return $this->rootIdentifier ?: $this->trunkPath;
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
        return array('type' => 'bzr', 'url' => $this->baseUrl, 'reference' => $identifier);
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

        $identifier = '/' . trim($identifier, '/') . '/';
        if ($res = $this->cache->read($identifier.'.json')) {
            $this->infoCache[$identifier] = JsonFile::parseJson($res);
        }

        if (!isset($this->infoCache[$identifier])) {
            preg_match('{^(.+?)(@\d+)?/$}', $identifier, $match);
            if (!empty($match[2])) {
                $path = $match[1];
                $rev = $match[2];
            } else {
                $path = $identifier;
                $rev = '';
            }
            try {
                $resource = $path . 'composer.json';
                $output = $this->execute('bzr cat', $this->baseUrl . $resource . $rev);
                if (!trim($output)) {
                    return;
                }
            } catch (\RuntimeException $e) {
                throw new TransportException($e->getMessage());
            }

            $composer = JsonFile::parseJson($output, $this->baseUrl . $resource . $rev);
            if (!isset($composer['time'])) {
                $output = $this->execute('bzr info', $this->baseUrl . $path . $rev);
                foreach ($this->process->splitLines($output) as $line) {
                    if ($line && preg_match('{^Last Changed Date: ([^(]+)}', $line, $match)) {
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

            if ($this->tagsPath !== false) {
                $output = $this->execute('bzr tags -d ', $this->baseUrl . '/' . $this->tagsPath);
                if ($output) {
                    foreach ($this->process->splitLines($output) as $line) {
                        $line = trim($line);
                        if ($line && preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                            if (isset($match[1]) && isset($match[2]) && $match[2] !== './') {
                                $this->tags[rtrim($match[2], '/')] = '/' . $this->tagsPath .
                                    '/' . $match[2] . '@' . $match[1];
                            }
                        }
                    }
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
        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        $url = self::normalizeUrl($url);
        if (preg_match('#(^http://|^bzr\+ssh://|bzr\.)#i', $url)) {
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

        if (false !== stripos($processExecutor->getErrorOutput(), 'authorization failed:')) {
            // This is likely a remote Subversion repository that requires
            // authentication. We will handle actual authentication later.
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
