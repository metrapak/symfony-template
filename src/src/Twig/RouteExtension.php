<?php

// src/Twig/RouteExtension.php

namespace App\Twig;

use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class RouteExtension extends AbstractExtension
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_all_routes', $this->getAllRoutes(...)),
            new TwigFunction('count_routes', $this->countRoutes(...)),
        ];
    }

    public function getAllRoutes(): array
    {
        $routes = array_map(fn ($route) => [
            'path' => $route->getPath(),
            'controller' => $route->getDefault('_controller'),
        ], $this->router->getRouteCollection()->all());

        ksort($routes);

        return $routes;
    }

    public function countRoutes(): int
    {
        return count($this->router->getRouteCollection()->all());
    }
}
