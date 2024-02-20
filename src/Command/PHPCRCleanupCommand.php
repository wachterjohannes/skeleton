<?php

declare(strict_types=1);

namespace App\Command;

use PHPCR\NodeInterface;
use PHPCR\SessionInterface;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\Event;
use Sulu\Component\DocumentManager\Events;
use Sulu\Component\DocumentManager\NamespaceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

#[AsCommand(
    name: 'phpcr:cleanup',
    description: 'Clean up the PHPCR repository',
)]
class PHPCRCleanupCommand extends Command
{
    /**
     * @var array<string, OptionsResolver> Cached options resolver instances
     */
    private array $optionsResolvers = [];

    public function __construct(
        private SessionInterface $session,
        private NamespaceRegistry $namespaceRegistry,
        private EventDispatcherInterface $documentManagerEventDispatcher,
        private DocumentManagerInterface $documentManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $queryManager = $this->session->getWorkspace()->getQueryManager();
        $rows = $queryManager->createQuery('SELECT * FROM [nt:unstructured]', 'JCR-SQL2')->execute();

        foreach ($rows->getNodes() as $node) {
            foreach ($this->getLocales($node) as $locale) {
                $document = $this->documentManager->find($node->getIdentifier(), $locale);

                $options = $this->getOptionsResolver(Events::PERSIST)->resolve();

                $cleanupNode = new CleanupNode(clone $node);

                $event = new Event\PersistEvent($document, $locale, $options);
                $event->setNode($cleanupNode);
                $this->documentManagerEventDispatcher->dispatch($event, Events::PERSIST);
                $this->documentManager->clear();

                $writtenProperties = $cleanupNode->getWrittenPropertyKeys();
                $this->cleanupNode($node, $locale, $writtenProperties);

                $x = 0;
            }
        }
    }

    private function cleanupNode(NodeInterface $node, string $locale, array $writtenProperties)
    {
        foreach ($node->getProperties() as $property) {
            if (!\str_starts_with($property->getName(), 'i18n:' . $locale)) {
                continue;
            }

            if (!\in_array($property->getName(), $writtenProperties, true)) {
                echo('Removing property ' . $property->getName() . ' from node ' . $node->getPath() . PHP_EOL);
            }
        }
    }

    private function getOptionsResolver(string $eventName): OptionsResolver
    {
        if (isset($this->optionsResolvers[$eventName])) {
            return $this->optionsResolvers[$eventName];
        }

        $resolver = new OptionsResolver();
        $resolver->setDefault('locale', null);

        $event = new Event\ConfigureOptionsEvent($resolver);
        $this->documentManagerEventDispatcher->dispatch($event, Events::CONFIGURE_OPTIONS);

        $this->optionsResolvers[$eventName] = $resolver;

        return $resolver;
    }

    private function getLocales(NodeInterface $node)
    {
        $locales = [];
        $prefix = $this->namespaceRegistry->getPrefix('system_localized');

        foreach ($node->getProperties() as $property) {
            \preg_match(
                \sprintf('/^%s:([a-zA-Z_]*?)-.*/', $prefix),
                $property->getName(),
                $matches,
            );

            if ($matches) {
                $locales[$matches[1]] = $matches[1];
            }
        }

        return \array_values(\array_unique($locales));
    }
}
