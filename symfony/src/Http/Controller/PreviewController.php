<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Http\AssetManifest;
use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PreviewController extends AbstractController
{
    #[Route('/preview', name: 'grindflow_preview', methods: ['GET'])]
    public function __invoke(ProductVersion $version, AssetManifest $assets): Response
    {
        return $this->render('preview/index.html.twig', [
            'app_version' => $version->human(),
            'build' => $assets->preview(),
        ])->setPrivate();
    }
}
