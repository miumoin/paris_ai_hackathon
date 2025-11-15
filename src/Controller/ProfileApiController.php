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

class ProfileApiController extends AbstractController
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

    #[Route('/api/workspace/{slug}/profile/{profileSlug}/knowledge', name: 'get_profile_knowledge')]
    public function get_profile_knowledges(string $slug, string $profileSlug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            if( $privileges != null ) {
                [ $profile, $knowledges ] = $this->utilities->getProfileKnowledges( $workspace, $profileSlug, $databaseManager );
            }
        }

        return new JsonResponse([
            'status'    => ( isset( $workspace['id'] ) ? 'success' : 'fail' ),
            'profile'   => $profile,
            'knowledges'=> $knowledges
        ]);
    }

    #[Route('/api/workspace/{slug}/profile/{profileSlug}/knowledge/save', name: 'save_profile_knowledge')]
    public function save_profile_knowledge(string $slug, string $profileSlug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $save = $this->utilities->saveProfileKnowledge( $databaseManager, $workspace, $profileSlug, $request );
            }
        }

        return new JsonResponse([
            'status'    => ( $save ? 'success' : 'fail' )
        ]);
    }

    #[Route('/api/workspace/{slug}/knowledge/get/{profile_id}/{id}', name: 'get_single_profile_knowledge')]
    public function get_profile_knowledge(string $slug, int $profile_id, int $id, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null ) {
                $knowledge = $this->utilities->getProfileKnowledge( $databaseManager, $workspace, $profile_id, $id );
            }
        }

        return new JsonResponse([
            'status'    => ( $knowledge ? 'success' : 'fail' ),
            'knowledge' => $knowledge
        ]);
    }

    #[Route('/api/workspace/{slug}/{profile_id}/knowledge/delete', name: 'delete_profile_knowledge')]
    public function delete_profile_knowledge(string $slug, int $profile_id, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $delete = $this->utilities->deleteProfileKnowledge( $databaseManager, $workspace, $profile_id, $request );
            }
        }

        return new JsonResponse([
            'status'    => ( $delete ? 'success' : 'fail' )
        ]);
    }

    #[Route('/api/workspace/{slug}/profile/{profileSlug}/messages', name: 'get_profile_messages')]
    public function get_profile_message(string $slug, string $profileSlug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);
        $after = $content['after'];
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null ) {
                [ $profile, $messages ] = $this->utilities->getProfileMessages( $workspace, $profileSlug, $after, $databaseManager );
            }
        }

        return new JsonResponse([
            'status'    => ( $messages ? 'success' : 'fail' ),
            'profile'   => $profile,
            'messages'   => $messages
        ]);
    }

    #[Route('/api/workspace/{slug}/profile/{profileSlug}/messages/send', name: 'send_message')]
    public function send_message(string $slug, string $profileSlug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null ) {
                $message = $this->utilities->sendMessage( $databaseManager, $workspace, $profileSlug, $request );
            }
        }

        return new JsonResponse([
            'status'    => ( $message ? 'success' : 'fail' ),
            'message'   => $message
        ]);
    }
}