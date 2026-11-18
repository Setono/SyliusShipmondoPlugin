<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers the webhooks, sent to the shop's webhook endpoint at a hostname Shipmondo can reach: the one passed with
 * --hostname, else the enabled channels' hostname (it asks which, when they have several), both on https, else the
 * router's default URI. On the command line there is no request to take the hostname from, and without
 * framework.router.default_uri the router generates http://localhost, which Shipmondo can't reach. When it finds no
 * such hostname, or the channels have several and it can't ask, it fails without registering, so the webhooks
 * registered before are kept
 */
#[AsCommand(
    name: 'setono:sylius-shipmondo:register-webhooks',
    description: 'Register relevant webhooks with Shipmondo',
)]
final class RegisterWebhooksCommand extends Command
{
    /**
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hostname', null, InputOption::VALUE_REQUIRED, 'The hostname Shipmondo sends the webhooks to, e.g. shop.example.com')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command registers the webhooks the plugin handles with Shipmondo. Shipmondo sends them to
                the shop's webhook endpoint at:

                  * the hostname passed with <comment>--hostname</comment>, on https;
                  * else the enabled channels' hostname, on https. When they have several, the command asks which one, or,
                    when it can't ask, fails and lists them;
                  * else the router's default URI, <comment>framework.router.default_uri</comment>.

                It skips a hostname Shipmondo can't reach, such as localhost, and fails without registering when it finds
                none.
                HELP)
        ;
    }

    /**
     * Asks which of the channels' hostnames Shipmondo sends the webhooks to, when there are several
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $hostnames = $this->channelHostnames();
        if (null !== $input->getOption('hostname') || count($hostnames) < 2) {
            return;
        }

        $input->setOption('hostname', (new SymfonyStyle($input, $output))->choice('Which hostname does Shipmondo send the webhooks to?', $hostnames));
    }

    /**
     * Fails, and says why, when it finds no hostname to register the webhooks under, and when Shipmondo fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routerContext = $this->urlGenerator->getContext();
        $webhookContext = $this->webhookContext($input, $routerContext);
        if (is_string($webhookContext)) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error($webhookContext);

            return Command::FAILURE;
        }

        $this->urlGenerator->setContext($webhookContext);

        try {
            $this->commandBus->dispatch(new RegisterWebhooks());
        } catch (HandlerFailedException $e) {
            $failure = ShipmondoFailure::of(array_values($e->getWrappedExceptions(ShipmondoException::class))[0] ?? throw $e);

            $this->logger->error('The webhooks could not be registered in Shipmondo', $failure->logContext());
            (new SymfonyStyle($input, $output))->getErrorStyle()->error($this->translator->trans(
                'setono_sylius_shipmondo.webhooks_not_registered',
                ['%reason%' => $failure->trans($this->translator)],
                'flashes',
            ));

            return Command::FAILURE;
        } finally {
            $this->urlGenerator->setContext($routerContext);
        }

        return Command::SUCCESS;
    }

    /**
     * The router context the webhooks' endpoint is generated with, or why there is none
     */
    private function webhookContext(InputInterface $input, RequestContext $routerContext): RequestContext|string
    {
        $hostname = $input->getOption('hostname');
        if (!is_string($hostname)) {
            $hostnames = $this->channelHostnames();
            if (count($hostnames) > 1) {
                return sprintf(
                    'The channels have several hostnames: %s. Choose the one Shipmondo sends the webhooks to with --hostname.',
                    implode(', ', $hostnames),
                );
            }

            $hostname = $hostnames[0] ?? null;
        }

        if (null === $hostname) {
            return self::isReachable($routerContext->getHost()) ? $routerContext : sprintf(
                'Found no hostname Shipmondo can reach to send the webhooks to: no channel has one, and the router\'s default URI (framework.router.default_uri) has %s. Give a channel the shop\'s hostname, or pass it with --hostname.',
                $routerContext->getHost(),
            );
        }

        if (!self::isReachable($hostname)) {
            return sprintf('Shipmondo can\'t reach the hostname "%s". Pass the shop\'s hostname, e.g. --hostname=shop.example.com.', $hostname);
        }

        return (clone $routerContext)->setHost($hostname)->setScheme('https')->setHttpsPort(443);
    }

    /**
     * The enabled channels' hostnames that Shipmondo can reach, each once
     *
     * @return list<string>
     */
    private function channelHostnames(): array
    {
        $hostnames = [];
        foreach ($this->channelRepository->findEnabled() as $channel) {
            $hostname = (string) $channel->getHostname();
            if (self::isReachable($hostname)) {
                $hostnames[] = $hostname;
            }
        }

        return array_values(array_unique($hostnames));
    }

    /**
     * Whether Shipmondo can reach the host: it is a hostname, and it doesn't name the shop's own machine, as localhost,
     * a name under localhost and a loopback address do
     */
    private static function isReachable(string $host): bool
    {
        $host = strtolower($host);

        return false !== filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) &&
            'localhost' !== $host && !str_ends_with($host, '.localhost') && !str_starts_with($host, '127.');
    }
}
