<?php

namespace AppBundle\Manager\Project;

use AppBundle\Gateway\FeedbackGateway;

use AppBundle\Manager\NotificationManager;
use FOS\UserBundle\Doctrine\UserManager;

use AppBundle\Entity\User;

use AppBundle\Model\Project\Bug;

use AppBundle\Utils\Parser;

class BugManager
{
    /** @var FeedbackGateway **/
    protected $gateway;
    /** @var CommentaryManager **/
    protected $commentaryManager;
    /** @var NotificationManager **/
    protected $notificationManager;
    /** @var UserManager **/
    protected $userManager;
    /** @var Parser **/
    protected $parser;
    
    /**
     * @param FeedbackGateway $gateway
     * @param CommentaryManager $commentaryManager
     * @param NotificationManager $notificationManager
     * @param UserManager $userManager
     * @param Parser $parser
     */
    public function __construct(
        FeedbackGateway $gateway,
        CommentaryManager $commentaryManager,
        NotificationManager $notificationManager,
        UserManager $userManager,
        Parser $parser
    )
    {
        $this->gateway = $gateway;
        $this->commentaryManager = $commentaryManager;
        $this->notificationManager = $notificationManager;
        $this->userManager = $userManager;
        $this->parser = $parser;
    }
    
    /**
     * @param string $title
     * @param string $description
     * @param User $user
     * @return mixed
     */
    public function create($title, $description, User $user)
    {
        return $this->format(json_decode($this->gateway->createBug(
            $title,
            $this->parser->parse($description),
            Bug::STATUS_TO_SPECIFY,
            $user->getUsername(),
            $user->getEmail()
        )->getBody(), true));
    }
    
    /**
     * @param Bug $bug
     * @param User $user
     * @return Response
     */
    public function update(Bug $bug, User $user)
    {
        $updatedBug = $this->format(json_decode($this->gateway->updateBug($bug)->getBody(), true));
        
        $title = 'Bug mis à jour';
        $content = "{$user->getUsername()} a mis à jour le bug \"{$bug->getTitle()}\".";
        // We avoid sending notification to the updater, whether he is the feedback author or not
        $players = [$user->getId()];
        if ($bug->getAuthor()->getId() !== $user->getId() && $bug->getAuthor()->getId() !== 0) {
            $players[] = $bug->getAuthor()->getId();
            $this->notificationManager->add($bug->getAuthor(), $title, $content);
        }
        foreach ($bug->getCommentaries() as $comment) {
            $commentAuthor = $comment->getAuthor();
            
            if (in_array($commentAuthor->getId(), $players) || $commentAuthor->getId() === 0) {
                continue;
            }
            $players[] = $commentAuthor->getId();
            $this->notificationManager->create($commentAuthor, $title, $content);
        }
        return $updatedBug;
    }
    
    /**
     * @return array
     */
    public function getAll()
    {
        $result = json_decode($this->gateway->getBugs()->getBody(), true);
        foreach ($result as &$data) {
            $data = $this->format($data);
        }
        return $result;
    }
    
    /**
     * @param string $id
     * @return Bug
     */
    public function get($id)
    {
        return $this->format(json_decode($this->gateway->getBug($id)->getBody(), true), true);
    }
    
    /**
     * @param array $data
     * @param boolean $getAuthor
     * @return Bug
     */
    protected function format($data, $getAuthor = false)
    {
        $bug =
            (new Bug())
            ->setId($data['id'])
            ->setTitle($data['title'])
            ->setDescription($data['description'])
            ->setStatus($data['status'])
            ->setAuthor($this->getAuthor($data['author']['username'], $getAuthor))
            ->setCreatedAt(new \DateTime($data['created_at']))
            ->setUpdatedAt(new \DateTime($data['updated_at']))
        ;
        if (!empty($data['commentaries'])) {
            foreach ($data['commentaries'] as $commentary) {
                $bug->addCommentary($this->commentaryManager->format($commentary, true));
            }
        }
        return $bug;
    }
    
    protected function getAuthor($name, $getAuthorData = false)
    {
        if ($getAuthorData === false) {
            return $name;
        }
        if (($author = $this->userManager->findUserByUsername($name)) === null) {
            return
                (new User())
                ->setUsername($name)
            ;
        }
        return $author;
    }
}