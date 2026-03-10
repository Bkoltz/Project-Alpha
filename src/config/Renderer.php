<?php

namespace App\Config;

use Twig\Environment;

class Renderer
{
    private Environment $twig;
    private Router $router;

    public function __construct(Environment $twig, Router $router)
    {
        $this->twig = $twig;
        $this->router = $router;
    }

    public function getRenderHTML(string $path, array $data = []) : string {
        return $this->twig->render($path, $data);
    }

    public function render(string $path, array $data = [])
    {
        echo $this->twig->render($path, $data);
    }
}
