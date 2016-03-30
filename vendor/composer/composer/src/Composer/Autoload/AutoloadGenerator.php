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

namespace Composer\Autoload;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use Composer\Script\ScriptEvents;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AutoloadGenerator
{
    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    /**
     * @var IOInterface
     */
    private $io;

    private $devMode = false;

    public function __construct(EventDispatcher $eventDispatcher, IOInterface $io = null)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->io = $io;
    }

    public function setDevMode($devMode = true)
    {
        $this->devMode = (boolean) $devMode;
    }

    public function dump(Config $config, InstalledRepositoryInterface $localRepo, PackageInterface $mainPackage, InstallationManager $installationManager, $targetDir, $scanPsr0Packages = false, $suffix = '')
    {
        $this->eventDispatcher->dispatchScript(ScriptEvents::PRE_AUTOLOAD_DUMP, $this->devMode, array(), array(
            'optimize' => (bool) $scanPsr0Packages,
        ));

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        $basePath = $filesystem->normalizePath(realpath(getcwd()));
        $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));
        $useGlobalIncludePath = (bool) $config->get('use-include-path');
        $prependAutoloader = $config->get('prepend-autoloader') === false ? 'false' : 'true';
        $classMapAuthoritative = $config->get('classmap-authoritative');
        $targetDir = $vendorPath.'/'.$targetDir;
        $filesystem->ensureDirectoryExists($targetDir);

        $vendorPathCode = $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true);
        $vendorPathCode52 = str_replace('__DIR__', 'dirname(__FILE__)', $vendorPathCode);
        $vendorPathToTargetDirCode = $filesystem->findShortestPathCode($vendorPath, realpath($targetDir), true);

        $appBaseDirCode = $filesystem->findShortestPathCode($vendorPath, $basePath, true);
        $appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);

        $namespacesFile = <<<EOF
<?php

// autoload_namespaces.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        $psr4File = <<<EOF
<?php

// autoload_psr4.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        // Collect information from all packages.
        $packageMap = $this->buildPackageMap($installationManager, $mainPackage, $localRepo->getCanonicalPackages());
        $autoloads = $this->parseAutoloads($packageMap, $mainPackage);

        // Process the 'psr-0' base directories.
        foreach ($autoloads['psr-0'] as $namespace => $paths) {
            $exportedPaths = array();
            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
            }
            $exportedPrefix = var_export($namespace, true);
            $namespacesFile .= "    $exportedPrefix => ";
            $namespacesFile .= "array(".implode(', ', $exportedPaths)."),\n";
        }
        $namespacesFile .= ");\n";

        // Process the 'psr-4' base directories.
        foreach ($autoloads['psr-4'] as $namespace => $paths) {
            $exportedPaths = array();
            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
            }
            $exportedPrefix = var_export($namespace, true);
            $psr4File .= "    $exportedPrefix => ";
            $psr4File .= "array(".implode(', ', $exportedPaths)."),\n";
        }
        $psr4File .= ");\n";

        $classmapFile = <<<EOF
<?php

