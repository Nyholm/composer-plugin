<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\CommandEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Puli\RepositoryManager\Config\Config;
use Puli\RepositoryManager\Discovery\DiscoveryManager;
use Puli\RepositoryManager\Environment\ProjectEnvironment;
use Puli\RepositoryManager\ManagerFactory;
use Puli\RepositoryManager\Package\PackageFile\RootPackageFileManager;
use Puli\RepositoryManager\Package\PackageManager;
use Puli\RepositoryManager\Repository\RepositoryManager;
use RuntimeException;
use Webmozart\PathUtil\Path;

/**
 * A Puli plugin for Composer.
 *
 * The plugin updates the Puli package repository based on the Composer
 * packages whenever `composer install` or `composer update` is executed.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The name of the installer.
     */
    const INSTALLER_NAME = 'Composer';

    /**
     * @var ManagerFactory
     */
    private $managerFactory;

    /**
     * @var ProjectEnvironment
     */
    private $projectEnvironment;

    /**
     * @var bool
     */
    private $runPostInstall = true;

    /**
     * @var bool
     */
    private $runPostAutoloadDump = true;

    /**
     * Creates the plugin.
     */
    public function __construct()
    {
        $this->managerFactory = new ManagerFactory();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postInstall',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'postAutoloadDump',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getEventDispatcher()->addSubscriber($this);
    }

    /**
     * Updates the Puli repository after Composer installations/updates.
     *
     * @param CommandEvent $event The Composer event.
     */
    public function postInstall(CommandEvent $event)
    {
        // This method is called twice. Run it only once.
        if (!$this->runPostInstall) {
            return;
        }

        $this->runPostInstall = false;

        $io = $event->getIO();
        $environment = $this->getProjectEnvironment();
        $packageManager = $this->managerFactory->createPackageManager($environment);

        $this->removeRemovedPackages($packageManager, $io, $event->getComposer());
        $this->installNewPackages($packageManager, $io, $event->getComposer());

        // TODO inject logger
        $packageFileManager = $this->managerFactory->createRootPackageFileManager($environment);
        $repoManager = $this->managerFactory->createRepositoryManager($environment, $packageManager);
        $discoveryManager = $this->managerFactory->createDiscoveryManager($environment, $packageManager);

        $this->copyComposerName($packageFileManager, $event->getComposer());
        $this->buildRepository($repoManager, $io);
        $this->buildDiscovery($discoveryManager, $io);
    }

    public function postAutoloadDump(Event $event)
    {
        // This method is called twice. Run it only once.
        if (!$this->runPostAutoloadDump) {
            return;
        }

        $this->runPostAutoloadDump = false;

        $io = $event->getIO();
        $rootDir = getcwd();
        $environment = $this->getProjectEnvironment();
        $puliConfig = $environment->getConfig();
        $compConfig = $event->getComposer()->getConfig();
        $vendorDir = $compConfig->get('vendor-dir');

        // On TravisCI, $vendorDir is a relative path. Probably an old Composer
        // build or something. Usually, $vendorDir should be absolute already.
        $vendorDir = Path::makeAbsolute($vendorDir, $rootDir);

        $autoloadFile = $vendorDir.'/autoload.php';
        $classMapFile = $vendorDir.'/composer/autoload_classmap.php';

        $factoryClass = $puliConfig->get(Config::FACTORY_CLASS);
        $factoryFile = Path::makeAbsolute($puliConfig->get(Config::FACTORY_FILE), $rootDir);

        $this->insertFactoryClassConstant($io, $autoloadFile, $factoryClass);
        $this->insertFactoryClassMap($io, $classMapFile, $vendorDir, $factoryClass, $factoryFile);
    }

    private function installNewPackages(PackageManager $packageManager, IOInterface $io, Composer $composer)
    {
        $io->write('<info>Looking for new Puli packages</info>');

        $repositoryManager = $composer->getRepositoryManager();
        $installationManager = $composer->getInstallationManager();
        $packages = $repositoryManager->getLocalRepository()->getPackages();
        $rootDir = $packageManager->getRootPackage()->getInstallPath();

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            $installPath = $installationManager->getInstallPath($package);

            // Already installed?
            if ($packageManager->isPackageInstalled($installPath)) {
                continue;
            }

            $io->write(sprintf(
                'Installing <info>%s</info> (<comment>%s</comment>)',
                $package->getName(),
                Path::makeRelative($installPath, $rootDir)
            ));

            $packageManager->installPackage($installPath, $package->getName(), self::INSTALLER_NAME);
        }
    }

    private function removeRemovedPackages(PackageManager $packageManager, IOInterface $io, Composer $composer)
    {
        $io->write('<info>Looking for removed Puli packages</info>');

        $repositoryManager = $composer->getRepositoryManager();
        $packages = $repositoryManager->getLocalRepository()->getPackages();
        $packageNames = array();
        $rootDir = $packageManager->getRootPackage()->getInstallPath();

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            $packageNames[$package->getName()] = true;
        }

        foreach ($packageManager->getPackagesByInstaller(self::INSTALLER_NAME) as $package) {
            if (!isset($packageNames[$package->getName()])) {
                $installPath = $package->getInstallPath();

                $io->write(sprintf(
                    'Removing <info>%s</info> (<comment>%s</comment>)',
                    $package->getName(),
                    Path::makeRelative($installPath, $rootDir)
                ));

                $packageManager->removePackage($package->getName());
            }
        }
    }

    private function copyComposerName(RootPackageFileManager $packageFileManager, Composer $composer)
    {
        $packageFileManager->setPackageName($composer->getPackage()->getName());
    }

    private function buildRepository(RepositoryManager $repositoryManager, IOInterface $io)
    {
        $io->write('<info>Building Puli resource repository</info>');

        $repositoryManager->clearRepository();
        $repositoryManager->buildRepository();
    }

    private function buildDiscovery(DiscoveryManager $discoveryManager, IOInterface $io)
    {
        $io->write('<info>Building Puli resource discovery</info>');

        $discoveryManager->clearDiscovery();
        $discoveryManager->buildDiscovery();
    }

    private function insertFactoryClassConstant(IOInterface $io, $autoloadFile, $factoryClass)
    {
        if (!file_exists($autoloadFile)) {
            throw new PuliPluginException(sprintf(
                'Could not adjust autoloader: The file %s was not found.',
                $autoloadFile
            ));
        }

        $io->write('<info>Generating PULI_FACTORY_CLASS constant</info>');

        $contents = file_get_contents($autoloadFile);
        $escFactoryClass = var_export($factoryClass, true);
        $constant = "define('PULI_FACTORY_CLASS', $escFactoryClass);\n\n";

        // Regex modifiers:
        // "m": \s matches newlines
        // "D": $ matches at EOF only
        // Translation: insert before the last "return" in the file
        $contents = preg_replace('/\n(?=return [^;]+;\s*$)/mD', "\n".$constant,
            $contents);

        file_put_contents($autoloadFile, $contents);
    }

    private function insertFactoryClassMap(IOInterface $io, $classMapFile, $vendorDir, $factoryClass, $factoryFile)
    {
        if (!file_exists($classMapFile)) {
            throw new PuliPluginException(sprintf(
                'Could not adjust autoloader: The file %s was not found.',
                $classMapFile
            ));
        }

        $io->write("<info>Registering $factoryClass with the class-map autoloader</info>");

        $relFactoryFile = Path::makeRelative($factoryFile, $vendorDir);
        $escFactoryClass = var_export($factoryClass, true);
        $escFactoryFile = var_export('/'.$relFactoryFile, true);
        $classMap = "\n    $escFactoryClass => \$vendorDir . $escFactoryFile,";

        $contents = file_get_contents($classMapFile);

        // Regex modifiers:
        // "m": \s matches newlines
        // "D": $ matches at EOF only
        // Translation: insert before the last ");" in the file
        $contents = preg_replace('/\n(?=\);\s*$)/mD', "\n".$classMap, $contents);

        file_put_contents($classMapFile, $contents);
    }

    /**
     * Returns Puli's project environment.
     *
     * @return ProjectEnvironment The project environment.
     */
    private function getProjectEnvironment()
    {
        if (!$this->projectEnvironment) {
            $this->projectEnvironment = $this->managerFactory->createProjectEnvironment(getcwd());
        }

        return $this->projectEnvironment;
    }
}
