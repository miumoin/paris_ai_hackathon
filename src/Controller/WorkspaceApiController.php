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
use Psr\Log\LoggerInterface;
use DateTime;

class WorkspaceApiController extends AbstractController
{
    private $entityManager;
    private $databaseManager;
    private $params;
    private $httpClient;
    private $utilities;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $params, HttpClientInterface $httpClient, private LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->httpClient = $httpClient;
        $this->utilities = new Utilities( $params, $entityManager, $httpClient );
        $this->logger = $logger;
    }

    #[Route('/api/workspaces', name: 'workspaces')]
    public function workspaces( Request $request ): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $page = 0;
        $workspaces = $this->utilities->getWorkspaces( $databaseManager, $page );
        $subscription = $this->utilities->getSubscriptionInfo( $databaseManager );

        // Success response
        return new JsonResponse([
            'status' => 'success',
            'workspaces' => $workspaces ?? [],
            'subscription' => $subscription ?? [],
        ]);
    }

    #[Route('/api/workspaces/add', name: 'add_new_workspaces')]
    public function add_new_workspace(Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);

        $user_id = $databaseManager->getCurrentUser();
        $block_data = array(
            'type'      => 'workspace',
            'title'     => $content['title'],
            'content'   => '',
            'parent'    => 0
        );

        $block = $databaseManager->addBlock( $user_id, $block_data, '' );

        // Convert the workspace into data collection if questionaire is set
        if( isset( $content['metas'] ) && isset( $content['metas']['questionnaire'] ) && $content['metas']['questionnaire'][0] != '' ) {
            $databaseManager->addMeta( 'workspace', $block['id'], 'collect_information', 'true' );
            $databaseManager->addMeta( 'workspace', $block['id'], 'questionnaire', $content['metas']['questionnaire'] );
        }

        // Setting up ownership and privileges
        $databaseManager->addMeta( 'workspace', $block['id'], 'privilege_' . $user_id, ['admin'] );

        // Success response
        return new JsonResponse([
            'status' => 'success',
            'block' => $block ?? []
        ]);
    }

    //route for homepage workspace list
    #[Route('/api/workspaces/{page}', name: 'workspaces_home')]
    public function workspaces_home( Request $request, int $page ): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $workspaces = $this->utilities->getWorkspaces( $databaseManager, $page );
        $subscription = $this->utilities->getSubscriptionInfo( $databaseManager );

        // Success response
        return new JsonResponse([
            'status' => 'success',
            'workspaces' => $workspaces ?? [],
            'subscription' => $subscription ?? [],
        ]);
    }

    #[Route('/api/workspace/delete', name: 'delete_workspace')]
    public function delete_workspace(Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);
        $id = $content['id'];
        $user_id = $databaseManager->getCurrentUser();
        $privileges = $databaseManager->getMeta( 'workspace', $id, 'privilege_' . $user_id );
        if( $privileges != null ) $privileges = json_decode( $privileges, true );
        if( $privileges != null && in_array( 'admin', $privileges ) ) {
            $delete = $databaseManager->deleteBlock($id);
        }

        return new JsonResponse([
            'status'    => 'success'
        ]);
    }

    #[Route('/api/workspace/{slug}', name: 'get_workspace')]
    public function getWorkspace(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $content = json_decode($request->getContent(), true);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
        }

        return new JsonResponse([
            'status'    => ( isset( $workspace['id'] ) ? 'success' : 'fail' ),
            'workspace' => $workspace
        ]);
    }

    #[Route('/api/workspace/{slug}/update', name: 'update_workspace')]
    public function updateWorkspace(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);

        if( isset( $slug ) && $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $workspace = $this->utilities->updateWorkspace( $workspace, $request, $databaseManager );
            }
        }

        return new JsonResponse([
            'status'    => ( isset( $workspace['id'] ) ? 'success' : 'fail' ),
            'workspace' => $workspace
        ]);
    }

    #[Route('/api/workspace/{slug}/logo', name: 'update_workspace_logo')]
    public function updateWorkspaceLogo(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null && in_array( 'admin', $privileges ) ) {
                $logo = $this->utilities->saveWorkspaceLogo( $databaseManager, $workspace, $request );
            }
        }

        return new JsonResponse([
            'status'    => ( $logo ? 'success' : 'fail' ),
            'logo'      => $logo
        ]);
    }

    #[Route('/api/workspace/{slug}/download', name: 'download_workspace_entries')]
    public function downloadWorkspaceEntries(string $slug, Request $request): JsonResponse
    {
        $domain = $request->headers->get('X-Vuedoo-Domain') ?? '';
        $access_key = $request->headers->get('X-Vuedoo-Access-Key') ?? '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        if( $slug != '' ) {
            $workspace = $this->utilities->getWorkspace( $slug, $databaseManager );
            $privileges = json_decode( $workspace['meta_value'], true );
            //check if privilege allows adding new knowledge
            if( $privileges != null ) {
                $data = $this->utilities->getWorkspaceEntries( $workspace, $databaseManager );
            }
        }

        return new JsonResponse([
            'status'    => ( $data ? 'success' : 'fail' ),
            'data'      => $data
        ]);
    }

    #[Route('/api/webhooks/stripe', name: 'stripe_webhook')]
    public function stripeWebhook(Request $request): JsonResponse
    {
        $domain = '';
        $access_key = '';
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $payload = json_decode($request->getContent(), true);
        $data = [];

        $this->logger->info('Received event payload', [
            'payload' => $payload // array or decoded JSON
        ]);

        if( $payload['type'] === 'checkout.session.completed' ) {
            $subscription = array(
                'expiry_date' => (new DateTime())->modify('+1 month')->format('Y-m-d H:i:s'),
                'customer_id' => $payload['data']['object']['customer'],
                'subscription_id' => $payload['data']['object']['subscription']
            );
            $databaseManager->addMeta( 'user', $payload['data']['object']['client_reference_id'], 'subscription', $subscription );
        } elseif ($payload['type'] === 'invoice.paid') {
            $user_id = $this->utilities->getSubscriberUserId( $databaseManager, $payload['data']['object']['customer'], $payload['data']['object']['subscription'] );
            if( $user_id != null ) {
                $subscription =  $databaseManager->getMeta( 'user', $user_id, 'subscription' );
                if( $subscription != null ) {
                    $subscription = json_decode( $subscription, true );
                    $subscription['expiry_date'] = (new DateTime())->modify('+1 month')->format('Y-m-d H:i:s');
                }
                $databaseManager->addMeta( 'user', $user_id, 'subscription', $subscription );
            }
        }

        return new JsonResponse([
            'status'    => ( $data ? 'success' : 'fail' ),
            'data'      => $data
        ]);
    }
}