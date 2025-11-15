<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DatabaseManager;
use App\Service\Utilities;
use DateTime;

class LoginApiController extends AbstractController
{
    private $entityManager;
    private $databaseManager;
    private $params;
    private $httpClient;
    private $utilities;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $params, HttpClientInterface $httpClient)
    {
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->httpClient = $httpClient;
        $this->utilities = new Utilities( $params, $entityManager, $httpClient );
    }

    #[Route('/api/login', name: 'login')]
    public function login(Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        [ $user_id, $user_email, $access_key ] = $this->utilities->makeLogin( $databaseManager, $request );

        // Success response
        return new JsonResponse([
            'status' => ( $user_id > 0 ? 'success' : 'fail' ),
            'access_key' => $access_key ?? ''
        ]);
    }

    #[Route('/api/verify', name: 'verify')]
    public function verify(Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);

        $q = $this->entityManager->createQueryBuilder();
        $result = $q->select('m.id, m.parent, m.parent_id, m.meta_value')
            ->from('App\Entity\Metas', 'm')
            ->where('m.meta_value LIKE :code')
            ->setParameter('code', '%"code":' . $content['code'] . '%')
            ->getQuery()
            ->getOneOrNullResult();

        $access_key = '';
        $user_id = 0;
        if( isset( $result['parent_id'] ) ) {
            $user_id = $result['parent_id'];
            [ $user_email, $access_key ]  = $databaseManager->getAccessKey( $user_id );
        }

        // Success response
        return new JsonResponse([
            'status' => ( $user_id > 0 ? 'success' : 'fail' ),
            'access_key' => $access_key ?? ''
        ]);
    }
}