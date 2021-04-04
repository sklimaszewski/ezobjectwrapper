<?php

namespace Kaliop\eZObjectWrapperBundle\DependencyInjection;

use Doctrine\Common\Annotations\AnnotationReader;
use Kaliop\eZObjectWrapperBundle\Core\EntityManager;
use Kaliop\eZObjectWrapperBundle\Core\Mapping\Entity;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class KaliopeZObjectWrapperExtension extends Extension
{
    protected $entityManagerService = EntityManager::class;

    public function getAlias()
    {
        return 'ezobject_wrapper';
    }

    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->injectConfiguration($config, $container);
    }

    protected function injectConfiguration(array $config, ContainerBuilder $container)
    {
        if ($container->hasDefinition($this->entityManagerService)) {
            $factoryDefinition = $container->findDefinition($this->entityManagerService);

            $factoryDefinition->addMethodCall('registerDefaultClass', array($config['default_repository_class']));

            foreach ($config['class_map'] as $type => $class) {
                $factoryDefinition
                    ->addMethodCall('registerClass', array($class, $type));
            }
            
            $reader = new AnnotationReader();
            foreach ($config['mappings'] as $path) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveCallbackFilterIterator(
                        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                        function (\SplFileInfo $current) {
                            return '.' !== substr($current->getBasename(), 0, 1);
                        }
                    ),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $file) {
                    if (!$file->isFile() || '.php' !== substr($file->getFilename(), -4)) {
                        continue;
                    }

                    if ($class = $this->findClass($file)) {
                        $refl = new \ReflectionClass($class);
                        if ($refl->isAbstract()) {
                            continue;
                        }

                        $classAnnotations = $reader->getClassAnnotations($refl);
                        foreach ($classAnnotations as $annot) {
                            if ($annot instanceof Entity) {
                                $factoryDefinition->addMethodCall('registerClass', array(
                                    $annot->repositoryClass ? $annot->repositoryClass : $config['default_repository_class'],
                                    $annot->contentType
                                ));
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns the full class name for the first class in the file.
     *
     * @return string|false Full class name if found, false otherwise
     */
    protected function findClass(string $file)
    {
        $class = false;
        $namespace = false;
        $tokens = token_get_all(file_get_contents($file));

        if (1 === \count($tokens) && \T_INLINE_HTML === $tokens[0][0]) {
            throw new \InvalidArgumentException(sprintf('The file "%s" does not contain PHP code. Did you forgot to add the "<?php" start tag at the beginning of the file?', $file));
        }

        $nsTokens = [\T_NS_SEPARATOR => true, \T_STRING => true];
        if (\defined('T_NAME_QUALIFIED')) {
            $nsTokens[T_NAME_QUALIFIED] = true;
        }

        for ($i = 0; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];

            if (!isset($token[1])) {
                continue;
            }

            if (true === $class && \T_STRING === $token[0]) {
                return $namespace.'\\'.$token[1];
            }

            if (true === $namespace && isset($nsTokens[$token[0]])) {
                $namespace = $token[1];
                while (isset($tokens[++$i][1], $nsTokens[$tokens[$i][0]])) {
                    $namespace .= $tokens[$i][1];
                }
                $token = $tokens[$i];
            }

            if (\T_CLASS === $token[0]) {
                // Skip usage of ::class constant and anonymous classes
                $skipClassToken = false;
                for ($j = $i - 1; $j > 0; --$j) {
                    if (!isset($tokens[$j][1])) {
                        break;
                    }

                    if (\T_DOUBLE_COLON === $tokens[$j][0] || \T_NEW === $tokens[$j][0]) {
                        $skipClassToken = true;
                        break;
                    } elseif (!\in_array($tokens[$j][0], [\T_WHITESPACE, \T_DOC_COMMENT, \T_COMMENT])) {
                        break;
                    }
                }

                if (!$skipClassToken) {
                    $class = true;
                }
            }

            if (\T_NAMESPACE === $token[0]) {
                $namespace = true;
            }
        }

        return false;
    }
}