// autoload_classmap.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        // add custom psr-0 autoloading if the root package has a target dir
        $targetDirLoader = null;
        $mainAutoload = $mainPackage->getAutoload();
        if ($mainPackage->getTargetDir() && !empty($mainAutoload['psr-0'])) {
            $levels = count(explode('/', $filesystem->normalizePath($mainPackage->getTargetDir())));
            $prefixes = implode(', ', array_map(function ($prefix) {
                return var_export($prefix, true);
            }, array_keys($mainAutoload['psr-0'])));
            $baseDirFromTargetDirCode = $filesystem->findShortestPathCode($targetDir, $basePath, true);

            $targetDirLoader = <<<EOF

    public static function autoload(\$class)
    {
        \$dir = $baseDirFromTargetDirCode . '/';
        \$prefixes = array($prefixes);
        foreach (\$prefixes as \$prefix) {
            if (0 !== strpos(\$class, \$prefix)) {
                continue;
            }
            \$path = \$dir . implode('/', array_slice(explode('\\\\', \$class), $levels)).'.php';
            if (!\$path = stream_resolve_include_path(\$path)) {
                return false;
            }
            require \$path;

            return true;
        }
    }

EOF;
        }

        // flatten array
        $classMap = array();
        if ($scanPsr0Packages) {
            // Scan the PSR-0/4 directories for class files, and add them to the class map
            foreach (array('psr-0', 'psr-4') as $psrType) {
                foreach ($autoloads[$psrType] as $namespace => $paths) {
                    foreach ($paths as $dir) {
                        $dir = $filesystem->normalizePath($filesystem->isAbsolutePath($dir) ? $dir : $basePath.'/'.$dir);
                        if (!is_dir($dir)) {
                            continue;
                        }
                        $whitelist = sprintf(
                            '{%s/%s.+(?<!(?<!/)Test\.php)$}',
                            preg_quote($dir),
                            ($psrType === 'psr-0' && strpos($namespace, '_') === false) ? preg_quote(strtr($namespace, '\\', '/')) : ''
                        );

                        $namespaceFilter = $namespace === '' ? null : $namespace;
                        foreach (ClassMapGenerator::createMap($dir, $whitelist, $this->io, $namespaceFilter) as $class => $path) {
                            if (!isset($classMap[$class])) {
                                $path = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
                                $classMap[$class] = $path.",\n";
                            }
                        }
                    }
                }
            }
        }

        foreach ($autoloads['classmap'] as $dir) {
            foreach (ClassMapGenerator::createMap($dir, null, $this->io) as $class => $path) {
                $path = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
                $classMap[$class] = $path.",\n";
            }
        }

        ksort($classMap);
        foreach ($classMap as $class => $code) {
            $classmapFile .= '    '.var_export($class, true).' => '.$code;
        }
        $classmapFile .= ");\n";

        if (!$suffix) {
            if (is_readable($vendorPath.'/autoload.php')) {
                $content = file_get_contents($vendorPath.'/autoload.php');
                if (preg_match('{ComposerAutoloaderInit([^:\s]+)::}', $content, $match)) {
                    $suffix = $match[1];
                }
            }

            if (!$suffix) {
                $suffix = $config->get('autoloader-suffix') ?: md5(uniqid('', true));
            }
        }

        file_put_contents($targetDir.'/autoload_namespaces.php', $namespacesFile);
        file_put_contents($targetDir.'/autoload_psr4.php', $psr4File);
        file_put_contents($targetDir.'/autoload_classmap.php', $classmapFile);
        if ($includePathFile = $this->getIncludePathsFile($packageMap, $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
            file_put_contents($targetDir.'/include_paths.php', $includePathFile);
        }
        if ($includeFilesFile = $this->getIncludeFilesFile($autoloads['files'], $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
            file_put_contents($targetDir.'/autoload_files.php', $includeFilesFile);
        }
        file_put_contents($vendorPath.'/autoload.php', $this->getAutoloadFile($vendorPathToTargetDirCode, $suffix));
        file_put_contents($targetDir.'/autoload_real.php', $this->getAutoloadRealFile(true, (bool) $includePathFile, $targetDirLoader, (bool) $includeFilesFile, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath, $prependAutoloader, $classMapAuthoritative));

        // use stream_copy_to_stream instead of copy
        // to work around https://bugs.php.net/bug.php?id=64634
        $sourceLoader = fopen(__DIR__.'/ClassLoader.php', 'r');
        $targetLoader = fopen($targetDir.'/ClassLoader.php', 'w+');
        stream_copy_to_stream($sourceLoader, $targetLoader);
        fclose($sourceLoader);
        fclose($targetLoader);
        unset($sourceLoader, $targetLoader);

        $this->eventDispatcher->dispatchScript(ScriptEvents::POST_AUTOLOAD_DUMP, $this->devMode, array(), array(
            'optimize' => (bool) $scanPsr0Packages,
        ));
    }

    public function buildPackageMap(InstallationManager $installationManager, PackageInterface $mainPackage, array $packages)
    {
        // build package => install path map
        $packageMap = array(array($mainPackage, ''));

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }
            $this->validatePackage($package);

            $packageMap[] = array(
                $package,
                $installationManager->getInstallPath($package),
            );
        }

        return $packageMap;
    }

    /**
     * @param PackageInterface $package
     *
     * @throws \InvalidArgumentException Throws an exception, if the package has illegal settings.
     */
    protected function validatePackage(PackageInterface $package)
    {
        $autoload = $package->getAutoload();
        if (!empty($autoload['psr-4']) && null !== $package->getTargetDir()) {
            $name = $package->getName();
            $package->getTargetDir();
            throw new \InvalidArgumentException("PSR-4 autoloading is incompatible with the target-dir property, remove the target-dir in package '$name'.");
        }
        if (!empty($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $namespace => $dirs) {
                if ($namespace !== '' && '\\' !== substr($namespace, -1)) {
                    throw new \InvalidArgumentException("psr-4 namespaces must end with a namespace separator, '$namespace' does not, use '$namespace\\'.");
                }
            }
        }
    }

    /**
     * Compiles an ordered list of namespace => path mappings
     *
     * @param  array            $packageMap  array of array(package, installDir-relative-to-composer.json)
     * @param  PackageInterface $mainPackage root package instance
     * @return array            array('psr-0' => array('Ns\\Foo' => array('installDir')))
     */
    public function parseAutoloads(array $packageMap, PackageInterface $mainPackage)
    {
        $mainPackageMap = array_shift($packageMap);
        $sortedPackageMap = $this->sortPackageMap($packageMap);
        $sortedPackageMap[] = $mainPackageMap;
        array_unshift($packageMap, $mainPackageMap);

        $psr0 = $this->parseAutoloadsType($packageMap, 'psr-0', $mainPackage);
        $psr4 = $this->parseAutoloadsType($packageMap, 'psr-4', $mainPackage);
        $classmap = $this->parseAutoloadsType($sortedPackageMap, 'classmap', $mainPackage);
        $files = $this->parseAutoloadsType($sortedPackageMap, 'files', $mainPackage);

        krsort($psr0);
        krsort($psr4);

        return array('psr-0' => $psr0, 'psr-4' => $psr4, 'classmap' => $classmap, 'files' => $files);
    }

    /**
     * Registers an autoloader based on an autoload map returned by parseAutoloads
     *
     * @param  array       $autoloads see parseAutoloads return value
     * @return ClassLoader
     */
    public function createLoader(array $autoloads)
    {
        $loader = new ClassLoader();

        if (isset($autoloads['psr-0'])) {
            foreach ($autoloads['psr-0'] as $namespace => $path) {
                $loader->add($namespace, $path);
            }
        }

        if (isset($autoloads['psr-4'])) {
            foreach ($autoloads['psr-4'] as $namespace => $path) {
                $loader->addPsr4($namespace, $path);
            }
        }

        return $loader;
    }

    protected function getIncludePathsFile(array $packageMap, Filesystem $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)
    {
        $includePaths = array();

        foreach ($packageMap as $item) {
            list($package, $installPath) = $item;

            if (null !== $package->getTargetDir() && strlen($package->getTargetDir()) > 0) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($package->getIncludePaths() as $includePath) {
                $includePath = trim($includePath, '/');
                $includePaths[] = empty($installPath) ? $includePath : $installPath.'/'.$includePath;
            }
        }

        if (!$includePaths) {
            return;
        }

        $includePathsCode = '';
        foreach ($includePaths as $path) {
            $includePathsCode .= "    " . $this->getPathCode($filesystem, $basePath, $vendorPath, $path) . ",\n";
        }

        return <<<EOF
<?php

// include_paths.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(
$includePathsCode);

