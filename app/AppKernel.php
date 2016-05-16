<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Symfony\Bundle\MonologBundle\MonologBundle(),
            new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
            new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),

            new Tagcade\Bundle\AppBundle\TagcadeAppBundle(),
            new Leezy\PheanstalkBundle\LeezyPheanstalkBundle(),
            new Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle(),

        );

        if (in_array($this->getEnvironment(), array('dev', 'test'), true)) {
            $bundles[] = new Symfony\Bundle\DebugBundle\DebugBundle();
            $bundles[] = new Sensio\Bundle\DistributionBundle\SensioDistributionBundle();
            $bundles[] = new Sensio\Bundle\GeneratorBundle\SensioGeneratorBundle();
        }

        return $bundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load($this->getRootDir().'/config/config_'.$this->getEnvironment().'.yml');
    }


    public function getCacheDir()
    {
        if ($this->isRunningOnDevelopmentVM()) {
            return '/dev/shm/tagcade-unified-directory-monitor/cache/' .  $this->environment;
        }

        return parent::getCacheDir();
    }

    public function getLogDir()
    {
        if ($this->isRunningOnDevelopmentVM()) {
            return '/dev/shm/tagcade-unified-directory-monitor/logs';
        }

        return parent::getLogDir();
    }

    /**
     * Checks that an environment variable is set and has a truthy value
     *
     * @param string $variable
     * @return bool
     */
    protected function checkForEnvironmentVariable($variable)
    {
        return isset($_SERVER[$variable]) && (bool) $_SERVER[$variable];
    }

    /**
     * The application is in development mode if an environment variable TAGCADE_DEV is set
     * and an environment variable TAGCADE_PROD is not set
     *
     * @return bool
     */
    protected function isRunningOnDevelopmentVM()
    {
        return !$this->checkForEnvironmentVariable('TAGCADE_PROD') && $this->checkForEnvironmentVariable('TAGCADE_DEV');
    }
}
