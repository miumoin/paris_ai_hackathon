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

class ThreadsApiController extends AbstractController
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

    #[Route('/api/workspace/{slug}/threads/{page}', name: 'get_threads')]
    public function get_threads(string $slug, int $page, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( isset( $slug ) && $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $threads = $this->utilities->getWorkspaceProfiles( $workspace, $databaseManager, $page );
            }
        }

        return new JsonResponse([
            'status'    => ( isset( $workspace['id'] ) ? 'success' : 'fail' ),
            'threads' => $threads
        ]);
    }

    #[Route('/api/workspace/{slug}/thread/add', name: 'add_new_thread')]
    public function add_new_thread(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $save = $this->utilities->addNewProfile( $databaseManager, $workspace, $request );
            }
        }

        return new JsonResponse([
            'status'    => ( $save ? 'success' : 'fail' )
        ]);
    }

    #[Route('/api/workspace/{slug}/thread/delete', name: 'delete_thread')]
    public function delete_workspace_thread(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $delete = $this->utilities->deleteProfile( $databaseManager, $workspace, $request );
            }
        }

        return new JsonResponse([
            'status'    => ( $delete ? 'success' : 'fail' )
        ]);
    }
}