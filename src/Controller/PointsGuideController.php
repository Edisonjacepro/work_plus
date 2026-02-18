<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PointsGuideController extends AbstractController
{
    #[Route('/points', name: 'points_guide_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('points_guide/index.html.twig');
    }
}
