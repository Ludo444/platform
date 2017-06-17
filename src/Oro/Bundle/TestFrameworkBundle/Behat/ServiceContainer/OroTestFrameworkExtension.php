<?php

namespace Oro\Bundle\TestFrameworkBundle\Behat\ServiceContainer;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\ServiceContainer\ContextExtension;
use Behat\MinkExtension\ServiceContainer\MinkExtension;
use Behat\Symfony2Extension\ServiceContainer\Symfony2Extension;
use Behat\Symfony2Extension\Suite\SymfonyBundleSuite;
use Behat\Symfony2Extension\Suite\SymfonySuiteGenerator;
use Behat\Testwork\EventDispatcher\ServiceContainer\EventDispatcherExtension;
use Behat\Testwork\ServiceContainer\Extension as TestworkExtension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Behat\Testwork\ServiceContainer\ServiceProcessor;
use Oro\Bundle\TestFrameworkBundle\Behat\Artifacts\ArtifactsHandlerInterface;
use Oro\Bundle\TestFrameworkBundle\Behat\Cli\AvailableSuitesGroupController;
use Oro\Bundle\TestFrameworkBundle\Behat\Driver\OroSelenium2Factory;
use Oro\Bundle\TestFrameworkBundle\Behat\Isolation\IsolatorInterface;
use Oro\Bundle\TestFrameworkBundle\Behat\Isolation\MessageQueueIsolatorAwareInterface;
use Oro\Bundle\TestFrameworkBundle\Behat\Isolation\MessageQueueIsolatorInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

class OroTestFrameworkExtension implements TestworkExtension
{
    const ISOLATOR_TAG = 'oro_behat.isolator';
    const SUITE_AWARE_TAG = 'suite_aware';
    const CONFIG_PATH = '/Tests/Behat/behat.yml';
    const MESSAGE_QUEUE_ISOLATOR_AWARE_TAG = 'message_queue_isolator_aware';
    const ELEMENTS_CONFIG_ROOT = 'elements';
    const PAGES_CONFIG_ROOT = 'pages';
    const SUITES_CONFIG_ROOT = 'suites';

    /**
     * @var ServiceProcessor
     */
    private $processor;

