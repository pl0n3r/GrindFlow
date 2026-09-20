<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'grindflow_home', methods: ['GET'])]
    public function __invoke(ProductVersion $version): Response
    {
        return $this->render('home/index.html.twig', ['app_version' => $version->human()]);
    }
}
