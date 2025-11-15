<?php

namespace App\Service;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DatabaseManager;
use App\Service\SerpApi;
use App\Service\QdrantManager;
use Doctrine\Common\Collections\ArrayCollection;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\PaymentLink;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Aws\S3\S3Client;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use DateTime;
use \Mailjet\Resources;
use \Smalot\PdfParser\Parser;
use \PhpOffice\PhpWord\IOFactory;

class Utilities
{
    private $entityManager;
    private $params;
    private $httpClient;

    //no functions yet
    public function __construct(ParameterBagInterface $params, EntityManagerInterface $entityManager, HttpClientInterface $httpClient)
    {
        $this->entityManager = $entityManager;
        $this->httpClient = $httpClient;
        $this->params = $params;
    }

    public function makeLogin( DatabaseManager $databaseManager, Request $request): array 
    {
        $content = json_decode($request->getContent(), true);
        $password = rand(100000, 999999);
        $user_id = $databaseManager->addUser( $content['email'], $password );
        [ $user_email, $access_key ] = [ $content['email'], '' ];
        //if login is attempted from google, return access key
        if( isset( $content['aud'] ) && isset( $content['azp'] ) ) {
            [ $user_email, $access_key ] = $databaseManager->getAccessKey( $user_id );
        } else {
            $databaseManager->addMeta( 'user', $user_id, 'validation_key', array( 'timestamp' => (new DateTime())->format(DateTime::ATOM), 'code' => $password ) );
            $subject = 'Your Login Verification Code';
            $message_plain = 'Hi there,

Your login verification code is: ' . $password . '

Please enter this code to verify your email and access your account.

If you didn\'t request this, please ignore this email.

Thanks,
The Typewriting Team';
          $message_html = '<p>Hi there,</p>
    <p>Your login verification code is:</p>
    <h1 style="color: #007bff;">' . $password . '</h1>
    <p>Please enter this code to verify your email and access your account.</p>
    <p>If you didn\'t request this, please ignore this email.</p>
    <br>
    <p>Thanks,<br>The Typewriting Team</p>';
          //send email
          $this->send_email( $user_email, $subject, $message_plain, $message_html );
      }

      return [ $user_id, $user_email, $access_key ];
    }

    public function getWorkspaces(DatabaseManager $databaseManager, int $page ): array 
    {
        $user_id = $databaseManager->getCurrentUser();
        $q = $this->entityManager->createQueryBuilder();
        $query = $q->select('b.id, b.title, b.slug, b.content, b.type, b.created_at, m.meta_value')
            ->from('App\Entity\Blocks', 'b')
            ->from('App\Entity\Metas', 'm')
            ->where('b.type = :block_type')
            ->andWhere('b.type = m.parent')
            ->andWhere('b.id = m.parent_id')
            ->andWhere('b.status = :status')
            ->andWhere('m.meta_key = :meta_key')
            ->setParameter('block_type', 'workspace')
            ->setParameter('status', 1)
            ->setParameter('meta_key', 'privilege_' . $user_id)
            ->setFirstResult( ( $page > 0 ? ($page - 1) * 20 : 0 ) )
            ->setMaxResults(( $page > 0 ? 20 : 999 ))
            ->orderBy('b.id', 'DESC')
            ->getQuery();
        $blocks = $query->getResult();

        if(count($blocks) > 0) {
            // Get only description and logo metadata for each workspace
            foreach ($blocks as $key => $block) {
                $q2 = $this->entityManager->createQueryBuilder();
                $meta_values = $q2->select('m.meta_key, m.meta_value')
                    ->from('App\Entity\Metas', 'm')
                    ->where('m.parent = :parent')
                    ->andWhere('m.parent_id = :parent_id')
                    ->andWhere('m.status = :status')
                    ->andWhere($q2->expr()->in('m.meta_key', ['description', 'logo']))
                    ->setParameter('parent', 'workspace')
                    ->setParameter('parent_id', $block['id'])
                    ->setParameter('status', 1)
                    ->getQuery()
                    ->getResult();

                $metas = [];
                if ($meta_values) {
                    foreach ($meta_values as $meta) {
                        $metas[$meta['meta_key']] = $meta['meta_value'];
                    }
                    $blocks[$key]['metas'] = $metas;
                }
            }
        }

        return $blocks;
    }

    public function getWorkspace( string $slug, DatabaseManager $databaseManager ): array|null 
    {
        $user_id = $databaseManager->getCurrentUser();
        $q = $this->entityManager->createQueryBuilder();
        $workspace = $q->select('b.id, b.title, b.slug, b.content, b.type, m.meta_value, b.created_at')
            ->from('App\Entity\Blocks', 'b')
            ->from('App\Entity\Metas', 'm')
            ->where('b.type = :block_type')
            ->andWhere('b.type = m.parent')
            ->andWhere('b.id = m.parent_id')
            ->andWhere('b.slug = :slug')
            ->andWhere('b.status = :status')
            ->andWhere('m.meta_key = :meta_key')
            ->setParameter('block_type', 'workspace')
            ->setParameter('meta_key', 'privilege_' . $user_id)
            ->setParameter('slug', $slug )
            ->setParameter('status', 1 )
            ->getQuery()
            ->getOneOrNullResult();
        
        if( $workspace != null ) {
            $q = $this->entityManager->createQueryBuilder();
            $meta_values = $q->select('m.meta_key, m.meta_value')
            ->from('App\Entity\Metas', 'm')
            ->where('m.parent = :parent')
            ->andWhere('m.parent_id = :parent_id')
            ->andWhere('m.status = :status')
            ->setParameter('parent_id', $workspace['id'])
            ->setParameter('parent', 'workspace')
            ->setParameter('status', 1 )
            ->getQuery()
            ->getResult();
            $metas = [];
            if( $meta_values != null ) {
                foreach( $meta_values as $meta ) {
                    $metas[$meta['meta_key']] = $meta['meta_value'];
                }
                $workspace['metas'] = $metas;
            }
        }
        return $workspace;
    }

    public function getWorkspaceUnprivileged( string $slug, DatabaseManager $databaseManager ): array|null 
    {
        $q = $this->entityManager->createQueryBuilder();
        $workspace = $q->select('b.id, b.title, b.slug, b.content, b.type, b.created_at')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.type = :block_type')
            ->andWhere('b.slug = :slug')
            ->andWhere('b.status = :status')
            ->setParameter('block_type', 'workspace')
            ->setParameter('slug', $slug )
            ->setParameter('status', 1 )
            ->getQuery()
            ->getOneOrNullResult();
        return $workspace;
    }

    public function updateWorkspace( array $workspace, Request $request, DatabaseManager $databaseManager ): array|null 
    {
        $content = json_decode($request->getContent(), true);
        $user_id = $databaseManager->getCurrentUser();
        $workspace['title'] = $content['title'];
        $workspace = $databaseManager->addBlock( $user_id, $workspace, $workspace['slug'] );
        
        $databaseManager->addMeta( 'workspace', $workspace['id'], 'prompt', ( isset( $content['prompt'] ) ? $content['prompt'] : ''  ) );
        $databaseManager->addMeta( 'workspace', $workspace['id'], 'description', ( isset( $content['description'] ) ? $content['description'] : '' ) );
        $databaseManager->addMeta( 'workspace', $workspace['id'], 'role', ( isset( $content['role'] ) ? $content['role'] : '' ) );
        $databaseManager->addMeta( 'workspace', $workspace['id'], 'tone', ( isset( $content['tone'] ) ? $content['tone'] : '' ) );
        $databaseManager->addMeta( 'workspace', $workspace['id'], 'collect_information', ( isset( $content['collect_information'] ) ? $content['collect_information'] : '' ) );
        $databaseManager->addMeta( 'workspace', $workspace['id'], 'questionnaire', ( isset( $content['questionnaire'] ) ? $content['questionnaire'] : '' ) );
        return $workspace;
    }

