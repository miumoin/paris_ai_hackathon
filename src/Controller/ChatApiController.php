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

class ChatApiController extends AbstractController
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

    #[Route('/api/chat/{slug}/contact', name: 'create_contact')]
    public function create_workspace_contact(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);
        [ $workspace, $profile ] = $this->utilities->createChatContact( $slug, $content['title'], $databaseManager );

        return new JsonResponse([
            'status'    => ( $profile ? 'success' : 'fail' ),
            'profile'   => $profile,
            'workspace' => $workspace
        ]);
    }

    #[Route('/api/chat/{slug}/prepare', name: 'prepare_chat_knowledge')]
    public function prepare_chat_knowledge(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);
        $isPrepared = $this->utilities->prepareChatKnowledge( $slug, $databaseManager );

        return new JsonResponse([
            'status'    => ( $isPrepared ? 'success' : 'fail' )
        ]);
    }

    #[Route('/api/chat/{slug}/inference/{id}', name: 'inference_chat_message')]
    public function inference_chat_message(string $slug, string $id, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        
        $workspacePrompt = $this->utilities->getWorkspacePrompt( $slug, $databaseManager );
        $generatedResponse = $this->utilities->generateResponse( $slug, $id, $databaseManager, $workspacePrompt );

        return new JsonResponse([
            'status'    => ( $generatedResponse ? 'success' : 'fail' ),
            'response'  => $generatedResponse
        ]);
    }

    #[Route('/api/chat/{slug}/messages', name: 'get_chat_messages')]
    public function get_chat_messages(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);
        $after = $content['after'];
        [ $profile, $workspace, $messages ] = $this->utilities->getChatMessages( $slug, $after, $databaseManager );

        return new JsonResponse([
            'status'    => ( $profile ? 'success' : 'fail' ),
            'profile'   => $profile,
            'workspace' => $workspace,
            'messages'   => $messages
        ]);
    }

    #[Route('/api/chat/{slug}/send', name: 'send_chat_message')]
    public function send_chat_message(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $message = $this->utilities->sendChatMessage( $databaseManager, $slug, $request );

        return new JsonResponse([
            'status'    => ( $message ? 'success' : 'fail' ),
            'message'   => $message
        ]);
    }

    #[Route('/api/chat/{slug}/files/send', name: 'send_file_message')]
    public function send_file_message(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $save = $this->utilities->sendFileMessage( $databaseManager, $slug, $request );

        return new JsonResponse([
            'status'    => ( $save ? 'success' : 'fail' ),
            'message'   => $save
        ]);
    }

    #[Route('/api/chat/{slug}/file/{profile_id}/{id}', name: 'get_single_file')]
    public function get_uploaded_file(string $slug, int $profile_id, int $id, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspaceUnprivileged( $slug, $databaseManager );
            if( $workspace != null ) {
                $knowledge = $this->utilities->getProfileKnowledge( $databaseManager, $workspace, $profile_id, $id );
            } else {
                $knowledge = null;
            }
        }

        return new JsonResponse([
            'status'    => ( $knowledge ? 'success' : 'fail' ),
            'knowledge' => $knowledge
        ]);
    }

    #[Route('/api/chat/{slug}/voice', name: 'convert_voice_message')]
    public function convert_voice_message(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        
        $data = json_decode($request->getContent(), true);
        $audioBase64 = $data['audio'] ?? '';
        $text = $this->utilities->convertVoiceMessage( $audioBase64 ); 

        return new JsonResponse([
            'status'    => ( $text ? 'success' : 'fail' ),
            'text'      => $text
        ]);
    }
}