    /**
     * Initializes compiler pass.
     *
     * @param null|ServiceProcessor $processor
     */
    public function __construct(ServiceProcessor $processor = null)
    {
        $this->processor = $processor ? : new ServiceProcessor();
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $container->get(Symfony2Extension::KERNEL_ID)->registerBundles();
        $this->processBundleBehatConfigurations($container);
        $this->processBundleAutoload($container);
        $this->injectMessageQueueIsolator($container);
        $this->processIsolationSubscribers($container);
        $this->processSuiteAwareSubscriber($container);
        $this->processClassResolvers($container);
        $this->processArtifactHandlers($container);
        $this->replaceSessionListener($container);
        $container->get(Symfony2Extension::KERNEL_ID)->shutdown();
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigKey()
    {
        return 'oro_test';
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(ExtensionManager $extensionManager)
    {
        /** @var MinkExtension $minkExtension */
        $minkExtension = $extensionManager->getExtension('mink');
        $minkExtension->registerDriverFactory(new OroSelenium2Factory());
    }

    /**
     * {@inheritdoc}
     */
    public function configure(ArrayNodeDefinition $builder)
    {
        $builder
            ->children()
                ->arrayNode('application_suites')
                    ->prototype('scalar')->end()
                    ->info(
                        "Suites that applicable for application.\n".
                        'This suites will be run with --applicable-suites key in console'
                    )
                    ->defaultValue([])
                ->end()
                ->arrayNode('suite_groups')
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->prototype('scalar')->end()
                    ->end()
                ->end()
                ->variableNode('shared_contexts')
                    ->info('Contexts that added to all autoload bundles suites')
                    ->defaultValue([])
                ->end()
                ->scalarNode('reference_initializer_class')
                    ->defaultValue('Oro\Bundle\TestFrameworkBundle\Behat\Fixtures\ReferenceRepositoryInitializer')
                ->end()
                ->arrayNode('artifacts')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('handlers')
                        ->useAttributeAsKey('name')
                            ->prototype('variable')->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * {@inheritdoc}
     */
    public function load(ContainerBuilder $container, array $config)
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/config'));
        $loader->load('services.yml');
        $loader->load('isolators.yml');
        $loader->load('artifacts.yml');
        $loader->load('kernel_services.yml');

        $container->setParameter('oro_test.shared_contexts', $config['shared_contexts']);
        $container->setParameter('oro_test.application_suites', $config['application_suites']);
        $container->setParameter('oro_test.suite_groups', $config['suite_groups']);
        $container->setParameter('oro_test.artifacts.handler_configs', $config['artifacts']['handlers']);
        $container->setParameter('oro_test.reference_initializer_class', $config['reference_initializer_class']);
        // Remove reboot kernel after scenario because we have isolation in feature layer instead of scenario
        $container->getDefinition('symfony2_extension.context_initializer.kernel_aware')
            ->clearTag(EventDispatcherExtension::SUBSCRIBER_TAG);
    }

    /**
     * @param ContainerBuilder $container
     */
    private function processIsolationSubscribers(ContainerBuilder $container)
    {
        $isolators = [];
        $applicationContainer = $container->get(Symfony2Extension::KERNEL_ID)->getContainer();

        foreach ($container->findTaggedServiceIds(self::ISOLATOR_TAG) as $id => $attributes) {
            /** @var IsolatorInterface $isolator */
            $isolator = $container->get($id);

            if ($isolator->isApplicable($applicationContainer)) {
                $priority = isset($attributes[0]['priority']) ? $attributes[0]['priority'] : 0;
                $isolators[$priority][] = new Reference($id);
            }
        }

        // sort by priority and flatten
        krsort($isolators);
        $isolators = call_user_func_array('array_merge', $isolators);

        $container->getDefinition('oro_behat_extension.isolation.test_isolation_subscriber')->replaceArgument(
            0,
            $isolators
        );
    }

    /**
     * @param ContainerBuilder $container
     */
    private function processArtifactHandlers(ContainerBuilder $container)
    {
        $handlerConfigurations = $container->getParameter('oro_test.artifacts.handler_configs');
        $prettySubscriberDefinition = $container->getDefinition('oro_test.artifacts.pretty_artifacts_subscriber');
        $progressSubscriberDefinition = $container->getDefinition('oro_test.artifacts.progress_artifacts_subscriber');

        foreach ($container->findTaggedServiceIds('artifacts_handler') as $id => $attributes) {
            $handlerClass = $container->getDefinition($id)->getClass();

            if (!in_array(ArtifactsHandlerInterface::class, class_implements($handlerClass))) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" should implement "%s"',
                    $handlerClass,
                    ArtifactsHandlerInterface::class
                ));
            }

            /** @var ArtifactsHandlerInterface $handlerClass */
            if (empty($handlerConfigurations[$handlerClass::getConfigKey()])) {
                continue;
            }

            if (false === $handlerConfigurations[$handlerClass::getConfigKey()]) {
                continue;
            }

            $container->getDefinition($id)->replaceArgument(0, $handlerConfigurations[$handlerClass::getConfigKey()]);
            $prettySubscriberDefinition->addMethodCall('addArtifactHandler', [new Reference($id)]);
            $progressSubscriberDefinition->addMethodCall('addArtifactHandler', [new Reference($id)]);
        }
    }

    /**
     * @param ContainerBuilder $container
     */
    private function replaceSessionListener(ContainerBuilder $container)
    {
        $container
            ->getDefinition('mink.listener.sessions')
            ->setClass('Oro\Bundle\TestFrameworkBundle\Behat\Listener\SessionsListener');
    }

    /**
     * @param ContainerBuilder $container
     */
    private function injectMessageQueueIsolator(ContainerBuilder $container)
    {
        $applicationContainer = $container->get(Symfony2Extension::KERNEL_ID)->getContainer();
        $applicableIsolatorId = null;

        foreach ($container->findTaggedServiceIds(self::ISOLATOR_TAG) as $id => $attributes) {
            /** @var IsolatorInterface $isolator */
            $isolator = $container->get($id);

            if ($isolator->isApplicable($applicationContainer) && $isolator instanceof MessageQueueIsolatorInterface) {
                $applicableIsolatorId = $id;
                break;
            }
        }

        if (null === $applicableIsolatorId) {
            throw new RuntimeException('Not found any MessageQueue Isolator to inject into FixtureLoader');
        }

        foreach ($container->findTaggedServiceIds(self::MESSAGE_QUEUE_ISOLATOR_AWARE_TAG) as $id => $attributes) {
            if (!$container->hasDefinition($id)) {
                continue;
            }
            $definition = $container->getDefinition($id);

            if (is_a($definition->getClass(), MessageQueueIsolatorAwareInterface::class, true)) {
                $definition->addMethodCall('setMessageQueueIsolator', [new Reference($applicableIsolatorId)]);
            }
        }
    }

    private function processSuiteAwareSubscriber(ContainerBuilder $container)
    {
        $services = [];

        foreach ($container->findTaggedServiceIds(self::SUITE_AWARE_TAG) as $id => $attributes) {
            $services[] = new Reference($id);
        }

        $container->getDefinition('oro_test.listener.suite_aware_subscriber')->replaceArgument(
            0,
            $services
        );
    }

    /**
     * Processes all context initializers.
     *
     * @param ContainerBuilder $container
     */
    private function processClassResolvers(ContainerBuilder $container)
    {
        $references = $this->processor->findAndSortTaggedServices($container, ContextExtension::CLASS_RESOLVER_TAG);
        $definition = $container->getDefinition('oro_test.environment.handler.feature_environment_handler');

        foreach ($references as $reference) {
            $definition->addMethodCall('registerClassResolver', array($reference));
        }
    }

    /**
     * @param ContainerBuilder $container
     */
    private function processBundleBehatConfigurations(ContainerBuilder $container)
    {
        /** @var KernelInterface $kernel */
        $kernel = $container->get(Symfony2Extension::KERNEL_ID);
        $processor = new Processor();
        $configuration = new BehatBundleConfiguration($container);
        $suites = $container->getParameter('suite.configurations');
        $pages = [];
        $elements = [];

        /** @var BundleInterface $bundle */
        foreach ($kernel->getBundles() as $bundle) {
            $configFile = str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $bundle->getPath().self::CONFIG_PATH
            );

            if (!is_file($configFile)) {
                continue;
            }

            $config = Yaml::parse(file_get_contents($configFile));
            $processedConfiguration = $processor->processConfiguration(
                $configuration,
                $config
            );

            $this->appendConfiguration($pages, $processedConfiguration[self::PAGES_CONFIG_ROOT]);
            $this->appendConfiguration($elements, $processedConfiguration[self::ELEMENTS_CONFIG_ROOT]);
            $suites = array_merge($suites, $processedConfiguration[self::SUITES_CONFIG_ROOT]);
        }

        $container->getDefinition('oro_element_factory')->replaceArgument(2, $elements);
        $container->getDefinition('oro_page_factory')->replaceArgument(1, $pages);
        $container->setParameter('suite.configurations', $suites);
    }

    private function appendConfiguration(array &$baseConfig, array $config)
    {
        foreach ($config as $key => $value) {
            if (array_key_exists($key, $baseConfig)) {
                throw new \InvalidArgumentException(sprintf('Configuration with "%s" key is already defined', $key));
            }

            $baseConfig[$key] = $value;
        }
    }

    /**
     * Generate behat test suite for every bundle that registered in kernel and not configured in configuration
     *
     * @param ContainerBuilder $container
     */
    private function processBundleAutoload(ContainerBuilder $container)
    {
        $suiteConfigurations = $container->getParameter('suite.configurations');
        $kernel = $container->get(Symfony2Extension::KERNEL_ID);
        /** @var SymfonySuiteGenerator $suiteGenerator */
        $suiteGenerator = $container->get('symfony2_extension.suite.generator');
        $commonContexts = $container->getParameter('oro_test.shared_contexts');

        /** @var BundleInterface $bundle */
        foreach ($kernel->getBundles() as $bundle) {
            if (array_key_exists($bundle->getName(), $suiteConfigurations)) {
                continue;
            }

            // Add ! to the start of bundle name, because we need to get the real bundle not the inheritance
            // See OroKernel->getBundle
            $bundleSuite = $suiteGenerator->generateSuite('!'.$bundle->getName(), []);

            if (!$this->hasValidPaths($bundleSuite)) {
                continue;
            }

            $suiteConfigurations[$bundle->getName()] = [
                'type' => 'symfony_bundle',
                'settings' => [
                    'contexts' => $this->getSuiteContexts($bundleSuite, $commonContexts),
                    'paths' => $bundleSuite->getSetting('paths'),
                ],
            ];
        }

        $container->setParameter('suite.configurations', $suiteConfigurations);
    }

    /**
     * @param SymfonyBundleSuite $bundleSuite
     * @param Context[] $commonContexts
     * @return array
     */
    private function getSuiteContexts(SymfonyBundleSuite $bundleSuite, array $commonContexts)
    {
        $suiteContexts = array_filter($bundleSuite->getSetting('contexts'), 'class_exists');
        $suiteContexts = array_merge($suiteContexts, $commonContexts);

        return $suiteContexts;
    }

    /**
     * @param SymfonyBundleSuite $bundleSuite
     * @return bool
     */
    protected function hasValidPaths(SymfonyBundleSuite $bundleSuite)
    {
        return 0 < count(array_filter($bundleSuite->getSetting('paths'), 'is_dir'));
    }

    /**
     * @param BundleInterface $bundle
     * @return bool
     */
    protected function hasDirectory(BundleInterface $bundle, $namespace)
    {
        $path = $bundle->getPath() . str_replace('\\', DIRECTORY_SEPARATOR, $namespace);

        return is_dir($path);
    }
}
