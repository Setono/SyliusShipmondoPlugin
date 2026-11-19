<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Registrar\UnreachableWebhookEndpointException;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookHost;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers the webhooks at a hostname Shipmondo can reach, chosen as the help text says. It can't take the hostname
 * from the router: on the command line there is no request, and without framework.router.default_uri the router
 * generates http://localhost, which Shipmondo can't reach
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
        private readonly WebhookRegistrarInterface $webhookRegistrar,
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
                the shop's webhook endpoint, /webhook/shipmondo, at:

                  * https://<hostname>, for the hostname passed with <comment>--hostname</comment>;
                  * else https://<hostname>, for the enabled channels' hostname. When they have several, the command asks
                    which one, or, when it can't ask, fails and lists them;
                  * else the router's default URI, <comment>framework.router.default_uri</comment>, path included.

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
     * Fails, and says why, when it finds no hostname to register the webhooks under, when the registrar refuses the
     * endpoint, and when Shipmondo fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routerContext = $this->urlGenerator->getContext();

        try {
            $webhookContext = $this->webhookContext($input, $routerContext);
        } catch (NoWebhookHostnameException $e) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->urlGenerator->setContext($webhookContext);

        try {
            $this->webhookRegistrar->register();
        } catch (UnreachableWebhookEndpointException $e) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        } catch (ShipmondoException $e) {
            $failure = ShipmondoFailure::of($e);

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
     * The router context the webhooks' endpoint is generated with: https://<hostname>, at its root, for a hostname
     * passed or found in the channels, else the router's own context, from framework.router.default_uri
     *
     * @throws NoWebhookHostnameException
     */
    private function webhookContext(InputInterface $input, RequestContext $routerContext): RequestContext
    {
        $hostname = $input->getOption('hostname');
        if (!is_string($hostname)) {
            $hostnames = $this->channelHostnames();
            if (count($hostnames) > 1) {
                throw NoWebhookHostnameException::severalChannelHostnames($hostnames);
            }

            $hostname = $hostnames[0] ?? null;
        }

        if (null === $hostname) {
            return WebhookHost::isReachable($routerContext->getHost()) ? $routerContext : throw NoWebhookHostnameException::noneReachable($routerContext->getHost());
        }

        if (!WebhookHost::isReachable($hostname)) {
            throw NoWebhookHostnameException::unreachable($hostname);
        }

        return new RequestContext(host: $hostname, scheme: 'https');
    }

    /**
     * The enabled channels' hostnames that Shipmondo can reach, each once (hostnames are case-insensitive)
     *
     * @return list<string>
     */
    private function channelHostnames(): array
    {
        $hostnames = [];
        foreach ($this->channelRepository->findEnabled() as $channel) {
            $hostname = strtolower((string) $channel->getHostname());
            if (WebhookHost::isReachable($hostname)) {
                $hostnames[] = $hostname;
            }
        }

        return array_values(array_unique($hostnames));
    }
}
