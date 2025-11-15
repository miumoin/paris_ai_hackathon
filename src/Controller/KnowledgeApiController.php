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

class KnowledgeApiController extends AbstractController
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

    #[Route('/api/workspace/{slug}/knowledge', name: 'get_knowledge')]
    public function get_workspace_knowledges(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            if( $privileges != null ) {
                $knowledges = $this->utilities->getWorkspaceKnowledge( $workspace, $databaseManager );
            }
        }

        return new JsonResponse([
            'status'    => ( isset( $workspace['id'] ) ? 'success' : 'fail' ),
            'knowledges' => $knowledges
        ]);
    }

    #[Route('/api/workspace/{slug}/knowledge/save', name: 'save_knowledge')]
    public function save_workspace_knowledge(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $save = $this->utilities->saveKnowledge( $databaseManager, $workspace, $request );
            }
        }

        return new JsonResponse([
            'status'    => ( $save ? 'success' : 'fail' )
        ]);
    }

    #[Route('/api/workspace/{slug}/knowledge/delete', name: 'delete_knowledge')]
    public function delete_workspace_knowledge(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $delete = $this->utilities->deleteKnowledge( $databaseManager, $workspace, $request );
            }
        }

        return new JsonResponse([
            'status'    => ( $delete ? 'success' : 'fail' )
        ]);
    }

    #[Route('/api/workspace/{slug}/knowledge/get/{id}', name: 'get_single_knowledge')]
    public function get_workspace_knowledge(string $slug, int $id, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null ) {
                $knowledge = $this->utilities->getKnowledge( $databaseManager, $workspace, $id );
            }
        }

        return new JsonResponse([
            'status'    => ( $knowledge ? 'success' : 'fail' ),
            'knowledge' => $knowledge
        ]);
    }
}