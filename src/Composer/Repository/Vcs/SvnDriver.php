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
use Composer\Util\Svn as SvnUtil;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Till Klampaeckel <till@php.net>
 */
class SvnDriver extends VcsDriver
{
    protected $baseUrl;
    protected $tags;
    protected $branches;
    protected $infoCache = array();

    /**
     * @var \Composer\Util\Svn
     */
    protected $util;

    /**
     * @param string          $url
     * @param IOInterface     $io
     * @param ProcessExecutor $process
     *
     * @return $this
     */
    public function __construct($url, IOInterface $io, ProcessExecutor $process = null)
    {
        $url = self::normalizeUrl($url);
        parent::__construct($this->baseUrl = rtrim($url, '/'), $io, $process);

        if (false !== ($pos = strrpos($url, '/trunk'))) {
            $this->baseUrl = substr($url, 0, $pos);
        }
        $this->util    = new SvnUtil($this->baseUrl, $io, $this->process);
    }

    /**
     * Execute an SVN command and try to fix up the process with credentials
     * if necessary.
     *
     * @param string $command The svn command to run.
     * @param string $url     The SVN URL.
     *
     * @return string
     */
    protected function execute($command, $url)
    {
        try {
            return $this->util->execute($command, $url);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'Repository '.$this->url.' could not be processed, '.$e->getMessage()
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->getBranches();
        $this->getTags();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return 'trunk';
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
        return array('type' => 'svn', 'url' => $this->baseUrl, 'reference' => $identifier);
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
        if (!isset($this->infoCache[$identifier])) {
            preg_match('{^(.+?)(@\d+)?/$}', $identifier, $match);
            if (!empty($match[2])) {
                $identifier = $match[1];
                $rev = $match[2];
            } else {
                $rev = '';
            }

            try {
                $output = $this->execute('svn cat', $this->baseUrl . $identifier . 'composer.json' . $rev);
                if (!trim($output)) {
                    return;
                }
            } catch (\RuntimeException $e) {
                throw new TransportException($e->getMessage());
            }

            $composer = JsonFile::parseJson($output);

            if (!isset($composer['time'])) {
                $output = $this->execute('svn info', $this->baseUrl . $identifier . $rev);
                foreach ($this->process->splitLines($output) as $line) {
                    if ($line && preg_match('{^Last Changed Date: ([^(]+)}', $line, $match)) {
                        $date = new \DateTime($match[1]);
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

            $output = $this->execute('svn ls', $this->baseUrl . '/tags');
            if ($output) {
                foreach ($this->process->splitLines($output) as $tag) {
                    if ($tag) {
                        $this->tags[rtrim($tag, '/')] = '/tags/'.$tag;
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
        if (null === $this->branches) {
            $this->branches = array();

            $output = $this->execute('svn ls --verbose', $this->baseUrl . '/');
            if ($output) {
                foreach ($this->process->splitLines($output) as $line) {
                    $line = trim($line);
                    if ($line && preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                        if (isset($match[1]) && isset($match[2]) && $match[2] === 'trunk/') {
                            $this->branches['trunk'] = '/trunk/@'.$match[1];
                            break;
                        }
                    }
                }
            }
            unset($output);

            $output = $this->execute('svn ls --verbose', $this->baseUrl . '/branches');
            if ($output) {
                foreach ($this->process->splitLines(trim($output)) as $line) {
                    $line = trim($line);
                    if ($line && preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                        if (isset($match[1]) && isset($match[2]) && $match[2] !== './') {
                            $this->branches[rtrim($match[2], '/')] = '/branches/'.$match[2].'@'.$match[1];
                        }
                    }
                }
            }
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports($url, $deep = false)
    {
        $url = self::normalizeUrl($url);
        if (preg_match('#(^svn://|^svn\+ssh://|svn\.)#i', $url)) {
            return true;
        }

        // proceed with deep check for local urls since they are fast to process
        if (!$deep && !static::isLocalUrl($url)) {
            return false;
        }

        $processExecutor = new ProcessExecutor();

        $exit = $processExecutor->execute(
            "svn info --non-interactive {$url}",
            $ignoredOutput
        );

        if ($exit === 0) {
            // This is definitely a Subversion repository.
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
}