    public function saveWorkspaceLogo( DatabaseManager $databaseManager, array $workspace, Request $request ): string|bool 
    {
        $file = $request->files->get('file');
        $originalFilename = '';
        if( $file ) {
            $originalFilename = $file->getClientOriginalName();
        }

        if( $originalFilename == '' ) {
            return false;
        } else {
            $filesource = '';
        
            if( $file ) {
                // Generate a unique filename and move to the desired directory
                $uploadsDirectory = '/tmp/';
                $newFilename = uniqid() . '_' . $originalFilename;
                $file->move($uploadsDirectory, $newFilename);
                
                $thumbfile = $this->createThumbnail( $uploadsDirectory . $newFilename, $uploadsDirectory . 'thumb_' . $newFilename );

                $s3 = new S3Client([
                    'version' => 'latest',
                    'region'  => $_ENV['AWS_REGION'],
                    'credentials' => [
                        'key'    => $_ENV['AWS_ACCESS_KEY'],
                        'secret' => $_ENV['AWS_SECRET_KEY'],
                    ],
                ]);

                try {
                    $result = $s3->putObject([
                        'Bucket' => $_ENV['AWS_S3_BUCKET'],
                        'Key'    => 'logos/' . $workspace['id'] . '/' . $newFilename,
                        'SourceFile' => $uploadsDirectory . 'thumb_' . $newFilename,
                        'ACL' => 'public-read'
                    ]);

                    if( $result ) {
                        $databaseManager->addMeta( 'workspace', $workspace['id'], 'logo', $result['ObjectURL'] );
                        unlink( $uploadsDirectory . $newFilename );
                        unlink( $uploadsDirectory . 'thumb_' . $newFilename );
                        return $result['ObjectURL'];
                    }
                } catch (\Exception $e) {
                    //do nothing
                }
            }
        }
        return false;
    }

    public function createThumbnail(string $sourcePath, string $destPath, int $thumbWidth = 200, int $thumbHeight = 200): void
    {
        // Get image size and type
        [$width, $height, $type] = getimagesize($sourcePath);

        switch ($type) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            default:
                throw new \Exception('Unsupported image type');
        }

        // Create a new blank image with desired thumbnail size
        $thumbImage = imagecreatetruecolor($thumbWidth, $thumbHeight);

