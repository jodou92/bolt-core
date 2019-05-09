<?php

declare(strict_types=1);

namespace Bolt;

use Bolt\Widget\CacheAware;
use Bolt\Widget\Injector\QueueProcessor;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widget\RequestAware;
use Bolt\Widget\TwigAware;
use Bolt\Widget\WidgetInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tightenco\Collect\Support\Collection;
use Twig\Environment;

/**
 * @todo Determine if we should split this up into smaller classes
 */
class Widgets
{
    /** @var Collection */
    private $queue;

    /** @var RequestStack */
    private $requestStack;

    /** @var QueueProcessor */
    private $queueProcessor;

    /** @var Environment */
    private $twig;

    /** @var CacheInterface */
    private $cache;

    public function __construct(RequestStack $requestStack, QueueProcessor $queueProcessor, Environment $twig, CacheInterface $cache)
    {
        $this->queue = new Collection([]);
        $this->requestStack = $requestStack;
        $this->queueProcessor = $queueProcessor;
        $this->twig = $twig;
        $this->cache = $cache;
    }

    public function registerWidget(WidgetInterface $widget): void
    {
        if ($widget instanceof TwigAware) {
            $widget->setTwig($this->twig);
        }

        $this->queue->push($widget);
    }

    public function renderWidgetByName(string $name, array $params = []): string
    {
        $widget = $this->queue->filter(function (WidgetInterface $widget) use ($name) {
            return $widget->getName() === $name;
        })->first();

        if ($widget) {
            return $this->invokeWidget($widget, $params);
        }
    }

    public function renderWidgetsForTarget(string $target, array $params = []): string
    {
        $widgets = $this->queue->filter(function (WidgetInterface $widget) use ($target) {
            return $widget->getTarget() === $target;
        })->sortBy(function (WidgetInterface $widget) {
            return $widget->getPriority();
        });

        $output = '';

        foreach ($widgets as $widget) {
            $output .= $this->invokeWidget($widget, $params);
        }

        return $output;
    }

    private function invokeWidget(WidgetInterface $widget, array $params = []): string
    {
        if ($widget instanceof RequestAware) {
            $widget->setRequest($this->requestStack->getCurrentRequest());
        }

        if ($widget instanceof CacheAware) {
            $widget->setCacheInterface($this->cache);

            return $widget->cachedInvoke();
        }

        // Call the magic `__invoke` method on the $widget object
        return $widget($params);
    }

    public function processQueue(Response $response): Response
    {
        // Don't try to modify the response body for streamed responses. Stuff will break, if we do.
        if ($response instanceof StreamedResponse) {
            return $response;
        }

        $request = $this->requestStack->getCurrentRequest();
        $zone = RequestZone::getFromRequest($request);
        if ($zone === RequestZone::NOWHERE) {
            return $response;
        }

        $queue = $this->queue;

        return $this->queueProcessor->guardResponse(
            $response,
            function (Response $response) use ($request, $queue, $zone): void {
                $this->queueProcessor->process($response, $request, $queue, $zone);
            }
        );
    }
}
