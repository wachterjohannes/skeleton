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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

#[AsCommand(
    name: 'phpcr:cleanup',
    description: 'Clean up the PHPCR repository',
)]
class PHPCRCleanupCommand extends Command
{
    /**
     * @var string[]
     */
    public const WHITELIST = [
        'state',
        'created',
        'creator',
        'changed',
        'changer',
    ];

    /**
     * @var array<string, OptionsResolver> Cached options resolver instances
     */
    private array $optionsResolvers = [];

    /**
     * @var callable
     */
    private $logger;

    public function __construct(
        private SessionInterface $session,
        private NamespaceRegistry $namespaceRegistry,
        private EventDispatcherInterface $documentManagerEventDispatcher,
        private DocumentManagerInterface $documentManager,
        private string $projectDirectory,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $defaultDebugFile = \sprintf('%s/var/%s_phpcr-cleanup.md', $this->projectDirectory, \date('Y-m-d-H-i-s'));

        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask for confirmation.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not make any changes to the repository.');
        $this->addOption('debug', null, InputOption::VALUE_NONE, 'Write debug information to a file.');
        $this->addOption('debug-file', null, InputOption::VALUE_REQUIRED, 'Write debug information to a file.', $defaultDebugFile);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('PHPCR Cleanup');

        if (!$input->getOption('dry-run')) {
            $io->warning('This command will remove properties from the PHPCR repository. Make sure to have a backup before running this command.');
            if (!$input->getOption('force')) {
                $answer = $io->ask('Do you want to continue [y/n]', null, function ($value) {
                    $value = \strtolower($value);
                    if (!\in_array($value, ['y', 'n'], true)) {
                        throw new \RuntimeException('You need to enter "y" to continue or "n" to abort.');
                    }

                    return 'y' === $value;
                });

                if (!$answer) {
                    $io->warning('You have aborted the command');

                    return self::SUCCESS;
                }
            } else {
                $io->writeln('The command will wait for 5 seconds before starting');
                $progressBar = $io->createProgressBar(5);
                $progressBar->start();
                for ($i = 0; $i < 5; ++$i) {
                    $progressBar->advance();
                    \sleep(1);
                }
                $progressBar->finish();

                $io->newLine();
                $io->newLine();
                $io->newLine();
            }
        }

        $io->section('Initiating cleanup process ...');
        $io->writeln('Project directory: ' . $this->projectDirectory);
        $io->writeln('Dry-run: ' . ($input->getOption('dry-run') ? 'enabled' : 'disabled'));

        $debug = $input->getOption('debug');
        $io->writeln('Debug: ' . ($debug ? 'enabled' : 'disabled'));
        $this->logger = function (string $message) {
            // do not print anything
        };

        if ($input->getOption('debug')) {
            $debugFile = $input->getOption('debug-file');
            $io->writeln('Debug file: ' . $debugFile);

            $this->logger = function (string $message) use ($debugFile) {
                \file_put_contents($debugFile, $message . \PHP_EOL, \FILE_APPEND);
            };
        }

        $io->newLine();
        $io->newLine();

        $queryManager = $this->session->getWorkspace()->getQueryManager();
        $rows = $queryManager->createQuery('SELECT * FROM [nt:unstructured]', 'JCR-SQL2')->execute();

        $stats = [
            'nodes' => 0,
            'properties' => 0,
            'removedProperties' => 0,
        ];

        $io->section('Running cleanup process ...');
        $progressBar = $io->createProgressBar();
        $progressBar->setFormat("Nodes: %nodes%\nProperties: %properties%\nRemoved properties: %removedProperties%\n\n");

        $progressBar->setMessage((string) $stats['nodes'], 'nodes');
        $progressBar->setMessage((string) $stats['properties'], 'properties');
        $progressBar->setMessage((string) $stats['removedProperties'], 'removedProperties');

        $progressBar->start();

        foreach ($rows->getNodes() as $node) {
            ++$stats['nodes'];

            foreach ($this->getLocales($node) as $locale) {
                $document = $this->documentManager->find($node->getIdentifier(), $locale);

                $options = $this->getOptionsResolver(Events::PERSIST)->resolve();

                $cleanupNode = new CleanupNode(clone $node);

                $event = new Event\PersistEvent($document, $locale, $options);
                $event->setNode($cleanupNode);
                $this->documentManagerEventDispatcher->dispatch($event, Events::PERSIST);
                $this->documentManager->clear();

                $writtenProperties = $cleanupNode->getWrittenPropertyKeys();
                foreach ($this->cleanupNode($node, $locale, $writtenProperties, $input->getOption('dry-run')) as $result) {
                    ++$stats['properties'];
                    $stats['removedProperties'] += $result ? 1 : 0;
                }

                $this->session->save();
                $this->documentManager->clear();
            }

            $progressBar->setMessage((string) $stats['nodes'], 'nodes');
            $progressBar->setMessage((string) $stats['properties'], 'properties');
            $progressBar->setMessage((string) $stats['removedProperties'], 'removedProperties');
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->success('Cleanup process finished');

        return self::SUCCESS;
    }

    private function cleanupNode(NodeInterface $node, string $locale, array $writtenProperties, bool $dryRun): \Generator
    {
        \call_user_func($this->logger, \sprintf('# Cleaning up node "%s" for locale "%s"' . \PHP_EOL, $node->getPath(), $locale));

        $whiteList = \array_map(fn ($property) => $this->namespaceRegistry->getPrefix('system_localized') . ':' . $locale . '-' . $property, self::WHITELIST);
        \call_user_func($this->logger, \sprintf("Whitelisted:\n* %s\n", \implode("\n* ", $whiteList)));
        \call_user_func($this->logger, \sprintf("Written:\n* %s\n", \implode("\n* ", $writtenProperties)));

        $removedProperties = [];
        foreach ($node->getProperties() as $property) {
            if (!\str_starts_with($property->getName(), 'i18n:' . $locale)) {
                yield false;

                continue;
            }

            if (\in_array($property->getName(), $writtenProperties, true)
                || \in_array($property->getName(), $whiteList, true)
            ) {
                yield false;

                continue;
            }

            $removedProperties[] = $property->getName();
            if (!$dryRun) {
                $property->remove();
            }

            yield true;
        }

        \call_user_func($this->logger, \sprintf("Removed:\n* %s\n", \implode("\n* ", $removedProperties)));
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
