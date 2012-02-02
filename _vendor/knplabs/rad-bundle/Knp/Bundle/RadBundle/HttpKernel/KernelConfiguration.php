<?php

/*
 * This file is part of the KnpRadBundle package.
 *
 * (c) KnpLabs <http://knplabs.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Knp\Bundle\RadBundle\HttpKernel;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Yaml\Yaml;

use RadAppKernel;

/**
 * RAD kernel configuration class.
 */
class KernelConfiguration
{
    private $projectName;
    private $applicationName;
    private $configs    = array();
    private $parameters = array();
    private $bundles    = array();

    protected $environment;
    protected $configDir;
    protected $projectCache;
    protected $bundlesCache;

    /**
     * Initializes configuration.
     *
     * @param string  $environment Configuration environment
     * @param string  $configDir   Directory to confgs
     * @param string  $cacheDir    Directory to cache
     * @param Boolean $debug       Whether debugging in enabled or not
     */
    public function __construct($environment, $configDir, $cacheDir, $debug)
    {
        $this->environment  = $environment;
        $this->configDir    = $configDir;
        $this->projectCache = new ConfigCache($cacheDir.'/project.yml.cache', $debug);
        $this->bundlesCache = new ConfigCache($cacheDir.'/bundles.php.cache', $debug);
    }

    /**
     * Loads conventional configs for specific configuration.
     */
    public function load()
    {
        if (!$this->projectCache->isFresh()) {
            $metadata = array();

            if (file_exists($cfg = $this->configDir.'/project.yml')) {
                $this->updateFromFile($cfg, $this->environment);
                $metadata[] = new FileResource($cfg);
            }

            if (file_exists($cfg = $this->configDir.'/project.local.yml')) {
                $this->updateFromFile($cfg, $this->environment);
                $metadata[] = new FileResource($cfg);
            }

            $this->projectCache->write('<?php return '.var_export(array(
                $this->projectName,
                $this->applicationName,
                $this->configs,
                $this->parameters,
                $this->bundles
            ), true).';', $metadata);
        }

        list(
            $this->projectName,
            $this->applicationName,
            $this->configs,
            $this->parameters,
            $this->bundles
        ) = require($this->projectCache);
    }

    /**
     * Returns full project name (application namespace).
     *
     * @return string
     */
    public function getProjectName()
    {
        return $this->projectName;
    }

    /**
     * Returns application name (last segment of applicatoin namespace).
     *
     * @return string
     */
    public function getApplicationName()
    {
        return $this->applicationName;
    }

    /**
     * Returns array of custom config DIC files paths.
     *
     * @return string
     */
    public function getConfigs()
    {
        return $this->configs;
    }

    /**
     * Returns array of initialized bundles.
     *
     * @param RadAppKernel $kernel Project kernel
     *
     * @return array
     */
    public function getBundles(RadAppKernel $kernel)
    {
        if (!$this->bundlesCache->isFresh()) {
            $this->bundlesCache->write(
                $this->generateBundlesCache($kernel, $this->bundles),
                array(new FileResource((string) $this->projectCache))
            );
        }

        return require($this->bundlesCache);
    }

    /**
     * Returns configuration parameters.
     *
     * @return array
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Updates configuration from specified configuration file.
     *
     * @param string $path        Configuration file path
     * @param string $environment Environment name
     */
    private function updateFromFile($path, $environment)
    {
        $config = Yaml::parse($path);

        if (isset($config['name'])) {
            $this->projectName     = $config['name'];
            $this->applicationName = preg_replace('/(?:.*\\\)?([^\\\]+)$/', '$1', $config['name']);
        } else {
            throw new \InvalidArgumentException(
                'Specify your project `name` inside config/project.yml or config/project.local.yml'
            );
        }

        if (isset($config['all'])) {
            $this->loadSettings($config['all']);
        }
        if (isset($config[$environment])) {
            $this->loadSettings($config[$environment]);
        }
    }

    /**
     * Loads specific settings from array.
     *
     * @param array $settings Settings array
     */
    private function loadSettings(array $settings)
    {
        if (isset($settings['bundles'])) {
            foreach ($settings['bundles'] as $class => $constr) {
                $this->bundles[$class] = $constr;
            }
        }

        if (isset($settings['parameters'])) {
            foreach ($settings['parameters'] as $key => $val) {
                $this->parameters[$key] = $val;
            }
        }

        if (isset($settings['configs'])) {
            foreach ($settings['configs'] as $config) {
                $this->configs[] = $config;
            }
        }
    }

    /**
     * Generates bundles cache string (*.php array file).
     *
     * @param RadAppKernel $kernel  Project kernel
     * @param array        $bundles List of bundle classes
     *
     * @return string
     */
    private function generateBundlesCache(RadAppKernel $kernel, array $bundles)
    {
        $cache = "<?php return array(\n";

        foreach ($bundles as $class => $arguments) {
            $arguments = array_map(function($argument) {
                if ('@' === substr($argument, 0, 1)) {
                    return '$'.substr($argument, 1);
                }
                if (is_numeric($argument)) {
                    return $argument;
                }

                return '"'.$argument.'"';
            }, (array) $arguments);

            if (!class_exists($class)) {
                throw new \InvalidArgumentException(sprintf(
                    'Bundle class "%s" does not exists or can not be found.',
                    $class
                ));
            }

            $cache .= sprintf(
                "    new %s(%s),\n", $class, implode(', ', $arguments)
            );
        }

        $cache .= sprintf(
            "    new Knp\Bundle\RadBundle\Bundle\ApplicationBundle('%s', '%s'),\n",
            $this->getProjectName(),
            $kernel->getRootDir().'/src'
        );

        $cache .= ");";

        return $cache;
    }
}
