<?php
namespace App\Controller;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class HomeController extends AbstractController
{
    private $params;
    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    #[Route('/{reactRouting}', name: 'home', requirements: ['reactRouting' => '^(?!api|webhooks).*'], defaults: ['reactRouting' => null])]
    public function index( Request $request ): Response
    {
        $response = $this->render('index.html.twig', [ 'google_client_id' =>  $this->params->get( 'google.client_id'), 'stripe_payment_link' =>  $this->params->get( 'stripe.payment_link') ]);

        return $response;
    }
}