        // Optional: Handle transparency for PNG and GIF
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagecolortransparent($thumbImage, imagecolorallocatealpha($thumbImage, 0, 0, 0, 127));
            imagealphablending($thumbImage, false);
            imagesavealpha($thumbImage, true);
        }

        // Resize and crop (center crop)
        $srcRatio = $width / $height;
        $thumbRatio = $thumbWidth / $thumbHeight;

        if ($srcRatio > $thumbRatio) {
            // Source is wider
            $newHeight = $thumbHeight;
            $newWidth = (int)($thumbHeight * $srcRatio);
        } else {
            // Source is taller or equal
            $newWidth = $thumbWidth;
            $newHeight = (int)($thumbWidth / $srcRatio);
        }

        $tempImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($tempImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Crop center
        $x = ($newWidth - $thumbWidth) / 2;
        $y = ($newHeight - $thumbHeight) / 2;
        imagecopy($thumbImage, $tempImage, 0, 0, $x, $y, $thumbWidth, $thumbHeight);

        // Save the thumbnail
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($thumbImage, $destPath, 85); // Quality 85
                break;
            case IMAGETYPE_PNG:
                imagepng($thumbImage, $destPath);
                break;
            case IMAGETYPE_GIF:
                imagegif($thumbImage, $destPath);
                break;
        }

        // Free memory
        imagedestroy($sourceImage);
        imagedestroy($tempImage);
        imagedestroy($thumbImage);
    }

    public function getWorkspaceKnowledge( array $workspace, DatabaseManager $databaseManager ): array 
    {
        $q = $this->entityManager->createQueryBuilder();
        $knowledges = $q->select('b.id, b.title, b.slug, b.content, b.type, b.created_at')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.type = :block_type')
            ->andWhere('b.parent = :parent')
            ->andWhere('b.status = :block_status')
            ->setParameter('block_type', 'knowledge')
            ->setParameter('block_status', 1)
            ->setParameter('parent', $workspace['id'])
            ->orderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
        return $knowledges;
    }

    public function saveKnowledge( DatabaseManager $databaseManager, array $workspace, Request $request ): bool 
    {
        $note = $request->request->get('note');
        $file = $request->files->get('file');
        $originalFilename = '';
        if( $file ) {
            $originalFilename = $file->getClientOriginalName();
        }

        if( $originalFilename == '' && trim( $note ) == '' ) {
            return false;
        } else {
            $user_id = $databaseManager->getCurrentUser();
            $block_data = array(
                'type'      => 'knowledge',
                'title'     => ( $originalFilename != '' ? $originalFilename : substr($note, 0, 30 ) ),
                'content'   => '',
                'parent'    => $workspace['id']
            );

            $block = $databaseManager->addBlock( $user_id, $block_data, '' );
            $databaseManager->addMeta( 'knowledge', $block['id'], 'note', $note );
            $filesource = '';
        
            if( $file ) {
                // Generate a unique filename and move to the desired directory
                $uploadsDirectory = '/tmp/';
                $newFilename = uniqid() . '_' . $originalFilename;
                $file->move($uploadsDirectory, $newFilename);

                $s3 = new S3Client([
                    'version' => 'latest',
                    'region'  => $_ENV['AWS_REGION'],
                    'credentials' => [
                        'key'    => $_ENV['AWS_ACCESS_KEY'],
                        'secret' => $_ENV['AWS_SECRET_KEY'],
                    ],
                ]);

                try {
                    $result = $s3->putObject([
                        'Bucket' => $_ENV['AWS_S3_BUCKET'],
                        'Key'    => 'knowledge/' . $workspace['id'] . '/' . $block['id'] . '/' . $newFilename,
                        'SourceFile' => $uploadsDirectory . $newFilename
                    ]);

                    if( $result ) {
                        $databaseManager->addMeta( 'knowledge', $block['id'], 'file', $newFilename );
                    }

                    $filesource = $uploadsDirectory . $newFilename;
                } catch (\Exception $e) {
                    //do nothing
                }
            }

            //save the embedding format in the s3
            $this->saveKnowledgeEmbedding( $block, $note, $filesource );
        }
        return ( $block ? true : false );
    }

    function saveKnowledgeEmbedding( array $block, string $note, string $filesource ): bool 
    {
        set_time_limit(300);
        $embeddingDatabase = [];
        $big_chunk = '';
        if( $note != '' ) $big_chunk .= 'Note added on ' . $block['created_at']->format('Y-m-d H:i:s') . PHP_EOL . $note;
        if( $filesource != '' ) {
            if( $big_chunk != '' ) $big_chunk .= PHP_EOL;
            $big_chunk .= 'File added on ' . $block['created_at']->format('Y-m-d H:i:s') . PHP_EOL;
            $big_chunk .= $this->local_file_ocr( $filesource );
            unlink( $filesource );
        }

        $textChunks = $this->splitText($big_chunk, 256);
        $chunks = $this->prepareEmbeddingsBatch( $textChunks );
        
        $qdrant = new QdrantManager(
            qdrantUrl: $_ENV['QDRANT_CLUSTER_URL'],
            qdrantApiKey: $_ENV['QDRANT_API_KEY'],
            collection: 'dataset-' . $block['id']
        );

        // Create collection for 768-dim embeddings
        $qdrant->createCollection(1536, 'Cosine');

        // Upsert multiple chunks
        $result = $qdrant->upsertChunks($chunks);
        return true;
    }

    public function deleteKnowledge( DatabaseManager $databaseManager, array $workspace, Request $request ): bool 
    {
        $content = json_decode($request->getContent(), true);
        $id = $content['id'];

        $s3 = new S3Client([
            'version' => 'latest',
            'region'  => $_ENV['AWS_REGION'],
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY'],
                'secret' => $_ENV['AWS_SECRET_KEY'],
            ],
        ]);

        $file = $databaseManager->getMeta('knowledge', $id, 'file');
        if( $file && $file != '' ) {
            try {
                $result = $s3->deleteObject([
                    'Bucket' => $_ENV['AWS_S3_BUCKET'],
                    'Key'    => 'knowledge/' . $workspace['id'] . '/' . $content['id'] . '/' . $file,
                ]);
            } catch (Aws\Exception\AwsException $e) {
                echo "Error: " . $e->getMessage();
            }
        }

        $qdrant = new QdrantManager(
            qdrantUrl: $_ENV['QDRANT_CLUSTER_URL'],
            qdrantApiKey: $_ENV['QDRANT_API_KEY'],
            collection: 'dataset-' . $id
        );

        $qdrant->deleteCollection();

        $databaseManager->deleteBlock($id);
        return true;
    }

    public function getKnowledge( DatabaseManager $databaseManager, array $workspace, int $id ): array|null
    {
        $file = $databaseManager->getMeta('knowledge', $id, 'file');
        $note = $databaseManager->getMeta('knowledge', $id, 'note');
        $url = '';
        if( $file && $file != '' ) {
            $s3 = new S3Client([
                'version' => 'latest',
                'region'  => $_ENV['AWS_REGION'],
                'credentials' => [
                    'key'    => $_ENV['AWS_ACCESS_KEY'],
                    'secret' => $_ENV['AWS_SECRET_KEY'],
                ],
            ]);

            try {
                $request = $s3->createPresignedRequest(
                    $s3->getCommand('GetObject', [
                        'Bucket' => $_ENV['AWS_S3_BUCKET'],
                        'Key' => 'knowledge/' . $workspace['id'] . '/' . $id . '/' . $file,
                    ]),
                    '+1 hour'
                );
                $url = (string) $request->getUri();
                //$url = $s3->getObjectUrl($_ENV['AWS_S3_BUCKET'], 'knowledge/' . $workspace['id'] . '/' . $id . '/' . $file);
            } catch (Aws\Exception\AwsException $e) {
                echo "Error: " . $e->getMessage();
            }
        }
        return array( 'note' => $note, 'file' => $url );
    }

    public function getWorkspaceProfiles( array $workspace, DatabaseManager $databaseManager, int $page ): array 
    {
        $q = $this->entityManager->createQueryBuilder();
        $threads = $q->select('b.id, b.title, b.slug, b.content, b.type, b.created_at')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.type = :block_type')
            ->andWhere('b.parent = :parent')
            ->andWhere('b.status = :status')
            ->setParameter('block_type', 'thread')
            ->setParameter('parent', $workspace['id'])
            ->setParameter('status', 1)
            ->orderBy('b.id', 'DESC')
            ->setFirstResult(($page - 1) * 20)
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        
        if(count($threads) > 0) {
            // Get only description and logo metadata for each workspace
            foreach ($threads as $key => $block) {
                $q2 = $this->entityManager->createQueryBuilder();
                $meta_values = $q2->select('m.meta_key, m.meta_value')
                    ->from('App\Entity\Metas', 'm')
                    ->where('m.parent = :parent')
                    ->andWhere('m.parent_id = :parent_id')
                    ->andWhere('m.status = :status')
                    ->andWhere($q2->expr()->in('m.meta_key', ['last_seen_from_admin', 'last_seen_from_client']))
                    ->setParameter('parent', 'thread')
                    ->setParameter('parent_id', $block['id'])
                    ->setParameter('status', 1)
                    ->getQuery()
                    ->getResult();

                $metas = [];
                if ($meta_values) {
                    foreach ($meta_values as $meta) {
                        $metas[$meta['meta_key']] = $meta['meta_value'];
                    }
                    $threads[$key]['metas'] = $metas;
                }
            }
        }
        return $threads;
    }

    public function getWorkspaceEntries( array $workspace, DatabaseManager $databaseManager ): array 
    {
        $q = $this->entityManager->createQueryBuilder();
        $entries = $q->select('m.meta_value')
            ->from('App\Entity\Blocks', 'b')
            ->from('App\Entity\Metas', 'm')
            ->where('b.type = :block_type')
            ->andWhere('b.type = m.parent')
            ->andWhere('b.id = m.parent_id')
            ->andWhere('m.meta_key = :meta_key')
            ->andWhere('b.parent = :parent')
            ->andWhere('b.status = :status')
            ->setParameter('block_type', 'thread')
            ->setParameter('parent', $workspace['id'])
            ->setParameter('meta_key', 'collected_information')
            ->setParameter('status', 1)
            ->orderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
        return $entries;
    }



    public function addNewProfile( DatabaseManager $databaseManager, array $workspace, Request $request ): bool 
    {
        $content = json_decode($request->getContent(), true);
        $user_id = $databaseManager->getCurrentUser();
        $block_data = array(
            'type'      => 'thread',
            'title'     => ( isset( $content['title'] ) && $content['title'] != '' ? $content['title'] : 'Untitled' ),
            'content'   => '',
            'parent'    => $workspace['id']
        );

        $block = $databaseManager->addBlock( $user_id, $block_data, '' );
        return ( $block ? true : false );
    }

    public function deleteProfile( DatabaseManager $databaseManager, array $workspace, Request $request ): bool 
    {
        $content = json_decode($request->getContent(), true);
        $id = $content['id'];
        $databaseManager->deleteBlock($id);
        return true;
    }

    public function getWorkspaceProfile( int $workspace_id, string $slug, DatabaseManager $databaseManager ): array|null 
    {
        $q = $this->entityManager->createQueryBuilder();
        $profile = $q->select('b.id, b.title, b.slug')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.slug = :slug')
            ->andWhere('b.type = :block_type')
            ->andWhere('b.parent = :parent')
            ->setParameter('block_type', 'thread')
            ->setParameter('slug', $slug )
            ->setParameter('parent', $workspace_id)
            ->getQuery()
            ->getOneOrNullResult();

        //return collected information from queries if workspace is set to collect information
        if( $profile != null ) {
            $collected_information = $databaseManager->getMeta('thread', $profile['id'], 'collected_information');
            if( $collected_information != null ) {
                $collected_information = json_decode( $collected_information, true );
            } else {
                $collected_information = [];
            }
            $profile['collected_information'] = $collected_information;
        }
        
        return $profile;
    }

    public function getProfileKnowledges( array $workspace, string $profile_slug, DatabaseManager $databaseManager ): array 
    {
        $profile = $this->getWorkspaceProfile( $workspace['id'], $profile_slug, $databaseManager );
        $knowledge = null;

        if( $profile ) {
            $q = $this->entityManager->createQueryBuilder();
            $knowledges = $q->select('b.id, b.title, b.slug, b.content, b.type, b.created_at')
                ->from('App\Entity\Blocks', 'b')
                ->where('b.type = :block_type')
                ->andWhere('b.parent = :parent')
                ->andWhere('b.status = :status')
                ->setParameter('block_type', 'knowledge')
                ->setParameter('parent', $profile['id'])
                ->setParameter('status', 1)
                ->orderBy('b.id', 'DESC')
                ->getQuery()
                ->getResult();
        }
        return [ $profile, $knowledges ];
    }

    public function getProfileKnowledge( DatabaseManager $databaseManager, array $workspace, int $profile_id, int $id ): array|null
    {
        $file = $databaseManager->getMeta('knowledge', $id, 'file');
        $note = $databaseManager->getMeta('knowledge', $id, 'note');
        $url = '';
        if( $file && $file != '' ) {
            $s3 = new S3Client([
                'version' => 'latest',
                'region'  => $_ENV['AWS_REGION'],
                'credentials' => [
                    'key'    => $_ENV['AWS_ACCESS_KEY'],
                    'secret' => $_ENV['AWS_SECRET_KEY'],
                ],
            ]);

            try {
                $request = $s3->createPresignedRequest(
                    $s3->getCommand('GetObject', [
                        'Bucket' => $_ENV['AWS_S3_BUCKET'],
                        'Key' => 'knowledge/' . $workspace['id'] . '/' . $profile_id . '/' . $id . '/' . $file,
                    ]),
                    '+1 hour'
                );
                $url = (string) $request->getUri();
            } catch (Aws\Exception\AwsException $e) {
                echo "Error: " . $e->getMessage();
            }
        }
        return array( 'note' => $note, 'file' => $url );
    }

    public function saveProfileKnowledge( DatabaseManager $databaseManager, array $workspace, string $profile_slug, Request $request ): bool 
    {
        $profile = $this->getWorkspaceProfile( $workspace['id'], $profile_slug, $databaseManager );
        $note = $request->request->get('note');
        $shared = $request->request->get('shared');
        $file = $request->files->get('file');
        $originalFilename = '';
        $filesource = '';
        if( $file ) {
            $originalFilename = $file->getClientOriginalName();
        }

        if( $originalFilename == '' && trim( $note ) == '' ) {
            return false;
        } else {
            $user_id = $databaseManager->getCurrentUser();
            $block_data = array(
                'type'      => 'knowledge',
                'title'     => ( $originalFilename != '' ? $originalFilename : substr($note, 0, 30 ) ),
                'content'   => '',
                'parent'    => $profile['id']
            );

            $block = $databaseManager->addBlock( $user_id, $block_data, '' );
            $databaseManager->addMeta( 'knowledge', $block['id'], 'note', $note );
            $databaseManager->addMeta( 'knowledge', $block['id'], 'shared', $shared );

            if( $file ) {
                // Generate a unique filename and move to the desired directory
                $uploadsDirectory = '/tmp/';
                $newFilename = uniqid() . '_' . $originalFilename;
                $file->move($uploadsDirectory, $newFilename);

                $s3 = new S3Client([
                    'version' => 'latest',
                    'region'  => $_ENV['AWS_REGION'],
                    'credentials' => [
                        'key'    => $_ENV['AWS_ACCESS_KEY'],
                        'secret' => $_ENV['AWS_SECRET_KEY'],
                    ],
                ]);

                try {
                    $result = $s3->putObject([
                        'Bucket' => $_ENV['AWS_S3_BUCKET'],
                        'Key'    => 'knowledge/' . $workspace['id'] . '/' . $profile['id'] . '/' . $block['id'] . '/' . $newFilename,
                        'SourceFile' => $uploadsDirectory . $newFilename,
                    ]);

                    if( $result ) {
                        $databaseManager->addMeta( 'knowledge', $block['id'], 'file', $newFilename );
                    }

                    $filesource = $uploadsDirectory . $newFilename;
                    
                } catch (\Exception $e) {
                    //do nothing
                }
            }

            //save the embedding format in the s3
            $this->saveKnowledgeEmbedding( $block, $note, $filesource );
        }

        return ( $block ? true : false );
    }

    public function deleteProfileKnowledge( DatabaseManager $databaseManager, array $workspace, int $profile_id, Request $request ): bool 
    {
        $content = json_decode($request->getContent(), true);
        $id = $content['id'];

        $s3 = new S3Client([
            'version' => 'latest',
            'region'  => $_ENV['AWS_REGION'],
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY'],
                'secret' => $_ENV['AWS_SECRET_KEY'],
            ],
        ]);

        $file = $databaseManager->getMeta('knowledge', $id, 'file');
        if( $file && $file != '' ) {
            try {
                $result = $s3->deleteObject([
                    'Bucket' => $_ENV['AWS_S3_BUCKET'],
                    'Key'    => 'knowledge/' . $workspace['id'] . '/' . $profile_id . '/' . $content['id'] . '/' . $file,
                ]);
            } catch (Aws\Exception\AwsException $e) {
                echo "Error: " . $e->getMessage();
            }
        }

        $qdrant = new QdrantManager(
            qdrantUrl: $_ENV['QDRANT_CLUSTER_URL'],
            qdrantApiKey: $_ENV['QDRANT_API_KEY'],
            collection: 'dataset-' . $content['id']
        );

        $qdrant->deleteCollection();

        $databaseManager->deleteBlock($id);
        return true;
    }
    
    public function getProfileMessages( array $workspace, string $profile_slug, string $after, DatabaseManager $databaseManager ): array|null
    {
        $profile = $this->getWorkspaceProfile( $workspace['id'], $profile_slug, $databaseManager );
        $messages = null;
        if( $profile ) {
            $q = $this->entityManager->createQueryBuilder();
            $messages = $q->select('b.id, b.author, b.title, b.slug, b.content, b.type, b.created_at')
                ->from('App\Entity\Blocks', 'b')
                ->where('b.type = :block_type')
                ->andWhere('b.parent = :parent')
                ->andWhere('b.status = :status')
                ->setParameter('block_type', 'message')
                ->setParameter('parent', $profile['id'])
                ->setParameter('status', 1);

            if ($after != '' ) {
                $q->andWhere('b.id > :after')
                ->setParameter('after', (int) $after);
            }

            $messages = $q->orderBy('b.id', 'ASC')
                ->getQuery()
                ->getResult();
        
            $messages_live = [];
            if( count( $messages ) > 0 ) {
                for( $i = 0; $i < count( $messages ); $i++ ) {
                    if( $messages[$i]['author'] < 0 ) {
                        $generated_response = $databaseManager->getMeta('message', $messages[$i]['id'], 'generated_response');
                        if( $generated_response ) {
                            $messages[$i]['generated_response'] = $generated_response;
                            $messages_live[] = $messages[$i];
                        }
                    } else {
                        $messages_live[] = $messages[$i];
                    }
                }
            }

            $databaseManager->addMeta('thread', $profile['id'], 'last_seen_from_admin', date('Y-m-d H:i:s'));
        }
        return [ $profile, $messages_live ];
    }

    public function sendMessage( DatabaseManager $databaseManager, array $workspace, string $profile_slug, Request $request ): array|null
    {
        $profile = $this->getWorkspaceProfile( $workspace['id'], $profile_slug, $databaseManager );
        $content = json_decode($request->getContent(), true);
        $message = $content['message'];
        $user_id = $databaseManager->getCurrentUser();
        $block_data = array(
            'type'      => 'message',
            'title'     => substr($message, 0, 30 ),
            'content'   => $message,
            'parent'    => $profile['id']
        );

        $block = $databaseManager->addBlock( $user_id, $block_data, '' );
        return ( $block ? $block : null );
    }

    public function getProfile( string $slug, DatabaseManager $databaseManager ): array|null 
    {
        $q = $this->entityManager->createQueryBuilder();
        $profile = $q->select('b.id, b.title, b.slug, b.parent, b.author')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.slug = :slug')
            ->andWhere('b.type = :block_type')
            ->andWhere('b.status = :block_status')
            ->setParameter('block_type', 'thread')
            ->setParameter('slug', $slug )
            ->setParameter('block_status', 1 )
            ->getQuery()
            ->getOneOrNullResult();
        return $profile;
    }

    public function getContactWorkspace( string $slug, DatabaseManager $databaseManager ): array|null 
    {
        $q = $this->entityManager->createQueryBuilder();
        $workspace = $q->select('b.id, b.title, b.slug, b.parent, b.author')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.slug = :slug')
            ->andWhere('b.type = :block_type')
            ->setParameter('block_type', 'workspace')
            ->setParameter('slug', $slug )
            ->getQuery()
            ->getOneOrNullResult();
        
        if( $workspace != null ) {
            $q = $this->entityManager->createQueryBuilder();
            $meta_values = $q->select('m.meta_key, m.meta_value')
            ->from('App\Entity\Metas', 'm')
            ->where('m.parent = :parent')
            ->andWhere('m.parent_id = :parent_id')
            ->andWhere('m.status = :status')
            ->setParameter('parent_id', $workspace['id'])
            ->setParameter('parent', 'workspace')
            ->setParameter('status', 1 )
            ->getQuery()
            ->getResult();
            $metas = [];
            if( $meta_values != null ) {
                foreach( $meta_values as $meta ) {
                    $metas[$meta['meta_key']] = $meta['meta_value'];
                }
                $workspace['metas'] = $metas;
            }
        }
        return $workspace;
    }

    public function getProfileWorkspace( int $id, DatabaseManager $databaseManager ): array|null 
    {
        $q = $this->entityManager->createQueryBuilder();
        $workspace = $q->select('b.id, b.title, b.slug, b.parent, b.author')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.id = :id')
            ->andWhere('b.type = :block_type')
            ->setParameter('block_type', 'workspace')
            ->setParameter('id', $id )
            ->getQuery()
            ->getOneOrNullResult();
        
        if( $workspace != null ) {
            $q = $this->entityManager->createQueryBuilder();
            $meta_values = $q->select('m.meta_key, m.meta_value')
            ->from('App\Entity\Metas', 'm')
            ->where('m.parent = :parent')
            ->andWhere('m.parent_id = :parent_id')
            ->andWhere('m.status = :status')
            ->setParameter('parent_id', $workspace['id'])
            ->setParameter('parent', 'workspace')
            ->setParameter('status', 1 )
            ->getQuery()
            ->getResult();
            $metas = [];
            if( $meta_values != null ) {
                foreach( $meta_values as $meta ) {
                    $metas[$meta['meta_key']] = $meta['meta_value'];
                }
                $workspace['metas'] = $metas;
            }
        }
        return $workspace;
    }

    public function prepareChatKnowledge( string $slug, DatabaseManager $databaseManager ): bool|null
    {
        if( file_exists('/tmp/_dataset-' . $slug . '.json')) {
            //do nothing, dataset already exists
        } else {
            $embeddingDatabase = [];
            //Find the profile
            $profile = $this->getProfile( $slug, $databaseManager );
            //Find the messages
            if( $profile ) {
                $q = $this->entityManager->createQueryBuilder();
                $knowledges = $q->select('b.id, b.parent, b.created_at')
                    ->from('App\Entity\Blocks', 'b')
                    ->where('b.type = :block_type')
                    ->andWhere(
                        $q->expr()->orX(
                            'b.parent = :workspace_parent',
                            'b.parent = :profile_parent'
                        )
                    )
                    ->andWhere('b.status = :block_status') // Fixed incorrect syntax
                    ->setParameter('block_type', 'knowledge')
                    ->setParameter('workspace_parent', $profile['parent'])
                    ->setParameter('profile_parent', $profile['id'])
                    ->setParameter('block_status', 1) // Fixed parameter name
                    ->orderBy('b.id', 'ASC')
                    ->getQuery()
                    ->getResult();

                if( count( $knowledges ) > 0 ) {
                    

                    for( $i = 0; $i < count( $knowledges ); $i++ ) {
                        $qdrant = new QdrantManager(
                            qdrantUrl: $_ENV['QDRANT_CLUSTER_URL'],
                            qdrantApiKey: $_ENV['QDRANT_API_KEY'],
                            collection: 'dataset-' . $knowledges[$i]['id']
                        );

                        $object = $qdrant->getAllPoints(1000);
                        $embeddingDatabase = array_merge( $embeddingDatabase, $object );
                    }
                }
            }
            
            if ($embeddingDatabase) {
                file_put_contents('/tmp/dataset-' . $slug . '.json', json_encode( $embeddingDatabase ));
            } else {
                //echo "No embeddings returned.\n";
            }
        }
        return true;
    }

    // Function to split text into chunks (e.g., 256 characters per chunk)
    function splitText($text, $chunkSize = 256, $minSize = 200) {
        $sentences = preg_split('/(?<=\.)\s+/', $text); // Split on periods with space
        $chunks = [];
        $currentChunk = "";
  
        foreach ($sentences as $sentence) {
            if (strlen($currentChunk) + strlen($sentence) < $minSize) {
                $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
            } else {
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $sentence;
            }
        }
    
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }
    
        return $chunks;
    }

    function cleanText($text) {
        // Convert to proper UTF-8 encoding
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
  
        // Remove non-ASCII characters (Unicode symbols, etc.)
        $text = preg_replace('/[^\x20-\x7E]/u', ' ', $text);
  
        // Normalize multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);
  
        return trim($text);
  }

    public function local_file_ocr( string $filesource ):null|string
    {
        $extension = strtolower(pathinfo($filesource, PATHINFO_EXTENSION));
        if ($extension === 'pdf') {
            // Parse PDF
            $pdfParser = new Parser();
            $pdf = $pdfParser->parseContent( file_get_contents( $filesource ) );
            $text = $pdf->getText();
        }

        //read file return text
        return $text;
    }

    public function prepareEmbeddingsBatch($chunks) {
        $client = new BedrockRuntimeClient([
            'region'  => $_ENV['AWS_REGION'],
            'version' => 'latest',
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY'],
                'secret' => $_ENV['AWS_SECRET_KEY'],
            ],
        ]);

        $promises = [];
        foreach ($chunks as $chunk) {
            if( trim( $chunk ) != '' ) {
                $chunk = $this->cleanText($chunk, 'UTF-8', 'auto');
                $payload = ['inputText' => $chunk];
                $body = json_encode($payload, JSON_THROW_ON_ERROR);
                $promises[] = $client->invokeModelAsync([
                    'modelId'     => 'amazon.titan-embed-text-v1',
                    'contentType' => 'application/json',
                    'accept'      => 'application/json',
                    'body'        => $body,
                ])->then(
                    function ($response) use ($chunk, &$embeddings) {
                        $body = json_decode($response['body']->getContents(), true, 512, JSON_THROW_ON_ERROR);
                        $embeddings[] = [
                            'text' => $chunk,
                            'embedding' => $body['embedding'] ?? []
                        ];
                    },
                    function ($reason) {
                        error_log("AWS Bedrock API Error: " . $reason->getMessage());
                    }
                );
            }
        }

        // Wait for all embeddings to process
        $responses = Utils::unwrap($promises);

        return $embeddings;
    }

    public function prepareEmbedding( string $text ): array|bool 
    {
        // AWS Bedrock Configuration
        $client = new BedrockRuntimeClient([
            'region'  => $_ENV['AWS_REGION'],
            'version' => 'latest',
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY'],
                'secret' => $_ENV['AWS_SECRET_KEY'],
            ],
        ]);

        $text = mb_convert_encoding($text, 'UTF-8', 'auto');

        // Ensure input is in the correct format
        $payload = ['inputText' => $text];

        // JSON encode the payload
        //$body = json_encode($payload);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        // Invoke the Bedrock Titan Embeddings model
        $result = $client->invokeModel([
            'modelId' => 'amazon.titan-embed-text-v1',
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => $body,
        ]);

        // Decode response JSON
        $response = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
        return $response['embedding'] ?? [];
    }

    public function getRole( string $role ): string|null 
    {
        $roles = array(
            'executive' => 'Executive',
            'recruiter' => 'Recruiter',
            'support' => 'Client Support Agent',
            'legal' => 'Legal Advisor',
            'sales' => 'Sales Representative',
            'technical' => 'Technical Support Agent',
            'marketing' => 'Marketing Specialist'
        );
        return $roles[$role] ?? null;
    }

    // Before asking inferencing
    // Measure if the query is a question or an answer
    // Also verify if the workspace is set for asking for information
    // See if the responses mathes with the questions and save, find a follow up question
    public function getWorkspacePrompt( string $slug, DatabaseManager $databaseManager ): array|string|null 
    {
        $q = $this->entityManager->createQueryBuilder();
        $thread = $q->select('b.id, b.parent, b.type, b.title')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.type = :block_type')
            ->andWhere('b.slug = :slug')
            ->andWhere('b.status = :status')
            ->setParameter('block_type', 'thread')
            ->setParameter('slug', $slug)
            ->setParameter('status', 1 )
            ->getQuery()
            ->getOneOrNullResult();
        
        if( $thread != null ) {
            $q = $this->entityManager->createQueryBuilder();
            $workspace_meta_values = $q->select('m.meta_key, m.meta_value')
                ->from('App\Entity\Metas', 'm')
                ->where('m.parent = :parent')
                ->andWhere('m.parent_id = :parent_id')
                ->andWhere('m.status = :status')
                ->setParameter('parent_id', $thread['parent'])
                ->setParameter('parent', 'workspace')
                ->setParameter('status', 1 )
                ->getQuery()
                ->getResult();
            $workspace_metas = [];
            if( $workspace_meta_values != null ) {
                foreach( $workspace_meta_values as $meta ) {
                    $workspace_metas[$meta['meta_key']] = $meta['meta_value'];
                }
            }
        }

        $question_prompt = '';
        //measure the query if it is a question or an answer
        //see if the workspace is set for asking for information
        if( isset( $workspace_metas['collect_information'] ) && $workspace_metas['collect_information'] == 'true' && isset( $workspace_metas['questionnaire'] ) && $workspace_metas['questionnaire'] != '' ) {
            //workspace collects information
            $questionnaire = json_decode( $workspace_metas['questionnaire'], true );
            
            //see if already answered
            $collected_information = $databaseManager->getMeta('thread', $thread['id'], 'collected_information');
            if( $collected_information != null ) {
                $collected_information = json_decode( $collected_information, true );
            } else {
                $collected_information = [];
            }
            
            //create a context with the client queries and generated responses
            [ $user_responses, $messages ] = $this->getThreadResponses( $thread['id'], $databaseManager );

            //echo '<pre>';
            //var_dump( $messages ); die();

            if( $user_responses != '' ) {
                $answer_prompt = '';
                $prompt = '';
                $prompt .= 'Act as an AI assistant on behalf of ' . ( isset( $workspace_metas['role'] ) && $workspace_metas['role'] != '' ? $this->getRole( $workspace_metas['role'] ) : 'a real person' ) . '. Find the best matching precise information for the following fields: ' . PHP_EOL;
                
                foreach( $questionnaire as $key => $question ) {
                    if( $questionnaire[$key] != '' ) {
                        $prompt .= $questionnaire[$key] . PHP_EOL;
                        $question_prompt .= $questionnaire[$key] . ': ' . ( isset( $collected_information[ $questionnaire[$key] ] ) ? $collected_information[ $questionnaire[$key] ] : 'missing' ) . PHP_EOL;
                        $answer_prompt .= ( $answer_prompt != '' ? ',' : '' ) . '"' . $questionnaire[$key] . '" : "finding or empty"';
                    }
                }
                $prompt .= 'Also check the sentence "' . $messages[ count( $messages ) - 1 ]['content'] . '" if it is a question or an answer. Generate only a JSON response. Fill up this given JSON: ' . PHP_EOL;
                $prompt .= '{"title": "Generate a title of this conversation", "is_query": "true/false", "answers": [' . $answer_prompt . '], "response":"generated response"}' . PHP_EOL;
                $prompt .= 'Respond with greeting only once if required. Ask a follow up question if the last response was not a query to find answer for the given fields and some answers are still missing. Ask one question at a time. Do not repeat any question already asked. Do not ask anything outside of the given fields. Confirm information noted and finish the conversation all the fields are filled. Also, let the user know that he can ask for any information if needed. Use ' . ( isset( $workspace_metas['tone'] ) && $workspace_metas['tone'] != '' ? $workspace_metas['tone'] : 'polite' ) . ' professional tone' . ( isset( $workspace_metas['prompt'] ) && $workspace_metas['prompt'] != '' ? PHP_EOL . $workspace_metas['prompt'] : '' ) . PHP_EOL;
            }

            $question_response = $this->generateBedrockText( $prompt, $messages );

            if( $question_response != '' ) {
                $qresponse = json_decode( str_replace( ['```json', '```'], '', $question_response ), true );
                if( isset( $qresponse['answers'] ) && is_array( $qresponse['answers'] ) ) {
                    foreach( $qresponse['answers'] as $key => $answer ) {
                        if( $answer != ''  && $answer != 'empty' ) {
                            $collected_information[$key] = $answer;
                        }
                    }
                }
                $databaseManager->addMeta('thread', $thread['id'], 'collected_information', json_encode($collected_information) );
                
                //rename thread if title is not set properly - only if more than 50% answers are filled
                if(strlen( $user_responses ) > 200 && strpos($thread['title'], ':') !== false && strpos($thread['title'], '-') === false) {
                    $q = $this->entityManager->createQueryBuilder();
                    $q->update('App\Entity\Blocks', 'b')
                        ->set('b.title', ':title')
                        ->where('b.id = :id')
                        ->setParameter('title', $thread['title'] . ' - ' . $qresponse['title'])
                        ->setParameter('id', $thread['id'])
                        ->getQuery()
                        ->execute();
                }
            }
        }
        
        if( !isset( $qresponse ) || $qresponse['is_query'] == 'true' ) {
            $prompt = 'Act as an AI assistant on behalf of ' . ( isset( $workspace_metas['role'] ) && $workspace_metas['role'] != '' ? $this->getRole( $workspace_metas['role'] ) : 'a real person' ) . ' that only answers based on the provided context in a ' . ( isset( $workspace_metas['tone'] ) && $workspace_metas['tone'] != '' ? $workspace_metas['tone'] : 'polite' ) . ' professional tone. Contexts are from common workspace knowledge and information related to the person asked. Do not use external knowledge or recommend for other options to communicate. Describe only useful information, write in brief. Don\'t mention anything about context or how the response is generated. ' . ( $question_prompt != '' ? 'Also collecting following information: ' . PHP_EOL . $question_prompt . 'Also ask for information one at a time if answer is missing.' : '' ) . ( isset( $workspace_metas['prompt'] ) && $workspace_metas['prompt'] != '' ? PHP_EOL . $workspace_metas['prompt'] : '' ) . '
Context: 
[[CONTEXT]]
The user asked: [[QUERY]]
Answer is:';
            return $prompt;
        } else return $qresponse;
    }

    public function getThreadResponses( int $id, DatabaseManager $databaseManager ): array|string|null 
    {
        $q = $this->entityManager->createQueryBuilder();
        $messages = $q->select('b.id, b.author, b.title, b.slug, b.content, b.type, b.created_at')
            ->from('App\Entity\Blocks', 'b')
            ->where($q->expr()->in('b.type', ['message', 'knowledge']))
            ->andWhere('b.parent = :parent')
            ->andWhere('b.status = :status')
            ->setParameter('parent', $id)
            ->setParameter('status', 1)
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
        
        $history = [];
        $responses = '';
        foreach( $messages as $key => $message ) {
            if( $message['type'] == 'message' ) {
                $history[] = [
                    'role' => ( $message['author'] < 0 ? 'user' : 'assistant' ),
                    'content' => $message['content'],
                ];

                $responses .= ( $responses != '' ? PHP_EOL : '' ) . 'Client responded: ' . $message['content'];
            } elseif( $message['type'] == 'knowledge' ) {
                $responses .= ( $responses != '' ? PHP_EOL : '' ) . 'Client sent a file: ' . $message['title'];
                
                $history[] = [
                    'role' => ( $message['author'] < 0 ? 'user' : 'assistant' ),
                    'content' => ( $message['author'] < 0 ? 'user' : 'assistant' ) . ' uploaded a file titled "' . $message['title'] . '".',
                ];
            }
            $generated_response = $databaseManager->getMeta('message', $message['id'], 'generated_response');
            if( $generated_response ) {
                $history[] = [
                    'role' => 'assistant',
                    'content' => $generated_response,
                ];

                $responses .= ( $responses != '' ? PHP_EOL : '' ) . 'AI responded: ' . $generated_response;
            }
        }
        
        return [$responses, $history];
    }
    //check if the information collection is enabled

    public function informationCollectionStatus( string $slug, string $id, databaseManager $databaseManager ): bool 
    {
        //See if information collection is enabled
        //See if information collection data is available (there will be a similar array field => data as questionnaire )
        //Collect all the messages received from client and ask AI to pick the best collection of answers
        //Return with the only missing questions
        return ( $status ? true : false );
    }

    public function generateResponse( string $slug, string $id, databaseManager $databaseManager, string|array $prompt ): null|string 
    {
        $generated_response = $databaseManager->getMeta('message', $id, 'generated_response');
        if( !$generated_response ) {
            //get message
            $q = $this->entityManager->createQueryBuilder();
            $message = $q->select('b.id, b.author, b.content, b.type, b.parent')
                ->from('App\Entity\Blocks', 'b')
                ->where($q->expr()->in('b.type', ['message', 'knowledge']))
                ->andWhere('b.id = :block_id')
                ->andWhere('b.status = :status')
                ->setParameter('block_id', $id)
                ->setParameter('status', 1 )
                ->getQuery()
                ->getOneOrNullResult();

            if( $message) {
                if( isset( $prompt['response'] ) && $prompt['response'] != '' ) {
                    $generated_response = $prompt['response'];
                } else {
                    if( $message['type'] == 'knowledge' ) {
                        $generated_response = 'Got it! Thanks for uploading your file. Is there anything else you’d like to ask?';
                    } elseif( $message['type'] == 'message' && $message['content'] != '' ) {
                        $searchQuery = $message['content'];
                        $queryEmbedding = $this->prepareEmbedding($searchQuery);

                        $isPrepared = $this->prepareChatKnowledge( $slug, $databaseManager );
                        if( $isPrepared ) {
                            $embeddingDatabase = json_decode( ( is_file( '/tmp/dataset-' . $slug . '.json' ) ? file_get_contents( '/tmp/dataset-' . $slug . '.json' ) : '{}' ), true );
                        }

                        // Compare query embedding with stored embeddings
                        $similarities = [];
                        foreach ($embeddingDatabase as $data) {
                            $score = $this->cosineSimilarity($queryEmbedding, $data['embedding']);
                            if ($score >= 0.3) {
                                $similarities[] = [
                                    'text' => $data['text'],
                                    'score' => $score,
                                ];
                            }
                        }

                        // Sort by highest similarity
                        usort($similarities, fn($a, $b) => $b['score'] <=> $a['score']);
                
                        //convert into embedding
                        if( count( $similarities ) < 1 ) $contextText = 'We don\'t have any information for the query except the message history.';
                        else $contextText = implode("\n", array_column($similarities, 'text'));
                        $prompt = str_replace('[[CONTEXT]]',  $contextText, $prompt );
                        $prompt = str_replace('[[QUERY]]', $searchQuery, $prompt );

                        //get the thread responses
                        [ $user_responses, $messages ] = $this->getThreadResponses( $message['parent'], $databaseManager );

                        $generated_response = $this->generateBedrockText( $prompt, $messages );
                    }
                }

                $databaseManager->addMeta('message', $id, 'generated_response', $generated_response);
            } else {
                $generated_response = 'Thank you for your query. Our team will review it and respond shortly.';
            }
        }

        return $generated_response;
    }

    public function generateBedrockText( string $prompt, array $messages ): string 
    {
        // AWS Bedrock Configuration
        $client = new BedrockRuntimeClient([
            'region'  => $_ENV['AWS_REGION'],
            'version' => 'latest',
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY'],
                'secret' => $_ENV['AWS_SECRET_KEY'],
            ],
        ]);

        $messages[] = [
                    'role' => 'user',      // Assuming 'role' is required (could be 'user' or 'system' based on API)
                    'content' => $prompt,  // Your prompt content here
        ];

        $payload = json_encode([
            'messages'  => $messages
        ], JSON_THROW_ON_ERROR);

        $response = $client->invokeModel([
            'modelId'     => 'mistral.mistral-large-2407-v1:0',
            'contentType' => 'application/json',
            'accept'      => 'application/json',
            'body'        => $payload,
        ]);

      // Extract and return the generated response
      $body = json_decode($response['body']->getContents(), true, 512, JSON_THROW_ON_ERROR);
      
      $outputText = 'No response generated.';
      if( count( $body['choices'] ) ) {
        $outputText = trim( $body['choices'][0]['message']['content'] );
      }

      return $outputText;
    }

    // Function to compute cosine similarity
    public function cosineSimilarity($vecA, $vecB)
    {
        $dotProduct = array_sum(array_map(fn($a, $b) => $a * $b, $vecA, $vecB));
        $magnitudeA = sqrt(array_sum(array_map(fn($a) => $a ** 2, $vecA)));
        $magnitudeB = sqrt(array_sum(array_map(fn($b) => $b ** 2, $vecB)));

        return ($magnitudeA * $magnitudeB) ? ($dotProduct / ($magnitudeA * $magnitudeB)) : 0;
    }

    public function createChatContact( string $slug, string $title, DatabaseManager $databaseManager ): array|null
    {
        $workspace = $this->getContactWorkspace( $slug, $databaseManager );
        $profile = null;

        if( $workspace ) {
            $block_data = array(
                'type'      => 'thread',
                'title'     => ( $title != '' ? $title : 'New untitled thread' ),
                'content'   => '',
                'parent'    => $workspace['id'],
                'author'    => $workspace['author'],
                'slug'      => $slug
            );
            
            $profile = $databaseManager->addBlock( $workspace['author'], $block_data, '' );
        }
        
        return [$workspace, $profile];
    }

    public function getChatMessages( string $slug, string $after, DatabaseManager $databaseManager ): array|null
    {
        $profile = $this->getProfile( $slug, $databaseManager );
        $workspace = null;
        $messages = null;
        if( $profile ) {
            //Find the workspace
            $workspace = $this->getProfileWorkspace( $profile['parent'], $databaseManager );

            $q = $this->entityManager->createQueryBuilder();
            $messages = $q->select('b.id, b.author, b.title, b.slug, b.content, b.type, b.created_at')
                ->from('App\Entity\Blocks', 'b')
                ->where($q->expr()->in('b.type', ['message', 'knowledge']))
                ->andWhere('b.parent = :parent')
                ->andWhere('b.status = :status')
                ->setParameter('parent', $profile['id'])
                ->setParameter('status', 1);

            if ($after != '' ) {
            $q->andWhere('b.id > :after')
                ->setParameter('after', (int) $after);
            }

            $messages = $q->orderBy('b.id', 'ASC')
                ->getQuery()
                ->getResult();


            if(count($messages) > 0) {
                // Get only description and logo metadata for each workspace
                foreach ($messages as $key => $block) {
                    if( $block['type'] == 'knowledge' && $block['author'] > 0 ) {
                        $q2 = $this->entityManager->createQueryBuilder();
                        $meta_values = $q2->select('m.meta_key, m.meta_value')
                            ->from('App\Entity\Metas', 'm')
                            ->where('m.parent = :parent')
                            ->andWhere('m.parent_id = :parent_id')
                            ->andWhere('m.status = :status')
                            ->setParameter('parent', 'knowledge')
                            ->setParameter('parent_id', $block['id'])
                            ->setParameter('status', 1)
                            ->getQuery()
                            ->getResult();
        
                        $metas = [];
                        if ($meta_values) {
                            foreach ($meta_values as $meta) {
                                $metas[$meta['meta_key']] = $meta['meta_value'];
                            }
                            $messages[$key]['metas'] = $metas;
                        }
                    }
                }
            }
            
            //mark that seen by client
            $databaseManager->addMeta('thread', $profile['id'], 'last_seen_from_client', date('Y-m-d H:i:s'));
      }

        if( $messages && count( $messages ) > 0 ) {
            for( $i = 0; $i < count( $messages ); $i++ ) {
                if( $messages[$i]['author'] < 0 ) {
                    $generated_response = $databaseManager->getMeta('message', $messages[$i]['id'], 'generated_response');
                    if( $generated_response ) $messages[$i]['generated_response'] = $generated_response;
                }
            }
        }
        return [ $profile, $workspace, $messages ];
    }

    public function sendChatMessage( DatabaseManager $databaseManager, string $slug, Request $request ): array|null
    {
        $profile = $this->getProfile( $slug, $databaseManager );
        if( $profile ) {
            $content = json_decode($request->getContent(), true);
            $message = $content['message'];
            $user_id = -1;
            $block_data = array(
                'type'      => 'message',
                'title'     => substr($message, 0, 30 ),
                'content'   => $message,
                'parent'    => $profile['id']
            );

            $block = $databaseManager->addBlock( $user_id, $block_data, '' );
        }
      return ( $block ? $block : null );
    }

    public function sendFileMessage( DatabaseManager $databaseManager, string $slug, Request $request ): array|bool|null 
    {
        $file = $request->files->get('file');
        $originalFilename = '';
        if( $file ) {
            $originalFilename = $file->getClientOriginalName();
        }

        if( $originalFilename == '' ) {
            return false;
        } else {
            $profile = $this->getProfile( $slug, $databaseManager );
            $user_id = -1;
            $block_data = array(
                'type'      => 'knowledge',
                'title'     => ( $originalFilename != '' ? $originalFilename : substr($note, 0, 30 ) ),
                'content'   => '',
                'parent'    => $profile['id']
            );

            $block = $databaseManager->addBlock( $user_id, $block_data, '' );
            $filesource = '';
        
            if( $file ) {
                // Generate a unique filename and move to the desired directory
                $uploadsDirectory = '/tmp/';
                $newFilename = uniqid() . '_' . $originalFilename;
                $file->move($uploadsDirectory, $newFilename);

                $s3 = new S3Client([
                    'version' => 'latest',
                    'region'  => $_ENV['AWS_REGION'],
                    'credentials' => [
                        'key'    => $_ENV['AWS_ACCESS_KEY'],
                        'secret' => $_ENV['AWS_SECRET_KEY'],
                    ],
                ]);

                try {
                    $result = $s3->putObject([
                        'Bucket' => $_ENV['AWS_S3_BUCKET'],
                        'Key'    => 'knowledge/' . $profile['parent'] . '/' . $profile['id'] . '/' . $block['id'] . '/' . $newFilename,
                        'SourceFile' => $uploadsDirectory . $newFilename
                    ]);

                    if( $result ) {
                        $databaseManager->addMeta( 'knowledge', $block['id'], 'file', $newFilename );
                    }

                    $filesource = $uploadsDirectory . $newFilename;
                } catch (\Exception $e) {
                    //do nothing
                }
            }

            //save the embedding format in the s3
            $this->saveKnowledgeEmbedding( $block, '', $filesource );
        }
        return $block;
    }

    public function init_payment( int $level, int $entries, string $redirectUrl ): string
    {
        $secret_key = $this->params->get('stripe.secret_key');
        Stripe::setApiKey($secret_key);

        try {
            // Create a product (optional, can be reused)
            $product = \Stripe\Product::create([
                'name' => 'Intelligent web scrapper',
                'description' => 'Your AI data collection agent',
            ]);
      
            // Create a price for the product
            $price = \Stripe\Price::create([
                'unit_amount' => ( $entries * 2 ),
                'currency' => 'usd',
                'product' => $product->id,
            ]);
      
            // Create a payment link
            $paymentLink = \Stripe\PaymentLink::create([
                'line_items' => [
                    [
                        'price' => $price->id,
                        'quantity' => 1,
                    ],
                ],
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'url' => $redirectUrl, // Redirect after payment
                    ],
                ],
            ]);
      
            return $paymentLink->url;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            echo "Error: " . $e->getMessage() . PHP_EOL;
        }
    }

    public function validate_payment( string $sessionId ): bool
    {
        $customer = array();
        $status = false;
        $secret_key = $this->params->get('stripe.secret_key');
        Stripe::setApiKey($secret_key);

        try {
            // Retrieve the Checkout Session details
            $session = Session::retrieve([
                'id' => $sessionId,
                'expand' => ['line_items']
            ]);

            if( $session['payment_status'] == 'paid' && $session['line_items'] != null ) {
                $price_id = $session['line_items']['data'][0]['price']['id'];
                $customer = array( 'name' => $session['customer_details']['name'], 'email' => $session['customer_details']['email'] );
                $status = true;
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \Exception('Stripe Error: ' . $e->getMessage());
        }

        return $status;
    }

    public function get_block_by_slug( string $slug ): array 
    {
        $q = $this->entityManager->createQueryBuilder();
        $query = $q->select('b.id, b.slug, b.content, b.type')
            ->from('App\Entity\Blocks', 'b')
            ->where('b.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery();
        $block = $query->getResult();
        return $block;
    }

    public function generateTextGPT(string $prompt, string $model): array
    {
        $request = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => ( strlen( $prompt ) < 3500 ? $prompt : substr($prompt, 0, 3500) )
                ]
            ],
            'temperature' => 1,
            'max_tokens' => 2048,
            'top_p' => 1,
            'frequency_penalty' => 0,
            'presence_penalty' => 0
        ];

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->params->get('openai.key'),
                ],
                'json' => $request,
            ]);
        } catch (\Exception $e) {
          return array(
            'choices' => array(
                array(
                    'message' => array(
                        'content' => '{}'
                    )
                )
            )
          );
      }

      return $response->toArray();
    }

    public function send_email( string $recipient, string $subject, string $message_plain, string $message_html ): bool
    {
        $mj = new \Mailjet\Client($_ENV['MAILJET_API_KEY'], $_ENV['MAILJET_API_SECRET'],true,['version' => 'v3.1']);
        $body = [
            'Messages' => [
                [
                    'From' => [
                        'Email' => $_ENV['MAILJET_SENDER_EMAIL'],
                        'Name' => $_ENV['MAILJET_SENDER']
                    ],
                    'To' => [
                        [
                            'Email' => $recipient
                        ]
                    ],
                    'Subject' => $subject,
                    'TextPart' => $message_plain,
                    'HTMLPart' => $message_html
                ]
            ]
        ];
        $response = $mj->post(Resources::$Email, ['body' => $body]);
        $response->success();
        return true;
    }

    public function getSubscriptionInfo( DatabaseManager $databaseManager ): array|null 
    {
        $user_id = $databaseManager->getCurrentUser();
        $subscription = $databaseManager->getMeta('user', $user_id, 'subscription');
        if( $subscription != null ) {
            $subscription = json_decode( $subscription, true );
            
        } else {
            $subscription = array(
                'expiry_date' => ''
            );
        }

        $subscription['user_id'] = $user_id;

        // Get threads count
        $q = $this->entityManager->createQueryBuilder();
        $threads = $q->select('COUNT(t.id)')
            ->from('App\Entity\Blocks', 't')
            ->innerJoin('App\Entity\Blocks', 'w', 'WITH', 't.parent = w.id')
            ->where('t.type = :thread_type')
            ->andWhere('t.status = 1')
            ->andWhere('w.status = 1')
            ->andWhere('w.type = :workspace_type') 
            ->andWhere('w.author = :user_id')
            ->setParameter('thread_type', 'thread')
            ->setParameter('workspace_type', 'workspace')
            ->setParameter('user_id', $user_id)
            ->getQuery()
            ->getSingleScalarResult();

        $subscription['threads'] = (int)$threads;
        return $subscription;
    }

    public function getSubscriberUserId( DatabaseManager $databaseManager, string $customer_id, string $subscription_id ): array|null 
    {
        $q = $this->entityManager->createQueryBuilder();
        $subscription = $q->select('m.parent_id, m.meta_value')
            ->from('App\Entity\Metas', 'm')
            ->where('m.parent = :parent')
            ->andWhere('m.meta_key = :meta_key')
            ->andWhere('m.meta_value LIKE :customer_id')
            ->andWhere('m.meta_value LIKE :subscription_id')
            ->setParameter('parent', 'user')
            ->setParameter('meta_key', 'subscription')
            ->setParameter('customer_id', '%' . $customer_id . '%')
            ->setParameter('subscription_id', '%' . $subscription_id . '%')
            ->getQuery()
            ->getOneOrNullResult();
        return $subscription['parent_id'] ?? null;
    }

    public function convertVoiceMessage( $audioBase64 ): string|null
    {
        
        $audioBinary = base64_decode($audioBase64);

        $apiKey = $_ENV['ELEVEN_LABS_API_KEY'];
        $client = new Client();

        // Speech-to-Text
        $response = $client->post('https://api.elevenlabs.io/v1/speech-to-text', [
            'headers' => [
                'xi-api-key' => $_ENV['ELEVEN_LABS_API_KEY']
            ],
            'multipart' => [
                [
                    'name'     => 'file',
                    'contents' => $audioBinary,
                    'filename' => 'audio.wav'
                ],
                [
                    'name'     => 'model_id',
                    'contents' => 'scribe_v1'  // required field
                ]
            ]
        ]);
        $sttData = json_decode($response->getBody()->getContents(), true);
        $text = $sttData['text'] ?? 'Transcription feature is not available currently.';
        
        return $text;
    }
}