EOF;
    }

    protected function getIncludeFilesFile(array $files, Filesystem $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)
    {
        $filesCode = '';
        foreach ($files as $functionFile) {
            $filesCode .= '    '.$this->getPathCode($filesystem, $basePath, $vendorPath, $functionFile).",\n";
        }

        if (!$filesCode) {
            return false;
        }

        return <<<EOF
<?php

// autoload_files.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(
$filesCode);

EOF;
    }

    protected function getPathCode(Filesystem $filesystem, $basePath, $vendorPath, $path)
    {
        if (!$filesystem->isAbsolutePath($path)) {
            $path = $basePath . '/' . $path;
        }
        $path = $filesystem->normalizePath($path);

        $baseDir = '';
        if (strpos($path.'/', $vendorPath.'/') === 0) {
            $path = substr($path, strlen($vendorPath));
            $baseDir = '$vendorDir';

            if ($path !== false) {
                $baseDir .= " . ";
            }
        } else {
            $path = $filesystem->normalizePath($filesystem->findShortestPath($basePath, $path, true));
            if (!$filesystem->isAbsolutePath($path)) {
                $baseDir = '$baseDir . ';
                $path = '/' . $path;
            }
        }

        if (preg_match('/\.phar$/', $path)) {
            $baseDir = "'phar://' . " . $baseDir;
        }

        return $baseDir . (($path !== false) ? var_export($path, true) : "");
    }

    protected function getAutoloadFile($vendorPathToTargetDirCode, $suffix)
    {
        return <<<AUTOLOAD
<?php

// autoload.php @generated by Composer

require_once $vendorPathToTargetDirCode . '/autoload_real.php';

return ComposerAutoloaderInit$suffix::getLoader();

AUTOLOAD;
    }

    protected function getAutoloadRealFile($useClassMap, $useIncludePath, $targetDirLoader, $useIncludeFiles, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath, $prependAutoloader, $classMapAuthoritative)
    {
        // TODO the class ComposerAutoloaderInit should be revert to a closure
        // when APC has been fixed:
        // - https://github.com/composer/composer/issues/959
        // - https://bugs.php.net/bug.php?id=52144
        // - https://bugs.php.net/bug.php?id=61576
        // - https://bugs.php.net/bug.php?id=59298

        $file = <<<HEADER
<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit$suffix
{
    private static \$loader;

    public static function loadClassLoader(\$class)
    {
        if ('Composer\\Autoload\\ClassLoader' === \$class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    public static function getLoader()
    {
        if (null !== self::\$loader) {
            return self::\$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'), true, $prependAutoloader);
        self::\$loader = \$loader = new \\Composer\\Autoload\\ClassLoader();
        spl_autoload_unregister(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'));


HEADER;

        if ($useIncludePath) {
            $file .= <<<'INCLUDE_PATH'
        $includePaths = require __DIR__ . '/include_paths.php';
        array_push($includePaths, get_include_path());
        set_include_path(join(PATH_SEPARATOR, $includePaths));


INCLUDE_PATH;
        }

        $file .= <<<'PSR0'
        $map = require __DIR__ . '/autoload_namespaces.php';
        foreach ($map as $namespace => $path) {
            $loader->set($namespace, $path);
        }


PSR0;

        $file .= <<<'PSR4'
        $map = require __DIR__ . '/autoload_psr4.php';
        foreach ($map as $namespace => $path) {
            $loader->setPsr4($namespace, $path);
        }


PSR4;

        if ($useClassMap) {
            $file .= <<<'CLASSMAP'
        $classMap = require __DIR__ . '/autoload_classmap.php';
        if ($classMap) {
            $loader->addClassMap($classMap);
        }


CLASSMAP;
        }

        if ($classMapAuthoritative) {
            $file .= <<<'CLASSMAPAUTHORITATIVE'
        $loader->setClassMapAuthoritative(true);

CLASSMAPAUTHORITATIVE;
        }

        if ($useGlobalIncludePath) {
            $file .= <<<'INCLUDEPATH'
        $loader->setUseIncludePath(true);

INCLUDEPATH;
        }

        if ($targetDirLoader) {
            $file .= <<<REGISTER_AUTOLOAD
        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'autoload'), true, true);


REGISTER_AUTOLOAD;
        }

        $file .= <<<REGISTER_LOADER
        \$loader->register($prependAutoloader);


REGISTER_LOADER;

        if ($useIncludeFiles) {
            $file .= <<<INCLUDE_FILES
        \$includeFiles = require __DIR__ . '/autoload_files.php';
        foreach (\$includeFiles as \$file) {
            composerRequire$suffix(\$file);
        }


INCLUDE_FILES;
        }

        $file .= <<<METHOD_FOOTER
        return \$loader;
    }

METHOD_FOOTER;

        $file .= $targetDirLoader;

        return $file . <<<FOOTER
}

function composerRequire$suffix(\$file)
{
    require \$file;
}

FOOTER;
    }

    protected function parseAutoloadsType(array $packageMap, $type, PackageInterface $mainPackage)
    {
        $autoloads = array();

        foreach ($packageMap as $item) {
            list($package, $installPath) = $item;

            $autoload = $package->getAutoload();
            if ($this->devMode && $package === $mainPackage) {
                $autoload = array_merge_recursive($autoload, $package->getDevAutoload());
            }

            // skip misconfigured packages
            if (!isset($autoload[$type]) || !is_array($autoload[$type])) {
                continue;
            }
            if (null !== $package->getTargetDir() && $package !== $mainPackage) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($autoload[$type] as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    if (($type === 'files' || $type === 'classmap') && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        // remove target-dir from file paths of the root package
                        if ($package === $mainPackage) {
                            $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(array('/', '\\'), '<dirsep>', $package->getTargetDir())));
                            $path = ltrim(preg_replace('{^'.$targetDir.'}', '', ltrim($path, '\\/')), '\\/');
                        } else {
                            // add target-dir from file paths that don't have it
                            $path = $package->getTargetDir() . '/' . $path;
                        }
                    }

                    $relativePath = empty($installPath) ? (empty($path) ? '.' : $path) : $installPath.'/'.$path;

                    if ($type === 'files' || $type === 'classmap') {
                        $autoloads[] = $relativePath;
                        continue;
                    }

                    $autoloads[$namespace][] = $relativePath;
                }
            }
        }

        return $autoloads;
    }

    /**
     * Sorts packages by dependency weight
     *
     * Packages of equal weight retain the original order
     *
     * @param  array $packageMap
     * @return array
     */
    protected function sortPackageMap(array $packageMap)
    {
        $packages = array();
        $paths = array();
        $usageList = array();

        foreach ($packageMap as $item) {
            list($package, $path) = $item;
            $name = $package->getName();
            $packages[$name] = $package;
            $paths[$name] = $path;

            foreach (array_merge($package->getRequires(), $package->getDevRequires()) as $link) {
                $target = $link->getTarget();
                $usageList[$target][] = $name;
            }
        }

        $computing = array();
        $computed = array();
        $computeImportance = function ($name) use (&$computeImportance, &$computing, &$computed, $usageList) {
            // reusing computed importance
            if (isset($computed[$name])) {
                return $computed[$name];
            }

            // canceling circular dependency
            if (isset($computing[$name])) {
                return 0;
            }

            $computing[$name] = true;
            $weight = 0;

            if (isset($usageList[$name])) {
                foreach ($usageList[$name] as $user) {
                    $weight -= 1 - $computeImportance($user);
                }
            }

            unset($computing[$name]);
            $computed[$name] = $weight;

            return $weight;
        };

        $weightList = array();

        foreach ($packages as $name => $package) {
            $weight = $computeImportance($name);
            $weightList[$name] = $weight;
        }

        $stable_sort = function (&$array) {
            static $transform, $restore;

            $i = 0;

            if (!$transform) {
                $transform = function (&$v, $k) use (&$i) {
                    $v = array($v, ++$i, $k, $v);
                };

                $restore = function (&$v, $k) {
                    $v = $v[3];
                };
            }

            array_walk($array, $transform);
            asort($array);
            array_walk($array, $restore);
        };

        $stable_sort($weightList);

        $sortedPackageMap = array();

        foreach (array_keys($weightList) as $name) {
            $sortedPackageMap[] = array($packages[$name], $paths[$name]);
        }

        return $sortedPackageMap;
    }
}